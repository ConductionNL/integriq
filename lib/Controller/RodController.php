<?php

/**
 * Integriq DUO ROD Controller.
 *
 * REST controller for integriq-adapter-rod: the push endpoint learniq's
 * `bron-rod` DataExchangeJob calls directly over an authenticated NC
 * session to register a ROD bericht — mirrors
 * `IwmoIjwController::createMessage()` — and the signed inbound DUO
 * acknowledgement/retour receiver — mirrors `IwmoIjwController::inbound()`.
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
 * @spec openspec/specs/rod-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\RodProviderException;
use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\RodService;
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
 * Push (register a ROD bericht) + signed inbound DUO retour receiver.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/specs/rod-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) the gate and its webhook-type constant replace the
 * signature service; the HTTP, auth and provider types this controller answers with stay.
 */
class RodController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param RodService $rodService Send/retour orchestration logic.
	 * @param WebhookGate $gate The consumer model: signature, account and refusals of the inbound webhook.
	 * @param IUserSession $userSession The user session (push endpoint).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RodService $rodService,
		private readonly WebhookGate $gate,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Register one outbound ROD bericht.
	 *
	 * Expected JSON body: `{berichtsoort: "inschrijving"|"uitschrijving"|
	 * "verblijfsgegevens"|"schooladvies", kenmerk: "...", payload: {...}}` —
	 * see contract.md for the full field table per berichtsoort.
	 *
	 * @return JSONResponse `{ref, berichtsoort, status}` on success, or a 400/503/502 error envelope.
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function berichten(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'rod.push');

		$params = $this->request->getParams();
		$berichtsoort = (string)($params['berichtsoort'] ?? '');
		$kenmerk = (string)($params['kenmerk'] ?? '');
		$payload = (array)($params['payload'] ?? []);
		if ($berichtsoort === '' || $kenmerk === '') {
			return new JSONResponse(
				[
					'error' => 'missing_fields',
					'message' => $this->l->t('The "berichtsoort" and "kenmerk" fields are required'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->rodService->sendBericht(berichtsoort: $berichtsoort, kenmerk: $kenmerk, payload: $payload);
			return new JSONResponse($result);
		} catch (RodTranslationException $exception) {
			return new JSONResponse(
				['error' => 'invalid_bericht', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (RodProviderException $exception) {
			$this->logger->warning('[RodController] send failed: ' . $exception->getMessage());

			$status = Http::STATUS_BAD_GATEWAY;
			$code = 'rod_send_failed';
			if (str_contains($exception->getMessage(), 'No active ROD source') === true) {
				$status = Http::STATUS_SERVICE_UNAVAILABLE;
				$code = 'not_configured';
			}

			return new JSONResponse(['error' => $code, 'message' => $exception->getMessage()], $status);
		}//end try

	}//end berichten()

	/**
	 * Receive an inbound DUO ROD acknowledgement/retour.
	 *
	 * Gated by the same HMAC scheme as the `webhook_signature` rule
	 * (constant-time compare, timestamp tolerance): an unsigned or tampered
	 * retour is rejected 401 BEFORE any state change. The `#[PublicPage]` /
	 * `#[NoCSRFRequired]` attributes are present because this endpoint
	 * authenticates via webhook signature, not NC session — the signature
	 * check IS the auth body for this route (mirrors `IwmoIjwController::inbound()`).
	 *
	 * The delivery authenticates the `rod-webhook` consumer, and every write runs
	 * as that consumer's account. A missing connection, account or right, and
	 * a write OpenRegister refuses, answer 503 so the partner retries.
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 *
	 * @contract tests/Unit/Controller/XmlWebhooksConsumerTest.php — signed delivery stored as the
	 *           connection's account, 503 without an account, 401 on a wrong signature
	 *           (data provider `webhooks()`, which calls the method by name)
	 *
	 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 * @spec openspec/changes/rod-retour-on-the-consumer-model/specs/rod-adapter/spec.md#requirement-the-retour-acts-as-the-rod-connections-account-req-020
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function retour(): JSONResponse {
		$rawBody = $this->getRawContent();

		$identity = $this->gate->identify(profile: WebhookProfiles::ROD, rawBody: $rawBody, request: $this->request);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		// Signature verification runs over the exact raw bytes; the retour
		// is XML (not JSON), so the body is passed to the service verbatim.
		try {
			$this->gate->deliver(
				identity: $identity,
				operation: function () use ($rawBody): void {
					$this->rodService->receiveReturn(rawXml: $rawBody);
				}
			);
		} catch (Throwable $exception) {
			// The account's write was refused: answer 503 so ROD delivers again.
			return $this->gate->notStored(profile: WebhookProfiles::ROD, reason: $exception->getMessage());
		}//end try

		return new JSONResponse(['received' => true]);
	}//end retour()

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
