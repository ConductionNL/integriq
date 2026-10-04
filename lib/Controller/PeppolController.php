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
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\PeppolTransmissionService;
use OCA\Integriq\Service\Intake\WebhookGate;
use OCA\Integriq\Service\Intake\WebhookProfiles;
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
	 * @param PeppolTransmissionService $transmissionService Lookup + transmission/callback logic.
	 * @param WebhookGate $gate The consumer model: signature, account and refusals of the inbound webhook.
	 * @param IUserSession $userSession The user session (participants endpoint).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PeppolTransmissionService $transmissionService,
		private readonly WebhookGate $gate,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
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
			$this->logger->warning(
				'[PeppolController] participant lookup failed: ' . $exception->getMessage(),
				['peppolId' => $peppolId]
			);
			return new JSONResponse(
				['error' => 'peppol_unavailable', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_GATEWAY
			);
		}//end try

	}//end participants()

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

		$identity = $this->gate->identify(profile: WebhookProfiles::PEPPOL, rawBody: $rawBody, request: $this->request);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		// Payload parsing happens from $this->request->getParams() (NC decodes a
		// JSON body into params) — NOT a second json_decode($rawBody) — mirroring
		// DSOController: signature verification runs over the exact raw bytes,
		// while payload access goes through the framework's normalised params.
		$body = $this->request->getParams();

		try {
			$this->gate->deliver(
				identity: $identity,
				operation: function () use ($body): void {
					if (isset($body['transmissionId']) === true) {
						$detail = null;
						if (isset($body['detail']) === true) {
							$detail = (string)$body['detail'];
						}

						$this->transmissionService->handleDeliveryCallback(
							transmissionId: (string)$body['transmissionId'],
							status: (string)($body['status'] ?? ''),
							detail: $detail
						);
					} elseif (isset($body['senderPeppolId']) === true) {
						$this->transmissionService->handleInboundDocument(
							senderPeppolId: (string)$body['senderPeppolId'],
							documentType: (string)($body['documentType'] ?? ''),
							payloadReference: (string)($body['payloadReference'] ?? '')
						);
					} else {
						$this->logger->warning('[PeppolController] inbound webhook payload matched neither known shape', ['keys' => array_keys($body)]);
					}
				}
			);
		} catch (Throwable $exception) {
			// The account's write was refused: answer 503 so the access point delivers again.
			return $this->gate->notStored(profile: WebhookProfiles::PEPPOL, reason: $exception->getMessage());
		}//end try

		return new JSONResponse(['received' => true]);
	}//end inbound()

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
