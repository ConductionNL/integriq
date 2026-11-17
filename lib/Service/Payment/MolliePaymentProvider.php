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
	 * Mollie's point-of-sale method: the payment is sent to a terminal.
	 *
	 * @var string
	 */
	public const METHOD_POINT_OF_SALE = 'pointofsale';

	/**
	 * Most pages a list read follows before it stops.
	 *
	 * @var integer
	 */
	private const MAX_PAGES = 50;

	/**
	 * Constructor.
	 *
	 * @param BrokeredCallService $brokeredCallService Dispatches the call through the OpenRegister credential broker.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for secret-free failure diagnostics.
	 * @param SettlementMapper $settlementMapper Maps a settlement onto the reported shape.
	 */
	public function __construct(
		private readonly BrokeredCallService $brokeredCallService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly SettlementMapper $settlementMapper=new SettlementMapper(),
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

		if (($payload['method'] ?? null) === self::METHOD_POINT_OF_SALE) {
			$body['terminalId'] = (string)($payload['terminalId'] ?? '');
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
		if (($payload['method'] ?? null) === self::METHOD_POINT_OF_SALE && $checkoutUrl === null) {
			// A point-of-sale payment goes to the terminal: it has no checkout link.
			$checkoutUrl = '';
		} else if ($this->isUsableText(value: $checkoutUrl) === false) {
			$providerPaymentId = null;
		}

		if ($this->isUsableText(value: $providerPaymentId) === false) {
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
	/**
	 * {@inheritDoc}
	 *
	 * Reads Mollie's settlement list (newest first) page by page until a
	 * settlement is older than `$since`, and per paid-out settlement its
	 * payments, refunds and chargebacks. A settlement not yet paid out has no
	 * `settledAt` and is left for a later pull.
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object.
	 * @param string $since ISO 8601 lower bound.
	 *
	 * @return list<array<string, mixed>> The settlements, mapped by SettlementMapper.
	 *
	 * @throws PaymentProviderException When Mollie is unreachable or errors.
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
	 */
	public function fetchSettlements(array $sourceConfiguration, string $since): array {
		$bound = strtotime($since);
		$settlements = [];
		foreach ($this->listAll(sourceConfiguration: $sourceConfiguration, path: '/settlements', key: 'settlements', stopBefore: $bound) as $settlement) {
			if (empty($settlement['settledAt']) === true) {
				continue;
			}

			$settlements[] = $this->settlementDetail(sourceConfiguration: $sourceConfiguration, settlement: $settlement);
		}

		return $settlements;

	}//end fetchSettlements()

	/**
	 * One paid-out settlement with its payments, refunds and chargebacks.
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object.
	 * @param array $settlement The settlement as listed.
	 *
	 * @return array<string, mixed> The mapped settlement.
	 *
	 * @throws PaymentProviderException When Mollie is unreachable or errors.
	 */
	private function settlementDetail(array $sourceConfiguration, array $settlement): array {
		$path = '/settlements/' . rawurlencode((string)($settlement['id'] ?? ''));

		return $this->settlementMapper->map(
			settlement: $settlement,
			payments: $this->listAll(sourceConfiguration: $sourceConfiguration, path: $path . '/payments', key: 'payments'),
			refunds: $this->listAll(sourceConfiguration: $sourceConfiguration, path: $path . '/refunds', key: 'refunds'),
			chargebacks: $this->listAll(sourceConfiguration: $sourceConfiguration, path: $path . '/chargebacks', key: 'chargebacks')
		);

	}//end settlementDetail()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object.
	 *
	 * @return list<array{terminalId: string, label: string, status: string}> The terminals.
	 *
	 * @throws PaymentProviderException When Mollie is unreachable or errors.
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-payment-can-be-taken-on-a-registered-point-of-sale-device-req-lpp-009
	 */
	public function listTerminals(array $sourceConfiguration): array {
		$terminals = [];
		foreach ($this->listAll(sourceConfiguration: $sourceConfiguration, path: '/terminals', key: 'terminals') as $terminal) {
			$terminals[] = [
				'terminalId' => (string)($terminal['id'] ?? ''),
				'label' => (string)($terminal['description'] ?? ''),
				'status' => (string)($terminal['status'] ?? ''),
			];
		}

		return $terminals;

	}//end listTerminals()

	/**
	 * Every item of a Mollie list, following `_links.next`.
	 *
	 * With `$stopBefore` the list is read newest first and stops at the first
	 * item whose `settledAt` or `createdAt` lies before that moment.
	 *
	 * @param array    $sourceConfiguration The payment source's `configuration` object.
	 * @param string   $path                The list path, such as `/terminals`.
	 * @param string   $key                 The `_embedded` key holding the items.
	 * @param int|null $stopBefore          Unix time to stop at, or null to read all.
	 *
	 * @return list<array<string, mixed>> The items.
	 *
	 * @throws PaymentProviderException When Mollie is unreachable, errors or answers non-JSON.
	 */
	private function listAll(array $sourceConfiguration, string $path, string $key, ?int $stopBefore=null): array {
		$items = [];
		$url = $this->baseUrl(sourceConfiguration: $sourceConfiguration) . $path . '?limit=250';
		for ($page = 0; $url !== '' && $page < self::MAX_PAGES; $page++) {
			$decoded = json_decode($this->dispatch(sourceConfiguration: $sourceConfiguration, call: new PaymentCall(method: 'GET', url: $url)), true);
			if (is_array($decoded) === false) {
				throw new PaymentProviderException(message: 'Mollie returned a non-JSON response for ' . $path . '.');
			}

			foreach (($decoded['_embedded'][$key] ?? []) as $item) {
				if ($stopBefore !== null && $this->isBefore(item: $item, moment: $stopBefore) === true) {
					return $items;
				}

				$items[] = $item;
			}

			$url = (string)($decoded['_links']['next']['href'] ?? '');
		}

		return $items;

	}//end listAll()

	/**
	 * Whether a listed item lies before a moment.
	 *
	 * @param array $item   The item.
	 * @param int   $moment Unix time.
	 *
	 * @return bool
	 */
	private function isBefore(array $item, int $moment): bool {
		$when = strtotime((string)($item['settledAt'] ?? $item['createdAt'] ?? ''));

		return $when !== false && $when < $moment;

	}//end isBefore()
}//end class
