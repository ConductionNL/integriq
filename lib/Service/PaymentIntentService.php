<?php

/**
 * OpenConnector Payment Intent Service.
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
 * @package  OCA\OpenConnector\Service
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

namespace OCA\OpenConnector\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenConnector\Exception\PaymentProviderException;
use OCA\OpenConnector\Service\Payment\LogPaymentProvider;
use OCA\OpenConnector\Service\Payment\MolliePaymentProvider;
use OCA\OpenConnector\Service\Payment\PaymentProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Drives payment creation and the verified-webhook status lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/live-payment-providers/spec.md
 */
class PaymentIntentService
{

    /**
     * OpenRegister register slug holding payment sources and intents.
     *
     * @var string
     */
    public const REGISTER = 'openconnector';

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
        'paid'       => 'captured',
        'authorized' => 'authorized',
        'failed'     => 'failed',
        'expired'    => 'failed',
        'canceled'   => 'voided',
    ];

    /**
     * Constructor.
     *
     * @param ORObjectService       $objectService  OR object service for source/payment_intent persistence.
     * @param LogPaymentProvider    $logProvider    The sandbox provider binding.
     * @param MolliePaymentProvider $mollieProvider The Mollie REST provider binding.
     * @param EventService          $eventService   Emits the payment-status CloudEvent.
     * @param IL10N                 $l              The localization service.
     * @param LoggerInterface       $logger         Logger for non-fatal diagnostics.
     */
    public function __construct(
        private readonly ORObjectService $objectService,
        private readonly LogPaymentProvider $logProvider,
        private readonly MolliePaymentProvider $mollieProvider,
        private readonly EventService $eventService,
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
    public function createPayment(array $payload): array
    {
        $this->validateCreateRequest(payload: $payload);

        $sourceSlug    = ($payload['sourceSlug'] ?? null);
        $source        = $this->resolveSource(sourceSlug: $sourceSlug);
        $configuration = ($source->getObject()['configuration'] ?? []);
        $provider      = $this->resolveProvider(configuration: $configuration);

        $result = $provider->createPayment(sourceConfiguration: $configuration, payload: $payload);

        $now    = (new DateTime())->format('c');
        $record = $this->objectService->saveObject(
            object: [
                'sourceSlug'        => ($source->getObject()['slug'] ?? $sourceSlug ?? ''),
                'provider'          => (string) ($configuration['provider'] ?? 'log'),
                'providerPaymentId' => $result['providerPaymentId'],
                'amountValue'       => (string) ($payload['amount']['value'] ?? ''),
                'amountCurrency'    => (string) ($payload['amount']['currency'] ?? ''),
                'description'       => (string) ($payload['description'] ?? ''),
                'method'            => ($payload['method'] ?? null),
                'redirectUrl'       => (string) ($payload['redirectUrl'] ?? ''),
                'checkoutUrl'       => $result['checkoutUrl'],
                'metadata'          => ($payload['metadata'] ?? []),
                'paymentStatus'     => $result['paymentStatus'],
                'lastOutcome'       => null,
                'createdAt'         => $now,
                'updatedAt'         => $now,
            ],
            register: self::REGISTER,
            schema: self::SCHEMA_PAYMENT_INTENT
        );

        return [
            'paymentIntentId'   => $record->getUuid(),
            'providerPaymentId' => $result['providerPaymentId'],
            'paymentStatus'     => $result['paymentStatus'],
            'checkoutUrl'       => $result['checkoutUrl'],
            'dormant'           => ((string) ($configuration['provider'] ?? 'log') === 'log'),
            'extras'            => $result['extras'],
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
     * @return array{result: string, outcome: ?string} `result` is one of
     *         `applied`|`noop`|`not-found`; `outcome` is the mapped outcome when applied.
     *
     * @spec openspec/specs/live-payment-providers/spec.md#requirement-signature-gated-webhook-that-never-trusts-an-inbound-status-claim-req-lpp-003
     */
    public function handleWebhook(string $providerPaymentId): array
    {
        if ($providerPaymentId === '') {
            $this->logger->warning('[PaymentIntentService] webhook payload carried no payment id; ignored');
            return ['result' => 'not-found', 'outcome' => null];
        }

        $record = $this->findPaymentIntent(providerPaymentId: $providerPaymentId);
        if ($record === null) {
            $this->logger->warning(
                '[PaymentIntentService] webhook for unknown providerPaymentId; recorded, no state change',
                ['providerPaymentId' => $providerPaymentId]
            );
            return ['result' => 'not-found', 'outcome' => null];
        }

        $data = $record->getObject();

        // Read the last-applied outcome BEFORE mutating $data — it is the
        // idempotency guard (REQ-LPP-005) and must reflect the persisted state,
        // not this call's in-progress edits.
        $previousOutcome = ($data['lastOutcome'] ?? null);

        $sourceSlug    = (string) ($data['sourceSlug'] ?? '');
        $source        = $this->resolveSource(sourceSlug: $sourceSlug);
        $configuration = ($source->getObject()['configuration'] ?? []);
        $provider      = $this->resolveProvider(configuration: $configuration);

        $status       = $provider->fetchPaymentStatus(sourceConfiguration: $configuration, providerPaymentId: $providerPaymentId);
        $nativeStatus = $status['paymentStatus'];
        $outcome      = (self::STATUS_OUTCOME_MAP[$nativeStatus] ?? null);

        $data['paymentStatus'] = $nativeStatus;
        $data['updatedAt']     = (new DateTime())->format('c');

        if ($outcome === null) {
            // Unmapped status (open/pending/refunded/chargeback): persist the
            // latest native status, no outcome change, no event (REQ-LPP-004).
            $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER,
                schema: self::SCHEMA_PAYMENT_INTENT,
                uuid: $record->getUuid()
            );
            return ['result' => 'noop', 'outcome' => null];
        }

        if ($previousOutcome === $outcome) {
            // Idempotency guard (REQ-LPP-005): the mapped outcome is unchanged
            // from what was already applied — persist the native status but do
            // not re-emit.
            $this->objectService->saveObject(
                object: $data,
                register: self::REGISTER,
                schema: self::SCHEMA_PAYMENT_INTENT,
                uuid: $record->getUuid()
            );
            return ['result' => 'noop', 'outcome' => $outcome];
        }

        $data['lastOutcome'] = $outcome;
        $this->objectService->saveObject(
            object: $data,
            register: self::REGISTER,
            schema: self::SCHEMA_PAYMENT_INTENT,
            uuid: $record->getUuid()
        );

        $this->emitStatusEvent(providerPaymentId: $providerPaymentId, outcome: $outcome);

        return ['result' => 'applied', 'outcome' => $outcome];

    }//end handleWebhook()

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
    public function resolveActiveSource(): ObjectEntity
    {
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
    private function validateCreateRequest(array $payload): void
    {
        $amount = ($payload['amount'] ?? []);
        if (is_array($amount) === false
            || empty($amount['value']) === true
            || empty($amount['currency']) === true
        ) {
            throw new InvalidArgumentException($this->l->t('Invalid payment amount').': `amount.value` and `amount.currency` are required.');
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
    private function resolveSource(?string $sourceSlug): ObjectEntity
    {
        $filters = [
            'register' => self::REGISTER,
            'schema'   => self::SCHEMA_SOURCE,
            'type'     => self::SOURCE_TYPE,
        ];

        if ($sourceSlug !== null && $sourceSlug !== '') {
            $filters['slug'] = $sourceSlug;
        } else {
            $filters['isEnabled'] = true;
        }

        $matches = $this->objectService->findAll(config: ['filters' => $filters, 'limit' => 1]);
        $results = ($matches['results'] ?? $matches);

        if (empty($results) === true) {
            $selector = ', isEnabled=true';
            if ($sourceSlug !== null && $sourceSlug !== '') {
                $selector = ', slug "'.$sourceSlug.'"';
            }

            throw new PaymentProviderException(
                message: $this->l->t('No active payment source is configured').' (register "openconnector", '
                    .'schema "source", type "payment"'.$selector.'). '
                    .'Configure one before using the payment connector.'
            );
        }

        return $results[0];

    }//end resolveSource()

    /**
     * Select the provider binding named by `configuration.provider` (default `log`).
     *
     * @param array $configuration The payment source's `configuration` object.
     *
     * @return PaymentProviderInterface The resolved provider binding.
     *
     * @spec openspec/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002
     */
    private function resolveProvider(array $configuration): PaymentProviderInterface
    {
        $provider = ($configuration['provider'] ?? 'log');
        if ($provider === 'mollie') {
            return $this->mollieProvider;
        }

        return $this->logProvider;

    }//end resolveProvider()

    /**
     * Find the `payment_intent` record for a given provider payment id.
     *
     * @param string $providerPaymentId The provider-assigned payment id.
     *
     * @return ObjectEntity|null The matching record, or null when none exists.
     */
    private function findPaymentIntent(string $providerPaymentId): ?ObjectEntity
    {
        $matches = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register'          => self::REGISTER,
                    'schema'            => self::SCHEMA_PAYMENT_INTENT,
                    'providerPaymentId' => $providerPaymentId,
                ],
                'limit'   => 1,
            ]
        );
        $results = ($matches['results'] ?? $matches);

        if (empty($results) === true) {
            return null;
        }

        return $results[0];

    }//end findPaymentIntent()

    /**
     * Emit `nl.conduction.payment.status`, payload-shaped identically to
     * `PaymentReconciliationService::reconcile()`'s `$event` parameter so a
     * future shillinq event listener can pass the `data` straight through.
     *
     * @param string $providerPaymentId The provider payment id (becomes the event's `paymentIntentId`
     *                                  — shillinq resolves records by this value, not openconnector's
     *                                  own object uuid).
     * @param string $outcome           The mapped outcome (`authorized|captured|failed|voided`).
     *
     * @return void
     *
     * @spec openspec/specs/live-payment-providers/spec.md
     */
    private function emitStatusEvent(string $providerPaymentId, string $outcome): void
    {
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
            source: '/payments/'.$providerPaymentId,
            subject: $providerPaymentId,
            data: [
                'paymentIntentId'     => $providerPaymentId,
                'outcome'             => $outcome,
                'errorCode'           => null,
                'errorMessage'        => $errorMessage,
                'settlementReference' => $settlementReference,
                'gatewayFeeAmount'    => null,
            ]
        );

    }//end emitStatusEvent()
}//end class
