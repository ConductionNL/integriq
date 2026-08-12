<?php

/**
 * OpenConnector Payments Controller.
 *
 * REST controller for the live-payment-providers connector: payment
 * creation and the signature-gated inbound webhook. The webhook never trusts
 * a status claimed in the body — it re-derives the authoritative status via
 * the configured provider before doing anything else (REQ-LPP-003).
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/live-payment-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use InvalidArgumentException;
use OCA\OpenConnector\Exception\PaymentProviderException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\PaymentIntentService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
 * Payment creation + signed inbound receive webhook.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *
 * @spec openspec/changes/live-payment-providers/tasks.md#task-4
 * @spec openspec/changes/live-payment-providers/tasks.md#task-5
 */
class PaymentsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App identifier ("openconnector").
	 * @param IRequest $request Current request.
	 * @param PaymentIntentService $paymentIntentService Create + webhook business logic.
	 * @param WebhookSignatureService $signatureService HMAC verification for the inbound webhook.
	 * @param IUserSession $userSession The user session (create endpoint).
	 * @param ActionAuthService $actionAuth The action authorization service.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PaymentIntentService $paymentIntentService,
		private readonly WebhookSignatureService $signatureService,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * Create a payment against the configured provider.
	 *
	 * @return JSONResponse The checkout envelope, or a 400/502 error envelope.
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$this->actionAuth->requireAction(user: $user, action: 'payments.create');

		$payload = $this->request->getParams();

		try {
			$result = $this->paymentIntentService->createPayment(payload: $payload);
			return new JSONResponse($result);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(
				['error' => 'invalid_request', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (PaymentProviderException $exception) {
			$this->logger->warning('[PaymentsController] payment creation failed: ' . $exception->getMessage());
			return new JSONResponse(
				['error' => 'payment_provider_unavailable', 'message' => $exception->getMessage()],
				Http::STATUS_BAD_GATEWAY
			);
		}//end try

	}//end create()

	/**
	 * Receive a provider payment-status webhook.
	 *
	 * Gated by the payment source's `configuration.webhookSignature` (HMAC,
	 * constant-time compare, timestamp tolerance — same scheme as
	 * `PeppolController::inbound()`): an unsigned or tampered callback is
	 * rejected 401 BEFORE any provider call, state change, or event
	 * emission. Once verified, only the `id` field of the body is used — any
	 * status claimed in the body is ignored; the authoritative status is
	 * always re-derived from the provider (REQ-LPP-003). The `#[PublicPage]`
	 * / `#[NoCSRFRequired]` attributes are present because this endpoint
	 * authenticates via webhook signature, not NC session — the signature
	 * check IS the auth body for this route (mirrors `PeppolController`).
	 *
	 * @return JSONResponse `{received: true}` on any verified/processed call, 401 on signature failure.
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md#requirement-signature-gated-webhook-that-never-trusts-an-inbound-status-claim-req-lpp-003
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function webhook(): JSONResponse {
		$rawBody = $this->getRawContent();

		try {
			$source = $this->paymentIntentService->resolveActiveSource();
		} catch (PaymentProviderException) {
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
			// Undifferentiated error body: never leak which check failed.
			return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
		}

		// Payload parsing happens from $this->request->getParams() (NC decodes a
		// JSON body into params) — NOT a second json_decode($rawBody) — mirroring
		// PeppolController: signature verification runs over the exact raw bytes,
		// while payload access goes through the framework's normalised params. Only
		// `id` is ever read — any `status` present in the body is deliberately
		// ignored (REQ-LPP-003).
		$body = $this->request->getParams();
		$providerPaymentId = (string)($body['id'] ?? '');

		try {
			$this->paymentIntentService->handleWebhook(providerPaymentId: $providerPaymentId);
		} catch (Throwable $exception) {
			// Never 500 on a verified callback: log and acknowledge receipt.
			$this->logger->error('[PaymentsController] webhook processing failed: ' . $exception->getMessage(), ['exception' => $exception]);
		}

		return new JSONResponse(['received' => true]);
	}//end webhook()

	/**
	 * Read the raw request body bytes for signature verification.
	 *
	 * Signature verification MUST run over the exact bytes the provider
	 * signed, not `$this->request->getParams()` (decoded/normalized, would
	 * desync).
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
