<?php

/**
 * Integriq VerdictController.
 *
 * Takes a verdict from an external checker and hands it back to the app that
 * owns the object. The inbound leg is public and gated by a webhook signature
 * verified over the raw bytes before the body is read, like every other
 * inbound endpoint here.
 *
 * Integriq stores the verdict and does nothing else with it: the object it
 * judges is never changed, and what the verdict means is the owning app's
 * decision.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Outbound\Call\VerdictServiceInterface;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\Integriq\Service\Intake\WebhookProfilesInterface;
use OCA\OpenRegister\Db\ObjectEntity;
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
use Throwable;

/**
 * Inbound verdicts, and reading them back.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-external-verdict-is-recorded-against-the-record-it-judges-req-ocd-006
 */
class VerdictController extends Controller {

	/**
	 * The channel id the verdict endpoint's signing secret is configured under.
	 *
	 * @var string
	 */
	public const CHANNEL_ID = 'verdicts';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Names the principal reading verdicts back.
	 * @param VerdictServiceInterface $verdicts Stores and reads the verdicts.
	 * @param WebhookGateInterface $gate The consumer model: signature, account and refusals of the inbound webhook.
	 * @param IL10N $l Translations.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly VerdictServiceInterface $verdicts,
		private readonly WebhookGateInterface $gate,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Take a verdict from an external checker.
	 *
	 * @return JSONResponse Whether it was stored.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbound(): JSONResponse {
		$rawBody = $this->getRawContent();
		$identity = $this->gate->identify(
			profile: WebhookProfilesInterface::INTAKE_CHANNEL_PREFIX . self::CHANNEL_ID,
			rawBody: $rawBody,
			request: $this->request
		);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		return $this->recordVerdict(identity: $identity, body: $this->request->getParams());

	}//end inbound()

	/**
	 * Record a signed verdict as the connection's account and answer it.
	 *
	 * @param DsoIdentity         $identity The consumer the delivery authenticated as.
	 * @param array<string,mixed> $body     The delivery's params.
	 *
	 * @return JSONResponse 201 with the verdict's id, 400 for a verdict the service refuses,
	 *                      or 503 when the account's write was refused.
	 */
	private function recordVerdict(DsoIdentity $identity, array $body): JSONResponse {
		try {
			$verdict = $this->gate->deliver(
				identity: $identity,
				operation: fn (): ObjectEntity => $this->record(body: $body)
			);
		} catch (Throwable $exception) {
			return $this->notRecorded(exception: $exception);
		}

		return new JSONResponse(
			['id' => (string)$verdict->getUuid(), 'stored' => true],
			Http::STATUS_CREATED
		);

	}//end recordVerdict()

	/**
	 * Hand a delivery's fields to the verdict service.
	 *
	 * @param array<string,mixed> $body The delivery's params.
	 *
	 * @return ObjectEntity The recorded verdict.
	 */
	private function record(array $body): ObjectEntity {
		$verdictPayload = ($body['payload'] ?? null);
		if (is_array($verdictPayload) === false) {
			$verdictPayload = [];
		}

		return $this->verdicts->record(
			(string)($body['objectRef'] ?? ''),
			(string)($body['state'] ?? ''),
			(string)($body['source'] ?? ''),
			(string)($body['reason'] ?? ''),
			$verdictPayload,
		);

	}//end record()

	/**
	 * Answer a verdict that was not recorded.
	 *
	 * @param Throwable $exception Why it was not recorded.
	 *
	 * @return JSONResponse 400 for a verdict the service refuses; else, the account's
	 *                      write was refused: 503 so the checker delivers again.
	 */
	private function notRecorded(Throwable $exception): JSONResponse {
		if ($exception instanceof InvalidArgumentException) {
			return new JSONResponse(['error' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return $this->gate->notStored(
			profile: WebhookProfilesInterface::INTAKE_CHANNEL_PREFIX . self::CHANNEL_ID,
			reason: $exception->getMessage()
		);

	}//end notRecorded()

	/**
	 * The verdicts recorded against one object.
	 *
	 * @return JSONResponse The verdicts, newest first.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @no-admin-idor-exempt Reads through VerdictService::forObject(), which lists verdicts with
	 *   OpenRegister's ObjectService::findAll() under the default RBAC and multitenancy, so the
	 *   caller only sees verdicts they may read. The controller takes the interface, which this
	 *   checker cannot follow to the class.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$objectRef = (string)$this->request->getParam('objectRef', '');
		if (trim($objectRef) === '') {
			return new JSONResponse(
				['error' => $this->l->t('Name the object whose verdicts you want.')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(['verdicts' => $this->verdicts->forObject($objectRef)]);

	}//end index()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * @return string The raw request body.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md
	 */
	protected function getRawContent(): string {
		$content = file_get_contents(filename: 'php://input');
		if ($content === false) {
			return '';
		}

		return $content;

	}//end getRawContent()

}//end class
