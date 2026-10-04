<?php

/**
 * Integriq OSO Controller.
 *
 * REST controller for integriq-adapter-oso: the export push endpoint
 * learniq's `oso` DataExchangeJob calls directly over an authenticated NC
 * session — mirrors `RodController::berichten()` — plus the signed inbound
 * import receiver (Kennisnet delivering an overstapdossier) and the signed
 * export acknowledgement/retour receiver.
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
 * @spec openspec/specs/oso-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\OsoService;
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
 * Push (register an OSO export) + signed inbound import receiver + signed export retour receiver.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/specs/oso-adapter/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) the gate and its webhook-type constant replace the
 * signature service; the HTTP, auth and provider types this controller answers with stay.
 */
class OsoController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param OsoService $osoService Export/import/retour orchestration logic.
	 * @param WebhookGate $gate The consumer model: signature, account and refusals of the inbound webhook.
	 * @param IUserSession $userSession The user session (push endpoint).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OsoService $osoService,
		private readonly WebhookGate $gate,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Register one outbound OSO export.
	 *
	 * @return JSONResponse `{ref, direction, status}` on success, or a 400/503/502 error envelope.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function export(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'oso.push');

		$params = $this->request->getParams();
		$kenmerk = (string)($params['kenmerk'] ?? '');
		$payload = (array)($params['payload'] ?? []);
		if ($kenmerk === '') {
			return new JSONResponse(
				['error' => 'missing_fields', 'message' => $this->l->t('The "kenmerk" field is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->osoService->sendExport(kenmerk: $kenmerk, payload: $payload);
			return new JSONResponse($result);
		} catch (OsoTranslationException $exception) {
			return new JSONResponse(
				['error' => 'invalid_export', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (OsoProviderException $exception) {
			$this->logger->warning('[OsoController] export failed: ' . $exception->getMessage());

			$status = Http::STATUS_BAD_GATEWAY;
			$code = 'oso_export_failed';
			if (str_contains($exception->getMessage(), 'No active OSO source') === true) {
				$status = Http::STATUS_SERVICE_UNAVAILABLE;
				$code = 'not_configured';
			}

			return new JSONResponse(['error' => $code, 'message' => $exception->getMessage()], $status);
		}//end try

	}//end export()

	/**
	 * Receive an inbound OSO overstapdossier.
	 *
	 * The delivery authenticates the `oso-webhook` consumer, and every write runs
	 * as that consumer's account. A missing connection, account or right, and
	 * a write OpenRegister refuses, answer 503 so the partner retries.
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 * @spec openspec/changes/oso-inbound-on-the-consumer-model/specs/oso-adapter/spec.md#requirement-the-import-and-the-retour-act-as-the-oso-connections-account-req-020
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function import(): JSONResponse {
		return $this->handleSignedInbound(
			handler: fn (string $rawBody) => $this->osoService->receiveImport(rawXml: $rawBody)
		);
	}//end import()

	/**
	 * Receive an inbound OSO export acknowledgement/retour.
	 *
	 * The delivery authenticates the `oso-webhook` consumer, and every write runs
	 * as that consumer's account. A missing connection, account or right, and
	 * a write OpenRegister refuses, answer 503 so the partner retries.
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 *
	 * @spec openspec/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
	 * @spec openspec/changes/oso-inbound-on-the-consumer-model/specs/oso-adapter/spec.md#requirement-the-import-and-the-retour-act-as-the-oso-connections-account-req-020
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function retour(): JSONResponse {
		return $this->handleSignedInbound(
			handler: fn (string $rawBody) => $this->osoService->receiveReturn(rawXml: $rawBody)
		);
	}//end retour()

	/**
	 * Shared HMAC-verify-then-process flow for the two signed inbound endpoints.
	 *
	 * Gated by the same HMAC scheme as the `webhook_signature` rule: an
	 * unsigned or tampered request is rejected 401 BEFORE any state change.
	 * A verified request always acknowledges `{received: true}`, even when
	 * `$handler` fails internally (never a 500).
	 *
	 * The delivery authenticates the `oso-webhook` consumer, and every write runs
	 * as that consumer's account. A missing connection, account or right, and
	 * a write OpenRegister refuses, answer 503 so the partner retries.
	 *
	 * @param callable $handler Receives the raw verified body; return value is ignored.
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 * @spec openspec/changes/oso-inbound-on-the-consumer-model/specs/oso-adapter/spec.md#requirement-the-import-and-the-retour-act-as-the-oso-connections-account-req-020
	 */
	private function handleSignedInbound(callable $handler): JSONResponse {
		$rawBody = $this->getRawContent();

		$identity = $this->gate->identify(profile: WebhookProfiles::OSO, rawBody: $rawBody, request: $this->request);
		if ($identity instanceof JSONResponse) {
			return $identity;
		}

		try {
			$this->gate->deliver(
				identity: $identity,
				operation: function () use ($handler, $rawBody): void {
					$handler($rawBody);
				}
			);
		} catch (Throwable $exception) {
			// The account's write was refused: answer 503 so OSO delivers again.
			return $this->gate->notStored(profile: WebhookProfiles::OSO, reason: $exception->getMessage());
		}//end try

		return new JSONResponse(['received' => true]);
	}//end handleSignedInbound()

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
