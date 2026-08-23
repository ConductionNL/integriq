<?php

/**
 * Integriq Mollie Payment Provider.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 * @link https://docs.mollie.com/reference/v2/payments-api/create-payment
 *
 * @spec openspec/specs/live-payment-providers/spec.md#scenario-the-mollie-provider-brokers-its-api-key
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Payment;

use OCA\Integriq\Exception\BrokeredCallConfigurationException;
use OCA\Integriq\Exception\PaymentProviderException;
use OCA\Integriq\Service\BrokeredCallService;
use OCP\IL10N;
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

		$response = $this->dispatch(
			sourceConfiguration: $sourceConfiguration,
			method: 'POST',
			url: $this->baseUrl(sourceConfiguration: $sourceConfiguration) . '/payments',
			json: $body
		);

		$decoded = json_decode($response, true);
		if (is_array($decoded) === false) {
			throw new PaymentProviderException(
				message: 'Mollie returned a non-JSON response for payment creation.'
			);
		}

		$providerPaymentId = ($decoded['id'] ?? null);
		$checkoutUrl = ($decoded['_links']['checkout']['href'] ?? null);

		if (is_string($providerPaymentId) === false || $providerPaymentId === ''
			|| is_string($checkoutUrl) === false || $checkoutUrl === ''
		) {
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

	}//end createPayment()

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
			method: 'GET',
			url: $this->baseUrl(sourceConfiguration: $sourceConfiguration) . '/payments/' . rawurlencode($providerPaymentId)
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
	 * @param array $sourceConfiguration The payment source's `configuration` object.
	 * @param string $method The HTTP method.
	 * @param string $url The composed URL.
	 * @param array|null $json Optional JSON request body.
	 *
	 * @return string The response body.
	 *
	 * @throws PaymentProviderException On any configuration, brokering, transport, or upstream error.
	 */
	private function dispatch(array $sourceConfiguration, string $method, string $url, ?array $json = null): string {
		$config = ['authentication' => ($sourceConfiguration['authentication'] ?? [])];
		if ($json !== null) {
			$config['json'] = $json;
		}

		if ($this->brokeredCallService->hasCredentialRef(config: $config) === false) {
			throw new PaymentProviderException(
				message: $this->l->t('Payment provider credential missing') . ': the `mollie` payment provider '
					. 'requires `configuration.authentication.credentialRef` — none is configured. Configure a '
					. 'credentialRef through the OpenRegister credential broker; no plaintext-key fallback is '
					. 'permitted (ADR-007).'
			);
		}

		try {
			$dispatch = $this->brokeredCallService->prepare(
				config: $config,
				sourceData: ['type' => 'payment'],
				asynchronous: false
			);

			$response = $this->brokeredCallService->dispatch(
				credentialId: $dispatch['credentialId'],
				actingUserId: $dispatch['actingUserId'],
				method: $method,
				url: $url,
				config: $config
			);
		} catch (BrokeredCallConfigurationException $exception) {
			throw new PaymentProviderException(message: $exception->getMessage(), previous: $exception);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[MolliePaymentProvider] unexpected transport failure',
				['exception' => $exception->getMessage()]
			);
			throw new PaymentProviderException(
				message: 'The Mollie request failed unexpectedly: ' . $exception->getMessage(),
				previous: $exception
			);
		}//end try

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new PaymentProviderException(message: 'Mollie responded with HTTP ' . $status . '.');
		}

		return $body;
	}//end dispatch()
}//end class
