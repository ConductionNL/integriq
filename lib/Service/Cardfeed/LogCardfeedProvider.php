<?php

/**
 * OpenConnector Log Corporate Card-Feed Provider.
 *
 * Sandbox/mock binding for {@see CardfeedProviderInterface}: performs no real
 * network call, returns canned cards and canned transactions, and reads no
 * secret. It is the default for dev/CI and mirrors the `source-management`
 * mock-mode convention (and the sibling LogPsd2AggregatorProvider). Its
 * transaction ids are stable per card so the connector's transaction-id
 * idempotency (REQ-004) is exercisable with a fixed sandbox feed.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Cardfeed
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
 * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#scenario-the-log-provider-drives-the-full-path-without-a-network-call-or-secret
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Cardfeed;

/**
 * Sandbox corporate-card provider: canned cards and stable-id transactions.
 *
 * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#scenario-the-log-provider-drives-the-full-path-without-a-network-call-or-secret
 */
class LogCardfeedProvider implements CardfeedProviderInterface
{

    /**
     * Canned cards a sandbox card program authorises.
     *
     * @var array<int, array<string, string>>
     */
    private const MOCK_CARDS = [
        [
            'cardId'         => 'SANDBOX-CARD-1',
            'last4'          => '4242',
            'cardholderName' => 'A. Example',
            'currency'       => 'EUR',
        ],
    ];

    /**
     * {@inheritDoc}
     *
     * @param array $sourceConfiguration The cardfeed source's `configuration` object (unused — the log provider needs no secret).
     *
     * @return array<int, array{cardId: string, last4: string, cardholderName: string, currency: string}> The canned cards.
     *
     * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-source-enrollment-and-card-discovery-req-002
     */
    public function listCards(array $sourceConfiguration): array
    {
        return self::MOCK_CARDS;

    }//end listCards()

    /**
     * {@inheritDoc}
     *
     * Returns a fixed pair of transactions with stable ids per card (not
     * window-dependent) so a replayed sync returns the same ids and the
     * connector's dedup (REQ-004) is what prevents a double-emit.
     *
     * @param array  $sourceConfiguration The cardfeed source's `configuration` object (unused — no secret).
     * @param string $cardId              The card id.
     * @param string $since               ISO 8601 start of the pull window.
     * @param string $until               ISO 8601 end of the pull window.
     *
     * @return array<int, array<string, mixed>> Canned provider-shaped transaction rows with stable ids.
     *
     * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003
     */
    public function listTransactions(array $sourceConfiguration, string $cardId, string $since, string $until): array
    {
        $bookingDate = substr($since, 0, 10);

        return [
            [
                'transactionId'         => 'SANDBOX-CTX-'.$cardId.'-1',
                'bookingDate'           => $bookingDate,
                'transactionAmount'     => ['amount' => '-89.99', 'currency' => 'EUR'],
                'merchantName'          => 'Example SaaS B.V.',
                'merchantCategoryCode'  => '5734',
                'remittanceInformation' => 'Sandbox card purchase 1',
            ],
            [
                'transactionId'         => 'SANDBOX-CTX-'.$cardId.'-2',
                'bookingDate'           => $bookingDate,
                'transactionAmount'     => ['amount' => '-12.50', 'currency' => 'EUR'],
                'merchantName'          => 'Example Coffee',
                'merchantCategoryCode'  => '5814',
                'remittanceInformation' => 'Sandbox card purchase 2',
            ],
        ];

    }//end listTransactions()
}//end class
