<?php

/**
 * Integriq Mollie Payment Provider.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Generic REST binding for {@see PaymentProviderInterface} against the
 * Mollie Payments API v2, driven by `configuration.baseUrl` (defaults to
 * `https://api.mollie.com/v2`) and `authentication.credentialRef`. Every
 * outbound call is dispatched through {@see BrokeredCallService} so the
 * Mollie API key is injected in-process by the OpenRegister credential
 * broker and never stored, exported, or logged in plaintext (ADR-007,
 * REQ-LPP-006). There is no fallback to an embedded secret: a source without
 * a resolvable `credentialRef` fails closed with an actionable
 * {@see PaymentProviderException}. Mirrors `RestPeppolAccessPointProvider`'s
 * dispatch structure.
 *
 * Real Mollie webhooks carry only `{id}` — no status, no signature. This
 * class's `fetchPaymentStatus()` is therefore the ONLY source of truth for a
 * payment's status; {@see PaymentIntentService} never trusts anything a
 * webhook body claims (see design.md "Why re-fetch instead of trusting the
 * webhook body").
 *
 * @category Service
 * @package  OCA\Integriq\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 * @link https://docs.mollie.com/reference/v2/payments-api/create-payment
 *
 * @spec openspec/specs/live-payment-providers/spec.md#scenario-the-mollie-provider-brokers-its-api-key
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Payment;

use OCA\Integriq\Exception\PaymentProviderException;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCP\IL10N;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mollie Payments API v2 provider, dispatched through the credential broker.
 *
 * @spec openspec/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002
 */
class MolliePaymentProvider implements PaymentProviderInterface {

	/**
	 * Default Mollie Payments API v2 base URL.
	 *
	 * @var string
	 */
	private const DEFAULT_BASE_URL = 'https://api.mollie.com/v2';

	/**
	 * Constructor.
	 *
	 * @param BrokeredCallService $brokeredCallService Dispatches the call through the OpenRegister credential broker.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for secret-free failure diagnostics.
	 */
	public function __construct(
		private readonly BrokeredCallService $brokeredCallService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The id a payment source names this provider by.
	 *
	 * @return string `mollie`.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-extension-app-contributes-adapters-through-a-collect-event-req-cxk-001
	 */
	public function getProviderId(): string {
		return 'mollie';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object (`baseUrl`, `authentication.credentialRef`).
	 * @param array $payload The create-payment envelope.
	 *
	 * @return array{providerPaymentId: string, paymentStatus: string, checkoutUrl: string, extras: array}
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md
	 */
	public function createPayment(array $sourceConfiguration, array $payload): array {
		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			call: new PaymentCall(
				method: 'POST',
				url: $this->baseUrl(sourceConfiguration: $sourceConfiguration) . '/payments',
				json: $this->paymentBody(payload: $payload)
			)
		);

		$decoded = json_decode($response, true);
		if (is_array($decoded) === false) {
			throw new PaymentProviderException(
				message: 'Mollie returned a non-JSON response for payment creation.'
			);
		}

		return $this->createdPayment(decoded: $decoded, payload: $payload);

	}//end createPayment()

	/**
	 * The payment request body: amount, description and URLs, plus method and metadata when given.
	 *
	 * @param array $payload The integriq payment payload.
	 *
	 * @return array The Mollie request body.
	 */
	private function paymentBody(array $payload): array {
		$body = [
			'amount' => $payload['amount'],
			'description' => $payload['description'],
			'redirectUrl' => $payload['redirectUrl'],
			'webhookUrl' => $payload['webhookUrl'],
		];

		if (empty($payload['method']) === false) {
			$body['method'] = $payload['method'];
		}

		if (empty($payload['metadata']) === false) {
			$body['metadata'] = $payload['metadata'];
		}

		return $body;

	}//end paymentBody()

	/**
	 * The created payment; Mollie must have answered a payment id and a checkout URL.
	 *
	 * @param array $decoded The decoded Mollie answer.
	 * @param array $payload The integriq payment payload.
	 *
	 * @return array{providerPaymentId: string, paymentStatus: string, checkoutUrl: string, extras: array<string, mixed>}
	 *
	 * @throws PaymentProviderException When the id or the checkout URL is missing.
	 */
	private function createdPayment(array $decoded, array $payload): array {
		$providerPaymentId = ($decoded['id'] ?? null);
		$checkoutUrl = ($decoded['_links']['checkout']['href'] ?? null);
		if ($this->isUsableText(value: $providerPaymentId) === false || $this->isUsableText(value: $checkoutUrl) === false) {
			throw new PaymentProviderException(
				message: 'Mollie accepted the request but returned no usable payment id or checkout URL.'
			);
		}

		return [
			'providerPaymentId' => $providerPaymentId,
			'paymentStatus' => (string)($decoded['status'] ?? 'open'),
			'checkoutUrl' => $checkoutUrl,
			'extras' => ['method' => ($decoded['method'] ?? ($payload['method'] ?? null))],
		];

	}//end createdPayment()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a non-empty string.
	 */
	private function isUsableText(mixed $value): bool {
		return (is_string($value) === true && $value !== '');

	}//end isUsableText()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object.
	 * @param string $providerPaymentId The Mollie payment id (`tr_...`).
	 *
	 * @return array{providerPaymentId: string, paymentStatus: string}
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md#requirement-signature-gated-webhook-that-never-trusts-an-inbound-status-claim-req-lpp-003
	 */
	public function fetchPaymentStatus(array $sourceConfiguration, string $providerPaymentId): array {
		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			call: new PaymentCall(
				method: 'GET',
				url: $this->baseUrl(sourceConfiguration: $sourceConfiguration) . '/payments/' . rawurlencode($providerPaymentId)
			)
		);

		$decoded = json_decode($response, true);
		if (is_array($decoded) === false) {
			throw new PaymentProviderException(
				message: 'Mollie returned a non-JSON response for the payment status lookup.'
			);
		}

		return [
			'providerPaymentId' => $providerPaymentId,
			'paymentStatus' => (string)($decoded['status'] ?? ''),
		];

	}//end fetchPaymentStatus()

	/**
	 * Resolve the Mollie API base URL, defaulting to the real v2 endpoint.
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object.
	 *
	 * @return string The base URL, no trailing slash.
	 */
	private function baseUrl(array $sourceConfiguration): string {
		$baseUrl = (string)($sourceConfiguration['baseUrl'] ?? self::DEFAULT_BASE_URL);
		if ($baseUrl === '') {
			$baseUrl = self::DEFAULT_BASE_URL;
		}

		return rtrim($baseUrl, '/');
	}//end baseUrl()

	/**
	 * Dispatch one brokered call and return its raw body, mapping every
	 * failure mode to a secret-free {@see PaymentProviderException} — never a
	 * 500 crash, per REQ-LPP-001/REQ-LPP-006.
	 *
	 * @param array       $sourceConfiguration The payment source's `configuration` object.
	 * @param PaymentCall $call                The HTTP method, the composed URL and the optional JSON request body.
	 *
	 * @return string The response body.
	 *
	 * @throws PaymentProviderException On any configuration, brokering, transport, or upstream error.
	 */
	private function dispatch(array $sourceConfiguration, PaymentCall $call): string {
		$config = ['authentication' => ($sourceConfiguration['authentication'] ?? [])];
		if ($call->json !== null) {
			$config['json'] = $call->json;
		}

		$this->assertCredentialConfigured(config: $config);

		try {
			$response = $this->brokeredDispatch(config: $config, call: $call);
		} catch (BrokeredCallConfigurationException $exception) {
			throw new PaymentProviderException(message: $exception->getMessage(), previous: $exception);
		} catch (Throwable $exception) {
			throw $this->unexpectedFailure(exception: $exception);
		}

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new PaymentProviderException(message: 'Mollie responded with HTTP ' . $status . '.');
		}

		return $body;
	}//end dispatch()

	/**
	 * Refuse a call when the source names no broker credential: there is no plaintext-key fallback.
	 *
	 * @param array $config The brokered call config.
	 *
	 * @return void
	 *
	 * @throws PaymentProviderException When no credentialRef is configured.
	 */
	private function assertCredentialConfigured(array $config): void {
		if ($this->brokeredCallService->hasCredentialRef(config: $config) === false) {
			throw new PaymentProviderException(
				message: $this->l->t('Payment provider credential missing') . ': the `mollie` payment provider '
					. 'requires `configuration.authentication.credentialRef` — none is configured. Configure a '
					. 'credentialRef through the OpenRegister credential broker; no plaintext-key fallback is '
					. 'permitted (ADR-007).'
			);
		}

	}//end assertCredentialConfigured()

	/**
	 * Prepare the brokered credential and dispatch the call with it.
	 *
	 * @param array       $config The brokered call config.
	 * @param PaymentCall $call   The method and URL.
	 *
	 * @return ResponseInterface The answer, whatever its status.
	 *
	 * @throws BrokeredCallConfigurationException When the broker refuses the configuration.
	 */
	private function brokeredDispatch(array $config, PaymentCall $call): ResponseInterface {
		$dispatch = $this->brokeredCallService->prepare(
			config: $config,
			sourceData: ['type' => 'payment'],
			asynchronous: false
		);

		return $this->brokeredCallService->dispatch(
			credentialId: $dispatch['credentialId'],
			actingUserId: $dispatch['actingUserId'],
			method: $call->method,
			url: $call->url,
			config: $config
		);

	}//end brokeredDispatch()

	/**
	 * Log an unexpected transport failure and wrap it.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return PaymentProviderException The exception to throw.
	 */
	private function unexpectedFailure(Throwable $exception): PaymentProviderException {
		$this->logger->warning(
			'[MolliePaymentProvider] unexpected transport failure',
			['exception' => $exception->getMessage()]
		);

		return new PaymentProviderException(
			message: 'The Mollie request failed unexpectedly: ' . $exception->getMessage(),
			previous: $exception
		);

	}//end unexpectedFailure()
}//end class
