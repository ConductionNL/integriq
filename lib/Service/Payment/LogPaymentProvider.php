<?php

/**
 * OpenConnector Log Payment Provider.
 *
 * Sandbox/mock binding for {@see PaymentProviderInterface}: performs no real
 * network call, returns a synthetic `MOCK-PAY-<n>` provider payment id and a
 * canned checkout URL from `createPayment`, and answers `fetchPaymentStatus`
 * from a per-payment, caller-seeded status map
 * (`configuration.mockStatuses[providerPaymentId]`, default `open`). It MUST
 * NOT read any secret. It is the default for dev/CI and is the ONLY provider
 * exercised by this change's automated tests (deterministic, no network) —
 * mirrors the `LogPeppolAccessPointProvider` / `LogPsd2AggregatorProvider`
 * sandbox convention.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Payment
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
 * @spec openspec/changes/live-payment-providers/specs/live-payment-providers/spec.md#scenario-the-log-provider-creates-a-payment-without-a-network-call-or-secret
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Payment;

/**
 * Sandbox payment provider: canned checkout URL, synthetic payment ids, seeded status.
 *
 * @spec openspec/changes/live-payment-providers/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002
 */
class LogPaymentProvider implements PaymentProviderInterface
{

    /**
     * Per-process counter for synthetic provider payment ids (`MOCK-PAY-<n>`).
     *
     * A per-process, in-memory counter is sufficient for a sandbox binding —
     * ids only need to be locally unique for the duration of one request/test
     * run (mirrors `LogPeppolAccessPointProvider::$counter`).
     *
     * @var integer
     */
    private static int $counter = 0;

    /**
     * {@inheritDoc}
     *
     * @param array $sourceConfiguration The payment source's `configuration` object (unused — no secret needed).
     * @param array $payload             The create-payment envelope.
     *
     * @return array{providerPaymentId: string, paymentStatus: string, checkoutUrl: string, extras: array}
     *
     * @spec openspec/changes/live-payment-providers/specs/live-payment-providers/spec.md#scenario-the-log-provider-creates-a-payment-without-a-network-call-or-secret
     */
    public function createPayment(array $sourceConfiguration, array $payload): array
    {
        self::$counter++;
        $providerPaymentId = 'MOCK-PAY-'.self::$counter;

        $method = ($payload['method'] ?? 'ideal');

        return [
            'providerPaymentId' => $providerPaymentId,
            'paymentStatus'     => 'open',
            'checkoutUrl'       => 'https://sandbox.payment.example/checkout/'.$providerPaymentId,
            'extras'            => ['method' => $method],
        ];

    }//end createPayment()

    /**
     * {@inheritDoc}
     *
     * Reads the seeded status from `configuration.mockStatuses[providerPaymentId]`
     * (default `open` when unseeded) — no upstream call, deterministic for tests.
     *
     * @param array  $sourceConfiguration The payment source's `configuration` object (`mockStatuses`).
     * @param string $providerPaymentId   The provider-assigned payment id.
     *
     * @return array{providerPaymentId: string, paymentStatus: string}
     *
     * @spec openspec/changes/live-payment-providers/specs/live-payment-providers/spec.md#requirement-signature-gated-webhook-that-never-trusts-an-inbound-status-claim-req-lpp-003
     */
    public function fetchPaymentStatus(array $sourceConfiguration, string $providerPaymentId): array
    {
        $mockStatuses = ($sourceConfiguration['mockStatuses'] ?? []);
        if (is_array($mockStatuses) === false) {
            $mockStatuses = [];
        }

        $status = ($mockStatuses[$providerPaymentId] ?? 'open');

        return [
            'providerPaymentId' => $providerPaymentId,
            'paymentStatus'     => (string) $status,
        ];

    }//end fetchPaymentStatus()
}//end class
