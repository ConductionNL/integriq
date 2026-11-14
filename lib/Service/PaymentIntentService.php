<?php

/**
 * Integriq Payment Intent Service.
 *
 * Core of the live-payment-providers connector: resolves the configured
 * payment source + provider binding, drives payment creation, and processes
 * verified inbound webhooks by re-deriving the authoritative status (never
 * trusting the webhook body), mapping it onto shillinq's
 * `PaymentReconciliationService` outcome vocabulary
 * (`authorized|captured|failed|voided`), and emitting exactly one
 * `nl.conduction.payment.status` CloudEvent per actual outcome change.
 * `PaymentsController` stays a thin HTTP/signature-verification shell.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/specs/live-payment-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Integriq\Exception\PaymentProviderException;
use OCA\Integriq\Service\Payment\LogPaymentProvider;
use OCA\Integriq\Service\Payment\MolliePaymentProvider;
use OCA\Integriq\Service\Payment\PaymentProviderInterface;
use OCA\Integriq\Service\Payment\PaymentProviderProbeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drives payment creation and the verified-webhook status lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/live-payment-providers/spec.md
 */
class PaymentIntentService implements PaymentIntentServiceInterface {

	/**
	 * OpenRegister register slug holding payment sources and intents.
	 *
	 * @var string
	 */
	// Frozen on the old id: this is the OpenRegister REGISTER SLUG, not the app id.
	// OpenRegister matches registers by slug; renaming it orphans every stored object.
	public const REGISTER = 'integriq';

	/**
	 * OR schema slug for a payment source.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * OR schema slug for a payment_intent record.
	 *
	 * @var string
	 */
	public const SCHEMA_PAYMENT_INTENT = 'payment_intent';

	/**
	 * `source.type` value identifying a payment-provider source.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'payment';

	/**
	 * CloudEvent type emitted on every payment status outcome change.
	 *
	 * @var string
	 */
	public const EVENT_TYPE_STATUS = 'nl.conduction.payment.status';

	/**
	 * Map of provider-native (Mollie) status to shillinq's
	 * `PaymentReconciliationService` outcome vocabulary. Statuses absent from
	 * this map (`open`, `pending`, `refunded`, `chargeback`) have no mapped
	 * outcome and are a no-op (REQ-LPP-004).
	 *
	 * @var array<string, string>
	 */
	private const STATUS_OUTCOME_MAP = [
		'paid' => 'captured',
		'authorized' => 'authorized',
		'failed' => 'failed',
		'expired' => 'failed',
		'canceled' => 'voided',
	];

	/**
	 * The provider bindings this service knows, with whether each needs a brokered key.
	 *
	 * @var array<string, array{label: string, needsKey: bool}>
	 */
	private const PROVIDERS = [
		'log' => ['label' => 'Log (sandbox, charges nobody)', 'needsKey' => false],
		'mollie' => ['label' => 'Mollie', 'needsKey' => true],
	];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OR object service for source/payment_intent persistence.
	 * @param LogPaymentProvider $logProvider The sandbox provider binding.
	 * @param MolliePaymentProvider $mollieProvider The Mollie REST provider binding.
	 * @param CloudEventEmitterInterface $eventService Emits the payment-status CloudEvent.
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly LogPaymentProvider $logProvider,
		private readonly MolliePaymentProvider $mollieProvider,
		private readonly CloudEventEmitterInterface $eventService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Create a payment: validate the request, resolve the provider, dispatch,
	 * and persist the resulting `payment_intent`.
	 *
	 * @param array $payload The create-payment envelope — see
	 *                       {@see PaymentProviderInterface::createPayment()}, plus an
	 *                       optional `sourceSlug` selecting a specific payment source.
	 *
	 * @return array{paymentIntentId: string, providerPaymentId: string, paymentStatus: string,
	 *               checkoutUrl: string, dormant: bool, extras: array} The response envelope.
	 *
	 * @throws InvalidArgumentException When required fields are missing (caller maps to HTTP 400).
	 * @throws PaymentProviderException When no source is configured or the provider errors
	 *                                  (caller maps to HTTP 502).
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md
	 */
	public function createPayment(array $payload): array {
		$this->validateCreateRequest(payload: $payload);

		$sourceSlug = ($payload['sourceSlug'] ?? null);
		$source = $this->resolveSource(sourceSlug: $sourceSlug);
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$result = $provider->createPayment(sourceConfiguration: $configuration, payload: $payload);

		$now = (new DateTime())->format('c');
		$record = $this->objectService->saveObject(
			object: [
				'sourceSlug' => ($source->getObject()['slug'] ?? $sourceSlug ?? ''),
				'provider' => (string)($configuration['provider'] ?? 'log'),
				'providerPaymentId' => $result['providerPaymentId'],
				'amountValue' => (string)($payload['amount']['value'] ?? ''),
				'amountCurrency' => (string)($payload['amount']['currency'] ?? ''),
				'description' => (string)($payload['description'] ?? ''),
				'method' => ($payload['method'] ?? null),
				'redirectUrl' => (string)($payload['redirectUrl'] ?? ''),
				'checkoutUrl' => $result['checkoutUrl'],
				'metadata' => ($payload['metadata'] ?? []),
				'paymentStatus' => $result['paymentStatus'],
				'lastOutcome' => null,
				'createdAt' => $now,
				'updatedAt' => $now,
			],
			register: self::REGISTER,
			schema: self::SCHEMA_PAYMENT_INTENT,
			// System context: `payment_intent` is locked to admins and owners
			// (99-payment-intent-lockdown.json), and a `payments.create` holder
			// need not be an admin. The ADR-023 action check in
			// PaymentsController::create() is the gate for this write.
			_rbac: false
		);

		return [
			'paymentIntentId' => $record->getUuid(),
			'providerPaymentId' => $result['providerPaymentId'],
			'paymentStatus' => $result['paymentStatus'],
			'checkoutUrl' => $result['checkoutUrl'],
			'dormant' => ((string)($configuration['provider'] ?? 'log') === 'log'),
			'extras' => $result['extras'],
		];

	}//end createPayment()

	/**
	 * Process a verified inbound webhook call: re-derive the authoritative
	 * status (ignoring anything claimed in the body), map it to shillinq's
	 * outcome vocabulary, apply the idempotency guard, and emit a status
	 * CloudEvent on an actual outcome change.
	 *
	 * A callback for an unknown `providerPaymentId` is recorded (logged) and
	 * MUST NOT throw — it returns a `not-found` result rather than a 500
	 * (mirrors `PeppolTransmissionService::handleDeliveryCallback()`).
	 *
	 * @param string $providerPaymentId The provider payment id from the webhook body (`id`).
	 *                                  Any other field in the body is deliberately ignored.
	 *
	 * @return array{result: string, outcome: ?string, paymentStatus: ?string} `result` is one of
	 *                                                 `applied`|`noop`|`not-found`; `outcome` is the mapped outcome when applied;
	 *                                                 `paymentStatus` is the provider-native status read, null when not found.
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md#requirement-signature-gated-webhook-that-never-trusts-an-inbound-status-claim-req-lpp-003
	 */
	public function handleWebhook(string $providerPaymentId): array {
		if ($providerPaymentId === '') {
			$this->logger->warning('[PaymentIntentService] webhook payload carried no payment id; ignored');
			return ['result' => 'not-found', 'outcome' => null, 'paymentStatus' => null];
		}

		$record = $this->findPaymentIntent(providerPaymentId: $providerPaymentId);
		if ($record === null) {
			$this->logger->warning(
				'[PaymentIntentService] webhook for unknown providerPaymentId; recorded, no state change',
				['providerPaymentId' => $providerPaymentId]
			);
			return ['result' => 'not-found', 'outcome' => null, 'paymentStatus' => null];
		}

		$data = $record->getObject();

		// Read the last-applied outcome BEFORE mutating $data — it is the
		// idempotency guard (REQ-LPP-005) and must reflect the persisted state,
		// not this call's in-progress edits.
		$previousOutcome = ($data['lastOutcome'] ?? null);

		$sourceSlug = (string)($data['sourceSlug'] ?? '');
		$source = $this->resolveSource(sourceSlug: $sourceSlug);
		$configuration = ($source->getObject()['configuration'] ?? []);
		$provider = $this->resolveProvider(configuration: $configuration);

		$status = $provider->fetchPaymentStatus(sourceConfiguration: $configuration, providerPaymentId: $providerPaymentId);
		$nativeStatus = $status['paymentStatus'];
		$outcome = (self::STATUS_OUTCOME_MAP[$nativeStatus] ?? null);

		$data['paymentStatus'] = $nativeStatus;
		$data['updatedAt'] = (new DateTime())->format('c');

		if ($outcome === null) {
			// Unmapped status (open/pending/refunded/chargeback): persist the
			// latest native status, no outcome change, no event (REQ-LPP-004).
			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_PAYMENT_INTENT,
				uuid: $record->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
			return ['result' => 'noop', 'outcome' => null, 'paymentStatus' => $nativeStatus];
		}

		if ($previousOutcome === $outcome) {
			// Idempotency guard (REQ-LPP-005): the mapped outcome is unchanged
			// from what was already applied — persist the native status but do
			// not re-emit.
			$this->objectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA_PAYMENT_INTENT,
				uuid: $record->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
			return ['result' => 'noop', 'outcome' => $outcome, 'paymentStatus' => $nativeStatus];
		}

		$data['lastOutcome'] = $outcome;
		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER,
			schema: self::SCHEMA_PAYMENT_INTENT,
			uuid: $record->getUuid(),
			_rbac: false,
			_multitenancy: false
		);

		$this->emitStatusEvent(providerPaymentId: $providerPaymentId, outcome: $outcome);

		return ['result' => 'applied', 'outcome' => $outcome, 'paymentStatus' => $nativeStatus];
	}//end handleWebhook()

	/**
	 * Refresh one payment's status from its provider, through the webhook's own path.
	 *
	 * Loads the `payment_intent` and hands its `providerPaymentId` to
	 * {@see handleWebhook()}, so a refresh and a webhook apply the same
	 * `lastOutcome` guard and emit the same single status event; they can never
	 * disagree or reconcile twice. The caller gates this behind `payments.review`.
	 *
	 * @param string $paymentIntentId The `payment_intent` object id.
	 *
	 * @return array{result: string, outcome: ?string, paymentStatus: ?string} `result` is
	 *                                                                         `applied`|`noop`|`not-found`.
	 *
	 * @throws PaymentProviderException When the source names an unknown provider or the provider errors.
	 *
	 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payments-status-is-refreshed-from-the-provider-on-request-req-prv-002
	 */
	public function refreshPayment(string $paymentIntentId): array {
		$record = $this->findPaymentIntentById(paymentIntentId: $paymentIntentId);
		$providerPaymentId = '';
		if ($record !== null) {
			$providerPaymentId = (string)($record->getObject()['providerPaymentId'] ?? '');
		}

		if ($providerPaymentId === '') {
			return ['result' => 'not-found', 'outcome' => null, 'paymentStatus' => null];
		}

		return $this->handleWebhook(providerPaymentId: $providerPaymentId);

	}//end refreshPayment()

	/**
	 * The provider bindings a payment source can pick, in a stable order.
	 *
	 * @return array<int, array{id: string, label: string, needsKey: bool}> The bindings.
	 *
	 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
	 */
	public function listProviders(): array {
		$providers = [];
		foreach (self::PROVIDERS as $id => $provider) {
			$providers[] = [
				'id' => $id,
				'label' => $provider['label'],
				'needsKey' => $provider['needsKey'],
			];
		}

		return $providers;

	}//end listProviders()

	/**
	 * Check a draft payment source configuration with its binding's read-only probe.
	 *
	 * An unknown provider value, or a binding without a probe, answers `ok: false`
	 * with the reason; the answer never carries the key.
	 *
	 * @param array $configuration The draft `configuration` of a payment source.
	 *
	 * @return array{ok: bool, provider: string, mode: ?string, profile: ?string, message: ?string} The check result.
	 *
	 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
	 */
	public function checkProvider(array $configuration): array {
		$raw = ($configuration['provider'] ?? 'log');
		$name = '(not text)';
		if (is_scalar($raw) === true) {
			$name = (string)$raw;
		}

		if ($name === '') {
			$name = 'log';
		}

		try {
			$provider = $this->resolveProvider(configuration: $configuration);
		} catch (PaymentProviderException $exception) {
			return ['ok' => false, 'provider' => $name, 'mode' => null, 'profile' => null, 'message' => $exception->getMessage()];
		}

		if ($provider instanceof PaymentProviderProbeInterface === false) {
			return [
				'ok' => false,
				'provider' => $name,
				'mode' => null,
				'profile' => null,
				'message' => 'The "' . $name . '" payment provider offers no check.',
			];
		}

		return ['provider' => $name] + $provider->probe(sourceConfiguration: $configuration);

	}//end checkProvider()

	/**
	 * Resolve the payment source's `configuration.webhookSignature` block for
	 * signature verification. Public so `PaymentsController::webhook()` can
	 * verify BEFORE calling `handleWebhook()` (mirrors
	 * `PeppolController::inbound()` / `PeppolTransmissionService::resolveActiveSource()`).
	 *
	 * @return ObjectEntity The resolved active payment source.
	 *
	 * @throws PaymentProviderException When no active payment source is configured.
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md#requirement-signature-gated-webhook-that-never-trusts-an-inbound-status-claim-req-lpp-003
	 */
	public function resolveActiveSource(): ObjectEntity {
		return $this->resolveSource(sourceSlug: null);
	}//end resolveActiveSource()

	/**
	 * Validate the create-payment request shape (REQ-LPP-001).
	 *
	 * @param array $payload The create-payment envelope.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a required field is missing/empty.
	 */
	private function validateCreateRequest(array $payload): void {
		$amount = ($payload['amount'] ?? []);
		if (is_array($amount) === false
			|| empty($amount['value']) === true
			|| empty($amount['currency']) === true
		) {
			throw new InvalidArgumentException($this->l->t('Invalid payment amount') . ': `amount.value` and `amount.currency` are required.');
		}

		if (empty($payload['description']) === true) {
			throw new InvalidArgumentException('`description` is required.');
		}

	}//end validateCreateRequest()

	/**
	 * Resolve a payment source by slug, or the single active one when no slug is given.
	 *
	 * @param string|null $sourceSlug The requested source slug, or null for the active source.
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws PaymentProviderException When no matching/active payment source is configured.
	 */
	private function resolveSource(?string $sourceSlug): ObjectEntity {
		$filters = [
			'register' => self::REGISTER,
			'schema' => self::SCHEMA_SOURCE,
			'type' => self::SOURCE_TYPE,
		];

		if ($sourceSlug !== null && $sourceSlug !== '') {
			$filters['slug'] = $sourceSlug;
		} else {
			$filters['isEnabled'] = true;
		}

		// System context: `source` is admin-only (99-source-lockdown.json), and this
		// runs for a non-admin `payments.create` holder and for the sessionless
		// provider webhook. The engine needs the source; the caller never sees it.
		$matches = $this->objectService->findAll(
			config: ['filters' => $filters, 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			$selector = ', isEnabled=true';
			if ($sourceSlug !== null && $sourceSlug !== '') {
				$selector = ', slug "' . $sourceSlug . '"';
			}

			throw new PaymentProviderException(
				message: $this->l->t('No active payment source is configured') . ' (register "openconnector", '
					. 'schema "source", type "payment"' . $selector . '). '
					. 'Configure one before using the payment connector.'
			);
		}

		return $results[0];
	}//end resolveSource()

	/**
	 * Select the provider binding named by `configuration.provider`.
	 *
	 * An absent or empty value means `log`, so a dormant source keeps working. A
	 * value integriq has no binding for is refused with its name: a mistyped
	 * `Mollie` must stop the payment, not run the stub and record a payment
	 * nobody is charged for (REQ-PRV-004).
	 *
	 * @param array $configuration The payment source's `configuration` object.
	 *
	 * @return PaymentProviderInterface The resolved provider binding.
	 *
	 * @throws PaymentProviderException When the value names no binding.
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002
	 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-an-unknown-provider-value-is-refused-not-run-as-the-stub-req-prv-004
	 */
	private function resolveProvider(array $configuration): PaymentProviderInterface {
		$provider = ($configuration['provider'] ?? null);
		if ($provider === null || $provider === '' || $provider === 'log') {
			return $this->logProvider;
		}

		if ($provider === 'mollie') {
			return $this->mollieProvider;
		}

		$name = '(not text)';
		if (is_scalar($provider) === true) {
			$name = (string)$provider;
		}

		throw new PaymentProviderException(
			message: $this->l->t('Unknown payment provider') . ': "' . $name . '". '
				. 'Known providers: ' . implode(', ', array_keys(self::PROVIDERS)) . '.'
		);
	}//end resolveProvider()

	/**
	 * Find the `payment_intent` record for a given provider payment id.
	 *
	 * @param string $providerPaymentId The provider-assigned payment id.
	 *
	 * @return ObjectEntity|null The matching record, or null when none exists.
	 */
	private function findPaymentIntent(string $providerPaymentId): ?ObjectEntity {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA_PAYMENT_INTENT,
					'providerPaymentId' => $providerPaymentId,
				],
				'limit' => 1,
			],
			// System context: the verified provider webhook has no session and
			// `payment_intent` is locked to admins and owners
			// (99-payment-intent-lockdown.json). The webhook signature is the gate.
			_rbac: false,
			_multitenancy: false
		);
		$results = ($matches['results'] ?? $matches);

		if (empty($results) === true) {
			return null;
		}

		return $results[0];
	}//end findPaymentIntent()

	/**
	 * Find a `payment_intent` by its own object id.
	 *
	 * @param string $paymentIntentId The object id.
	 *
	 * @return ObjectEntity|null The record, or null when there is none.
	 */
	private function findPaymentIntentById(string $paymentIntentId): ?ObjectEntity {
		if ($paymentIntentId === '') {
			return null;
		}

		try {
			$record = $this->objectService->find(
				id: $paymentIntentId,
				register: self::REGISTER,
				schema: self::SCHEMA_PAYMENT_INTENT,
				// System context: `payment_intent` is locked to admins and owners
				// (99-payment-intent-lockdown.json). The ADR-023 `payments.review`
				// check in PaymentsController::refresh() is the gate for this read.
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->info(
				'[PaymentIntentService] payment to refresh not found',
				['paymentIntentId' => $paymentIntentId, 'errorClass' => get_class($exception)]
			);
			return null;
		}

		if ($record instanceof ObjectEntity === false) {
			return null;
		}

		return $record;

	}//end findPaymentIntentById()

	/**
	 * Emit `nl.conduction.payment.status`, payload-shaped identically to
	 * `PaymentReconciliationService::reconcile()`'s `$event` parameter so a
	 * future shillinq event listener can pass the `data` straight through.
	 *
	 * @param string $providerPaymentId The provider payment id (becomes the event's `paymentIntentId`
	 *                                  — shillinq resolves records by this value, not integriq's
	 *                                  own object uuid).
	 * @param string $outcome The mapped outcome (`authorized|captured|failed|voided`).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md
	 */
	private function emitStatusEvent(string $providerPaymentId, string $outcome): void {
		$errorMessage = null;
		if ($outcome === 'failed') {
			// Mirrors shillinq's own PaymentRequestWebhookController::extractMollieEvent()
			// default text exactly, for wire-level consistency.
			$errorMessage = 'Payment failed at gateway.';
		}

		$settlementReference = null;
		if ($outcome === 'captured') {
			$settlementReference = $providerPaymentId;
		}

		$this->eventService->emitCloudEvent(
			type: self::EVENT_TYPE_STATUS,
			source: '/payments/' . $providerPaymentId,
			subject: $providerPaymentId,
			data: [
				'paymentIntentId' => $providerPaymentId,
				'outcome' => $outcome,
				'errorCode' => null,
				'errorMessage' => $errorMessage,
				'settlementReference' => $settlementReference,
				'gatewayFeeAmount' => null,
			]
		);

	}//end emitStatusEvent()
}//end class
