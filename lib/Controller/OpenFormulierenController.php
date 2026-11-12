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
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Portal\PortalConnectionAlertsInterface;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Service\OpenFormulierenIntakeServiceInterface;
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
	 * @param OpenFormulierenIntakeServiceInterface $intakeService Ingest / mapping / handoff orchestration.
	 * @param OpenFormulierenConnection $connection The consumer that authenticates the webhook and its account.
	 * @param PortalConnectionAlertsInterface $alerts Admin alerts when a submission is refused (shared with DSO).
	 * @param IUserSession $userSession The user session (status/handoff endpoints).
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OpenFormulierenIntakeServiceInterface $intakeService,
		private readonly OpenFormulierenConnection $connection,
		private readonly PortalConnectionAlertsInterface $alerts,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
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
		$identity = $this->identify(rawBody: $this->getRawContent());
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		// Payload access goes through the framework's normalised params (NC decodes
		// a JSON body into params), NOT a second json_decode($rawBody): signature
		// verification already ran over the exact raw bytes above.
		$body = $this->request->getParams();
		if ((string)($body['form']['slug'] ?? '') === '') {
			return new JSONResponse(
				['error' => 'missing_form_slug', 'message' => $this->l->t('The "form.slug" field is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->storeSubmission(identity: $identity, body: $body);
	}//end inbound()

	/**
	 * Store the submission as the connection's account and answer the stored
	 * record, or 503 when it was not stored.
	 *
	 * @param DsoIdentity          $identity The authenticated connection.
	 * @param array<string, mixed> $body     The submission payload.
	 *
	 * @return JSONResponse The record with its id, or the 503 answer.
	 */
	private function storeSubmission(DsoIdentity $identity, array $body): JSONResponse {
		$submissionMeta = (array)($body['submission'] ?? []);
		try {
			$submission = $this->ingestAs(identity: $identity, body: $body);
		} catch (Throwable $exception) {
			return $this->notStored(submissionMeta: $submissionMeta, reason: $exception->getMessage());
		}

		if ($submission instanceof ObjectEntity === false || (string)$submission->getUuid() === '') {
			return $this->notStored(submissionMeta: $submissionMeta, reason: 'ingest returned an object without a uuid');
		}

		return new JSONResponse($submission->getObject() + ['id' => $submission->getUuid()]);
	}//end storeSubmission()

	/**
	 * Ingest the submission inside OpenRegister's `runAs()` for the
	 * connection's account.
	 *
	 * @param DsoIdentity          $identity The authenticated connection.
	 * @param array<string, mixed> $body     The submission payload.
	 *
	 * @return mixed What the intake service stored.
	 */
	private function ingestAs(DsoIdentity $identity, array $body): mixed {
		return $this->connection->runAs(
			$identity->account,
			fn (): mixed => $this->intakeService->ingest(
				formSlug: (string)($body['form']['slug'] ?? ''),
				formUuid: $this->formUuidOf(body: $body),
				submissionMeta: (array)($body['submission'] ?? []),
				values: (array)($body['values'] ?? []),
				attachmentRefs: (array)($body['attachments'] ?? []),
				authContext: $this->authContextOf(body: $body),
				identity: $identity
			)
		);
	}//end ingestAs()

	/**
	 * The form uuid as a string, or null when the payload has none.
	 *
	 * @param array<string, mixed> $body The submission payload.
	 *
	 * @return string|null
	 */
	private function formUuidOf(array $body): ?string {
		$formUuid = ($body['form']['uuid'] ?? null);
		if ($formUuid === null) {
			return null;
		}

		return (string)$formUuid;
	}//end formUuidOf()

	/**
	 * The payload's `auth` block, or null when it is not an object.
	 *
	 * @param array<string, mixed> $body The submission payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function authContextOf(array $body): ?array {
		$authContext = ($body['auth'] ?? null);
		if (is_array($authContext) === false) {
			return null;
		}

		return $authContext;
	}//end authContextOf()

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
			reason: PortalConnectionAlertsInterface::REASON_SUBMISSION_NOT_STORED,
			channel: DsoConnectionUnavailableException::CHANNEL_OPEN_FORMULIEREN
		);

		return new JSONResponse(
			[
				'error' => PortalConnectionAlertsInterface::REASON_SUBMISSION_NOT_STORED,
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
		$refusal = $this->callerRefusal(action: 'open-formulieren.status', id: $id);
		if ($refusal !== null) {
			return $refusal;
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
		$refusal = $this->callerRefusal(action: 'open-formulieren.handoff', id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$result = $this->intakeService->handoff(submissionUuid: $id);
			return new JSONResponse($result);
		} catch (Throwable $exception) {
			return $this->handoffFailure(exception: $exception);
		}

	}//end handoff()

	/**
	 * The refusal of a caller's request: 401 without a session user, the
	 * action check (which throws when refused), then 400 without an id.
	 *
	 * @param string $action The action-matrix action the request needs.
	 * @param string $id     The submission uuid.
	 *
	 * @return JSONResponse|null The refusal, or null when the request may proceed.
	 */
	private function callerRefusal(string $action, string $id): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: $action);
		if ($id === '') {
			return new JSONResponse(
				['error' => 'missing_id', 'message' => $this->l->t('The submission id is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return null;
	}//end callerRefusal()

	/**
	 * The answer to a failed handoff: its error code and the exception's message.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse
	 */
	private function handoffFailure(Throwable $exception): JSONResponse {
		[$error, $status] = $this->handoffError(exception: $exception);

		return new JSONResponse(
			['error' => $error, 'message' => $exception->getMessage()],
			$status
		);
	}//end handoffFailure()

	/**
	 * The error code and status a failed handoff answers with: a submission
	 * not ready 400, a handoff the engine refused 404 or 409, an RBAC refusal
	 * 403, and anything else 502 (logged).
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return array{0: string, 1: int} The error code and the HTTP status.
	 */
	private function handoffError(Throwable $exception): array {
		if ($exception instanceof OpenFormulierenException) {
			return ['submission_not_ready', Http::STATUS_BAD_REQUEST];
		}

		if ($exception instanceof HandoffException) {
			return [$exception->getErrorCode(), $this->handoffStatus(exception: $exception)];
		}

		if ($exception instanceof NotAuthorizedException) {
			return ['handoff_not_authorized', Http::STATUS_FORBIDDEN];
		}

		return $this->unexpectedHandoffError(exception: $exception);
	}//end handoffError()

	/**
	 * The status of a handoff the engine refused: 404 when the handoff is not
	 * declared, 409 otherwise.
	 *
	 * @param HandoffException $exception The refusal.
	 *
	 * @return int
	 */
	private function handoffStatus(HandoffException $exception): int {
		if ($exception->getErrorCode() === HandoffException::NOT_DECLARED) {
			return Http::STATUS_NOT_FOUND;
		}

		return Http::STATUS_CONFLICT;
	}//end handoffStatus()

	/**
	 * Log an unexpected handoff failure and answer it as a bad gateway.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return array{0: string, 1: int} The error code and the HTTP status.
	 */
	private function unexpectedHandoffError(Throwable $exception): array {
		$this->logger->error(
			'[OpenFormulierenController] handoff failed unexpectedly: ' . $exception->getMessage(),
			['exception' => $exception]
		);

		return ['handoff_failed', Http::STATUS_BAD_GATEWAY];
	}//end unexpectedHandoffError()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * @return string The raw request body.
	 */
	private function getRawContent(): string {
		return (new RawRequestBody())->read();
	}//end getRawContent()
}//end class
