<?php

/**
 * Integriq Peppol Controller.
 *
 * REST controller for the peppol-access-point-connector: the participant/SMP
 * lookup endpoint and the signed inbound Access Point (AP) receive webhook.
 * Outbound transmission is NOT triggered from here — it is event-driven (see
 * PeppolOutboundConsumer) per design.md.
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
 * @spec openspec/specs/peppol-access-point-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\PeppolProviderException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\PeppolTransmissionServiceInterface;
use OCA\Integriq\Service\Dso\DsoIdentity;
use OCA\Integriq\Service\Intake\WebhookGateInterface;
use OCA\Integriq\Service\Intake\WebhookProfilesInterface;
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
 * Peppol participant lookup + signed inbound receive webhook.
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md
 * @spec openspec/changes/peppol-inbound-on-the-consumer-model/specs/peppol-access-point-connector/spec.md#requirement-the-inbound-webhook-acts-as-the-peppol-connections-account-req-020
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.ElseExpression)
 */
class PeppolController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param PeppolTransmissionServiceInterface $transmissionService Lookup + transmission/callback logic.
	 * @param WebhookGateInterface $gate The consumer model: signature, account and refusals of the inbound webhook.
	 * @param IUserSession $userSession The user session (participants endpoint).
	 * @param ActionAuthServiceInterface $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PeppolTransmissionServiceInterface $transmissionService,
		private readonly WebhookGateInterface $gate,
		private readonly IUserSession $userSession,
		private readonly ActionAuthServiceInterface $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Resolve a Peppol participant/SMP lookup.
	 *
	 * @param string $peppolId The participant identifier (`scheme:identifier`).
	 *
	 * @return JSONResponse `{exists, supportedDocTypes}`, or a 400/502 error envelope.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-peppol-participant-smp-lookup-endpoint-req-001
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function participants(string $peppolId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'peppol.lookup');

		if ($this->transmissionService->isValidPeppolId(peppolId: $peppolId) === false) {
			return new JSONResponse(
				['error' => 'invalid_peppol_id', 'message' => $this->l->t('Invalid Peppol identifier')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->lookupParticipant(peppolId: $peppolId);

	}//end participants()

	/**
	 * Look a valid participant up at the access point and answer the result.
	 *
	 * @param string $peppolId The participant identifier (`scheme:identifier`).
	 *
	 * @return JSONResponse `{exists, supportedDocTypes}`, or the 502 error envelope.
	 */
	private function lookupParticipant(string $peppolId): JSONResponse {
		try {
			$result = $this->transmissionService->lookupParticipant(peppolId: $peppolId);
			if ($result['exists'] === false) {
				// Not an error (REQ-001: exists=false is a valid 200 response) —
				// the translated string is used for operator-facing logging only,
				// so the response shape stays exactly {exists, supportedDocTypes}.
				$this->logger->info($this->l->t('Peppol participant not found'), ['peppolId' => $peppolId]);
			}

			return new JSONResponse($result);
		} catch (PeppolProviderException $exception) {
			return $this->lookupFailure(exception: $exception, peppolId: $peppolId);
		}

	}//end lookupParticipant()

	/**
	 * Log a failed lookup and answer it.
	 *
	 * @param PeppolProviderException $exception The access point's failure.
	 * @param string                  $peppolId  The participant identifier looked up.
	 *
	 * @return JSONResponse The 502 error envelope.
	 */
	private function lookupFailure(PeppolProviderException $exception, string $peppolId): JSONResponse {
		$this->logger->warning(
			'[PeppolController] participant lookup failed: ' . $exception->getMessage(),
			['peppolId' => $peppolId]
		);
		return new JSONResponse(
			['error' => 'peppol_unavailable', 'message' => $exception->getMessage()],
			Http::STATUS_BAD_GATEWAY
		);

	}//end lookupFailure()

	/**
	 * Receive an Access Point delivery callback or inbound-document notification.
	 *
	 * Gated by the same HMAC scheme as the `webhook_signature` rule
	 * (constant-time compare, timestamp tolerance): an unsigned or tampered
	 * callback is rejected 401 BEFORE any state change or event emission. The
	 * `#[PublicPage]` / `#[NoCSRFRequired]` attributes are present because
	 * this endpoint authenticates via webhook signature, not NC session — the
	 * signature check IS the auth body for this route (mirrors DSOController).
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure, 503 when not stored.
	 *
	 * The callback authenticates the `peppol-webhook` consumer, and every write
	 * runs as that consumer's account. A missing connection, account or right,
	 * and a write OpenRegister refuses, answer 503 so the access point retries.
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-inbound-receive-webhook-that-republishes-ap-callbacks-as-events-req-005
	 * @spec openspec/changes/peppol-inbound-on-the-consumer-model/specs/peppol-access-point-connector/spec.md#requirement-the-inbound-webhook-acts-as-the-peppol-connections-account-req-020
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbound(): JSONResponse {
		$rawBody = $this->getRawContent();

		$identity = $this->gate->identify(profile: WebhookProfilesInterface::PEPPOL, rawBody: $rawBody, request: $this->request);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		// Payload parsing happens from $this->request->getParams() (NC decodes a
		// JSON body into params) — NOT a second json_decode($rawBody) — mirroring
		// DSOController: signature verification runs over the exact raw bytes,
		// while payload access goes through the framework's normalised params.
		return $this->deliverCallback(identity: $identity, body: $this->request->getParams());
	}//end inbound()

	/**
	 * Record a signed callback as the connection's account.
	 *
	 * @param DsoIdentity         $identity The consumer the callback authenticated as.
	 * @param array<string,mixed> $body     The callback's params.
	 *
	 * @return JSONResponse `{received: true}`, or 503 when the account's write was refused.
	 */
	private function deliverCallback(DsoIdentity $identity, array $body): JSONResponse {
		try {
			$this->gate->deliver(
				identity: $identity,
				operation: function () use ($body): void {
					$this->recordCallback(body: $body);
				}
			);
		} catch (Throwable $exception) {
			// The account's write was refused: answer 503 so the access point delivers again.
			return $this->gate->notStored(profile: WebhookProfilesInterface::PEPPOL, reason: $exception->getMessage());
		}

		return new JSONResponse(['received' => true]);

	}//end deliverCallback()

	/**
	 * Hand a callback to the transmission service by its shape: a delivery
	 * callback (it names a transmission), else an inbound document (it names a
	 * sender), else log the payload's keys.
	 *
	 * @param array<string,mixed> $body The callback's params.
	 *
	 * @return void
	 */
	private function recordCallback(array $body): void {
		if (isset($body['transmissionId']) === true) {
			$this->recordDeliveryCallback(body: $body);
			return;
		}

		if (isset($body['senderPeppolId']) === true) {
			$this->recordInboundDocument(body: $body);
			return;
		}

		$this->logger->warning('[PeppolController] inbound webhook payload matched neither known shape', ['keys' => array_keys($body)]);

	}//end recordCallback()

	/**
	 * Hand a delivery callback to the transmission service.
	 *
	 * @param array<string,mixed> $body The callback's params; it names a transmission.
	 *
	 * @return void
	 */
	private function recordDeliveryCallback(array $body): void {
		$this->transmissionService->handleDeliveryCallback(
			transmissionId: (string)$body['transmissionId'],
			status: (string)($body['status'] ?? ''),
			detail: $this->detailOf(body: $body)
		);

	}//end recordDeliveryCallback()

	/**
	 * Hand an inbound-document notification to the transmission service.
	 *
	 * @param array<string,mixed> $body The callback's params; it names a sender.
	 *
	 * @return void
	 */
	private function recordInboundDocument(array $body): void {
		$this->transmissionService->handleInboundDocument(
			senderPeppolId: (string)$body['senderPeppolId'],
			documentType: (string)($body['documentType'] ?? ''),
			payloadReference: (string)($body['payloadReference'] ?? '')
		);

	}//end recordInboundDocument()

	/**
	 * A delivery callback's detail as a string, or null when it carries none.
	 *
	 * @param array<string,mixed> $body The callback's params.
	 *
	 * @return string|null The detail, or null.
	 */
	private function detailOf(array $body): string|null {
		if (isset($body['detail']) === true) {
			return (string)$body['detail'];
		}

		return null;

	}//end detailOf()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * Signature verification MUST run over the exact bytes the AP signed, not
	 * `$this->request->getParams()` (decoded/normalized, would desync).
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
