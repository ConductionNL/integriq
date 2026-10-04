<?php

/**
 * Integriq Open Formulieren Controller.
 *
 * REST controller for the open-formulieren-intake bridge: the signed
 * inbound submission webhook (gated by HMAC against the `open-formulieren`
 * consumer, and run as that consumer's account, like the DSO STAM intake), a
 * status-read endpoint, and the authenticated handoff-trigger endpoint that
 * executes the declared `ns#Case` handoff under the calling user's own
 * session/RBAC (see design.md §1.1 for why this is a separate, authenticated
 * step rather than automatic at webhook-receipt time).
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/open-formulieren-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Exception\DsoSignatureException;
use OCA\Integriq\Exception\OpenFormulierenException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Service\OpenFormulierenIntakeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HandoffException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Signed inbound submission webhook + status read + authenticated handoff trigger.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the handoff-trigger error mapping
 * (REQ-004) legitimately switches on OpenFormulierenException/HandoffException/
 * NotAuthorizedException/Throwable in addition to the controller's normal HTTP/auth
 * collaborators (mirrors PeppolController's error-mapping breadth).
 *
 * @spec openspec/specs/open-formulieren-intake/spec.md
 */
class OpenFormulierenController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param OpenFormulierenIntakeService $intakeService Ingest / mapping / handoff orchestration.
	 * @param OpenFormulierenConnection $connection The consumer that authenticates the webhook and its account.
	 * @param DsoConnectionAlerts $alerts Admin alerts when a submission is refused (shared with DSO).
	 * @param IUserSession $userSession The user session (status/handoff endpoints).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OpenFormulierenIntakeService $intakeService,
		private readonly OpenFormulierenConnection $connection,
		private readonly DsoConnectionAlerts $alerts,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Receive a signed Open Formulieren submission.
	 *
	 * Open Formulieren is an integriq consumer (`authorizationType:
	 * open-formulieren`). The HMAC over the exact raw body authenticates it
	 * against that consumer's trust, and the consumer's account (`userId`) is
	 * who every write runs as, inside OpenRegister's `runAs()`. A bad signature
	 * answers 401 before any state change. A missing connection, account or
	 * right answers 503 before anything is written, so Open Formulieren
	 * delivers again. A write OpenRegister refuses anyway answers 503 too.
	 * Expected JSON body: see design.md §5 of open-formulieren-intake.
	 *
	 * @return JSONResponse The persisted `openformulieren_submission` record, or a 400/401/503 error envelope.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-signed-inbound-submission-webhook-req-001
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/specs/open-formulieren-intake/spec.md#requirement-the-intake-acts-as-the-open-formulieren-connections-account-req-006
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbound(): JSONResponse {
		$rawBody = $this->getRawContent();

		$identity = $this->identify(rawBody: $rawBody);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		// Payload access goes through the framework's normalised params (NC decodes
		// a JSON body into params), NOT a second json_decode($rawBody): signature
		// verification already ran over the exact raw bytes above.
		$body = $this->request->getParams();

		$formSlug = (string)($body['form']['slug'] ?? '');
		if ($formSlug === '') {
			return new JSONResponse(
				['error' => 'missing_form_slug', 'message' => $this->l->t('The "form.slug" field is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$formUuid = ($body['form']['uuid'] ?? null);
		$formUuidValue = null;
		if ($formUuid !== null) {
			$formUuidValue = (string)$formUuid;
		}

		$authContext = ($body['auth'] ?? null);
		if (is_array($authContext) === false) {
			$authContext = null;
		}

		$submissionMeta = (array)($body['submission'] ?? []);

		try {
			$submission = $this->connection->runAs(
				$identity->account,
				fn (): mixed => $this->intakeService->ingest(
					formSlug: $formSlug,
					formUuid: $formUuidValue,
					submissionMeta: $submissionMeta,
					values: (array)($body['values'] ?? []),
					attachmentRefs: (array)($body['attachments'] ?? []),
					authContext: $authContext,
					identity: $identity
				)
			);
		} catch (Throwable $exception) {
			return $this->notStored(submissionMeta: $submissionMeta, reason: $exception->getMessage());
		}

		if ($submission instanceof ObjectEntity === false || (string)$submission->getUuid() === '') {
			return $this->notStored(submissionMeta: $submissionMeta, reason: 'ingest returned an object without a uuid');
		}

		return new JSONResponse($submission->getObject() + ['id' => $submission->getUuid()]);
	}//end inbound()

	/**
	 * Authenticate the submission against the Open Formulieren connection.
	 *
	 * @param string $rawBody The exact raw request body.
	 *
	 * @return DsoIdentity|JSONResponse The identity, or the 401/503 answer.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	private function identify(string $rawBody): DsoIdentity|JSONResponse {
		try {
			return $this->connection->authenticate(
				rawBody: $rawBody,
				headerOf: fn (string $name): string => (string)$this->request->getHeader($name)
			);
		} catch (DsoSignatureException) {
			// Undifferentiated error body: never leak which check failed.
			$this->logger->warning('[OpenFormulierenController] webhook signature validation failed');
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		} catch (DsoConnectionUnavailableException $exception) {
			$this->logger->error(
				'[OpenFormulierenController] submission refused, the Open Formulieren connection is not usable; answering 503 so the sender retries',
				['reason' => $exception->getReason(), 'detail' => $exception->getMessage()]
			);
			$this->alerts->notify(reason: $exception->getReason(), channel: DsoConnectionUnavailableException::CHANNEL_OPEN_FORMULIEREN);

			return new JSONResponse(
				[
					'error' => $exception->getErrorCode(),
					'message' => $this->l->t('The submission could not be stored. Try again later.'),
				],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}//end try

	}//end identify()

	/**
	 * Answer 503 for a submission that was not stored, log it and alert the admins.
	 *
	 * @param array<string, mixed> $submissionMeta The payload's `submission` block.
	 * @param string               $reason         Why it was not stored (secret-free).
	 *
	 * @return JSONResponse The 503 answer.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-2
	 */
	private function notStored(array $submissionMeta, string $reason): JSONResponse {
		$this->logger->error(
			'[OpenFormulierenController] submission not stored, answering 503 so the sender retries',
			['submissionUuid' => (string)($submissionMeta['uuid'] ?? ''), 'exception' => $reason]
		);
		$this->alerts->notify(
			reason: DsoConnectionAlerts::REASON_SUBMISSION_NOT_STORED,
			channel: DsoConnectionUnavailableException::CHANNEL_OPEN_FORMULIEREN
		);

		return new JSONResponse(
			[
				'error' => DsoConnectionAlerts::REASON_SUBMISSION_NOT_STORED,
				'message' => $this->l->t('The submission could not be stored. Try again later.'),
			],
			Http::STATUS_SERVICE_UNAVAILABLE
		);

	}//end notStored()

	/**
	 * Read one submission's current status.
	 *
	 * @param string $id The `openformulieren_submission` uuid.
	 *
	 * @return JSONResponse The submission record, or a 401/404 error envelope.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-openformulieren-submission-lifecycle-with-per-submission-isolation-req-003
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function status(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'open-formulieren.status');

		if ($id === '') {
			return new JSONResponse(
				['error' => 'missing_id', 'message' => $this->l->t('The submission id is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->intakeService->getSubmission(submissionUuid: $id);
		} catch (OpenFormulierenException $exception) {
			return new JSONResponse(
				['error' => 'submission_not_found', 'message' => $exception->getMessage()],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($result);
	}//end status()

	/**
	 * Trigger the declared `submission-to-case` handoff for a `mapped`
	 * submission, as the authenticated caller — never a system-account
	 * shortcut (design.md §1.1).
	 *
	 * @param string $id The `openformulieren_submission` uuid.
	 *
	 * @return JSONResponse The engine's execute() result, or a 400/401/403/404/409 error envelope.
	 *
	 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-declared-ns-case-handoff-executed-by-a-real-authenticated-actor-req-004
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function handoff(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'open-formulieren.handoff');

		if ($id === '') {
			return new JSONResponse(
				['error' => 'missing_id', 'message' => $this->l->t('The submission id is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->intakeService->handoff(submissionUuid: $id);
			return new JSONResponse($result);
		} catch (OpenFormulierenException $exception) {
			return new JSONResponse(
				['error' => 'submission_not_ready', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (HandoffException $exception) {
			$status = Http::STATUS_CONFLICT;
			if ($exception->getErrorCode() === HandoffException::NOT_DECLARED) {
				$status = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse(
				['error' => $exception->getErrorCode(), 'message' => $exception->getMessage()],
				$status
			);
		} catch (NotAuthorizedException $exception) {
			return new JSONResponse(
				['error' => 'handoff_not_authorized', 'message' => $exception->getMessage()],
				Http::STATUS_FORBIDDEN
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[OpenFormulierenController] handoff failed unexpectedly: ' . $exception->getMessage(),
				['exception' => $exception]
			);
			return new JSONResponse(
				['error' => 'handoff_failed', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_GATEWAY
			);
		}//end try

	}//end handoff()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * @return string The raw request body.
	 */
	private function getRawContent(): string {
		$content = file_get_contents(filename: 'php://input');
		if ($content === false) {
			return '';
		}

		return $content;
	}//end getRawContent()
}//end class
