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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\WebhookSignatureService;
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
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md
 */
class OsoController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param OsoService $osoService Export/import/retour orchestration logic.
	 * @param WebhookSignatureService $signatureService HMAC verification for inbound webhooks.
	 * @param IUserSession $userSession The user session (push endpoint).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OsoService $osoService,
		private readonly WebhookSignatureService $signatureService,
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
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
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
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
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
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#requirement-req-004-push-export-signed-inbound-import-and-signed-export-retour
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
	 * @param callable $handler Receives the raw verified body; return value is ignored.
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 */
	private function handleSignedInbound(callable $handler): JSONResponse {
		$rawBody = $this->getRawContent();

		try {
			$source = $this->osoService->resolveActiveSource();
		} catch (OsoProviderException) {
			// No source configured => no secret to verify against => fail closed.
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		}

		$webhookConfig = ($source->getObject()['configuration']['webhookSignature'] ?? []);
		$scheme = ($webhookConfig['scheme'] ?? 'openconnector');
		$secret = (string)($webhookConfig['secret'] ?? '');
		$headerName = ($webhookConfig['header'] ?? 'X-OpenConnector-Signature');
		$tolerance = (int)($webhookConfig['toleranceSeconds'] ?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS);

		$headerValue = (string)$this->request->getHeader($headerName);

		$verified = $this->signatureService->verify(
			rawBody: $rawBody,
			headerValue: $headerValue,
			config: ['scheme' => $scheme, 'secret' => $secret, 'toleranceSeconds' => $tolerance]
		);

		if ($verified === false) {
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$handler($rawBody);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[OsoController] inbound processing failed: ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}

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
