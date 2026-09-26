<?php

/**
 * Integriq DUO Verzuimloket Controller.
 *
 * REST controller for integriq-adapter-verzuimloket: the push endpoint
 * learniq's `leerplicht` DataExchangeJob calls directly over an
 * authenticated NC session to register a Verzuimloket melding — mirrors
 * `RodController::berichten()` — and the signed inbound DUO
 * acknowledgement/retour receiver — mirrors `RodController::retour()`.
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
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\VerzuimloketService;
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
 * Push (register a Verzuimloket melding) + signed inbound DUO retour receiver.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md
 */
class VerzuimloketController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("integriq").
	 * @param IRequest $request Current request.
	 * @param VerzuimloketService $verzuimloketService Send/retour orchestration logic.
	 * @param WebhookSignatureService $signatureService HMAC verification for the inbound webhook.
	 * @param IUserSession $userSession The user session (push endpoint).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly VerzuimloketService $verzuimloketService,
		private readonly WebhookSignatureService $signatureService,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Register one outbound Verzuimloket melding.
	 *
	 * Expected JSON body: `{meldingType: "eerste-melding"|"herhaalmelding"|
	 * "langdurig-relatief-verzuim", kenmerk: "...", payload: {...}}` — see
	 * contract.md for the full field table per meldingType.
	 *
	 * @return JSONResponse `{ref, meldingType, status}` on success, or a 400/503/502 error envelope.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function berichten(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'verzuimloket.push');

		$params = $this->request->getParams();
		$meldingType = (string)($params['meldingType'] ?? '');
		$kenmerk = (string)($params['kenmerk'] ?? '');
		$payload = (array)($params['payload'] ?? []);
		if ($meldingType === '' || $kenmerk === '') {
			return new JSONResponse(
				[
					'error' => 'missing_fields',
					'message' => $this->l->t('The "meldingType" and "kenmerk" fields are required'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->verzuimloketService->sendMelding(meldingType: $meldingType, kenmerk: $kenmerk, payload: $payload);
			return new JSONResponse($result);
		} catch (VerzuimloketTranslationException $exception) {
			return new JSONResponse(
				['error' => 'invalid_melding', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (VerzuimloketProviderException $exception) {
			$this->logger->warning('[VerzuimloketController] send failed: ' . $exception->getMessage());

			$status = Http::STATUS_BAD_GATEWAY;
			$code = 'verzuimloket_send_failed';
			if (str_contains($exception->getMessage(), 'No active Verzuimloket source') === true) {
				$status = Http::STATUS_SERVICE_UNAVAILABLE;
				$code = 'not_configured';
			}

			return new JSONResponse(['error' => $code, 'message' => $exception->getMessage()], $status);
		}//end try

	}//end berichten()

	/**
	 * Receive an inbound DUO Verzuimloket acknowledgement/retour.
	 *
	 * Gated by the same HMAC scheme as the `webhook_signature` rule: an
	 * unsigned or tampered retour is rejected 401 BEFORE any state change.
	 *
	 * @return JSONResponse `{received: true}` on success, 401 on signature failure.
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function retour(): JSONResponse {
		$rawBody = $this->getRawContent();

		try {
			$source = $this->verzuimloketService->resolveActiveSource();
		} catch (VerzuimloketProviderException) {
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
			$this->verzuimloketService->receiveReturn(rawXml: $rawBody);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[VerzuimloketController] inbound retour processing failed: ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}

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
