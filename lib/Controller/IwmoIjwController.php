<?php

/**
 * Integriq iWMO/iJW Controller.
 *
 * REST controller for the iwmo-ijw-adapter: the push endpoint sibling apps
 * (e.g. procest's social-domain case module) call directly over an
 * authenticated NC session to register a toewijzing/declaratie — mirrors
 * `KissController::createCustomerContact()` — and the signed inbound retour
 * receiver — mirrors `PeppolController::inbound()`.
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
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\IwmoIjwProviderException;
use OCA\Integriq\Exception\IwmoIjwTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\IwmoIjwSyncService;
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
 * Push (register toewijzing/declaratie) + signed inbound retour receiver for iWMO/iJW.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/specs/iwmo-ijw-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) the gate and its webhook-type constant replace the
 * signature service; the HTTP, auth and provider types this controller answers with stay.
 */
class IwmoIjwController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param IwmoIjwSyncService $syncService Send/retour orchestration logic.
	 * @param WebhookGate $gate The consumer model: signature, account and refusals of the inbound webhook.
	 * @param IUserSession $userSession The user session (push endpoint).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IwmoIjwSyncService $syncService,
		private readonly WebhookGate $gate,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Register one toewijzing or declaratie bericht.
	 *
	 * Expected JSON body: `{kind: "toewijzing"|"declaratie", domain:
	 * "wmo"|"jw", ...berichttype fields (see design.md's outbound field
	 * table), caseReference, caseRegister, caseSchema}`.
	 *
	 * @return JSONResponse `{ref, berichttype}` on success, or a 400/503/502 error envelope.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-push-endpoint-and-signed-inbound-retour-receiver-req-004
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createMessage(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'iwmo-ijw.push');

		$params = $this->request->getParams();
		$kind = (string)($params['kind'] ?? '');
		$domain = (string)($params['domain'] ?? '');
		if ($kind === '' || $domain === '') {
			return new JSONResponse(
				[
					'error' => 'missing_fields',
					'message' => $this->l->t('The "kind" and "domain" fields are required'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->syncService->sendMessage(input: $params);
			return new JSONResponse($result);
		} catch (IwmoIjwTranslationException $exception) {
			return new JSONResponse(
				['error' => 'invalid_bericht', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (IwmoIjwProviderException $exception) {
			$this->logger->warning('[IwmoIjwController] send failed: ' . $exception->getMessage());

			$status = Http::STATUS_BAD_GATEWAY;
			$code = 'iwmo_ijw_send_failed';
			if (str_contains($exception->getMessage(), 'No active iWMO/iJW source') === true) {
				$status = Http::STATUS_SERVICE_UNAVAILABLE;
				$code = 'not_configured';
			}

			return new JSONResponse(['error' => $code, 'message' => $exception->getMessage()], $status);
		}//end try

	}//end createMessage()

	/**
	 * Receive an inbound iWMO/iJW retour message.
	 *
	 * Gated by the same HMAC scheme as the `webhook_signature` rule
	 * (constant-time compare, timestamp tolerance): an unsigned or tampered
	 * retour is rejected 401 BEFORE any state change. The `#[PublicPage]` /
	 * `#[NoCSRFRequired]` attributes are present because this endpoint
	 * authenticates via webhook signature, not NC session — the signature
	 * check IS the auth body for this route (mirrors `PeppolController::inbound()`).
	 *
	 * RATE-LIMIT RATIONALE (ADR-082): iWmo/iJw receiver — same posture as every
	 * standards receiver here.
	 *
	 * The delivery authenticates the `iwmo-ijw-webhook` consumer, and every write runs
	 * as that consumer's account. A missing connection, account or right, and
	 * a write OpenRegister refuses, answer 503 so the partner retries.
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 *
	 * @spec openspec/specs/iwmo-ijw-adapter/spec.md#requirement-push-endpoint-and-signed-inbound-retour-receiver-req-004
	 * @spec openspec/changes/iwmo-ijw-retour-on-the-consumer-model/specs/iwmo-ijw-adapter/spec.md#requirement-the-retour-acts-as-the-iwmo-and-ijw-connections-account-req-020
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inbound(): JSONResponse {
		$rawBody = $this->getRawContent();

		$identity = $this->gate->identify(profile: WebhookProfiles::IWMO_IJW, rawBody: $rawBody, request: $this->request);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		// Signature verification runs over the exact raw bytes; the retour
		// is XML (not JSON), so the body is passed to the sync service
		// verbatim — never a second decode pass.
		try {
			$this->gate->deliver(
				identity: $identity,
				operation: function () use ($rawBody): void {
					$this->syncService->receiveReturn(rawXml: $rawBody);
				}
			);
		} catch (Throwable $exception) {
			// The account's write was refused: answer 503 so the iWMO/iJW partner delivers again.
			return $this->gate->notStored(profile: WebhookProfiles::IWMO_IJW, reason: $exception->getMessage());
		}//end try

		return new JSONResponse(['received' => true]);
	}//end inbound()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * Signature verification MUST run over the exact bytes the sender
	 * signed, not `$this->request->getParams()` (decoded/normalized, would
	 * desync) — mirrors `PeppolController::getRawContent()`.
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
