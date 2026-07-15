<?php

/**
 * OpenConnector Corporate Card-Feed Provider Interface.
 *
 * Narrow domain seam through which every corporate/credit-card feed operation
 * occurs: card discovery and transaction pulls. A new card-program vendor
 * (Stripe Issuing, Adyen, Ramp, Moss, …) is added by implementing this
 * interface, never by editing CardfeedSyncService or CardfeedController — see
 * design.md and the sibling Psd2AggregatorProviderInterface.
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
 * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-card-provider-abstraction-with-log-and-generic-rest-bindings-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Cardfeed;

use OCA\OpenConnector\Exception\CardfeedProviderException;

/**
 * A corporate-card-feed provider binding: card discovery + transaction pulls.
 *
 * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-card-provider-abstraction-with-log-and-generic-rest-bindings-req-001
 */
interface CardfeedProviderInterface
{
    /**
     * List the corporate cards a card program authorises.
     *
     * @param array $sourceConfiguration The cardfeed source's `configuration` object
     *                                   (`provider`, `baseUrl`, `authentication.credentialRef`).
     *
     * @return array<int, array{cardId: string, last4: string, cardholderName: string, currency: string}> The cards.
     *
     * @throws CardfeedProviderException When the provider is unreachable, errors, or is misconfigured.
     *
     * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-source-enrollment-and-card-discovery-req-002
     */
    public function listCards(array $sourceConfiguration): array;

    /**
     * Pull the transactions for one card in a `[since, until]` window.
     *
     * Each returned row MUST carry a stable `transactionId` used for the
     * connector's idempotency dedup (REQ-004).
     *
     * @param array  $sourceConfiguration The cardfeed source's `configuration` object.
     * @param string $cardId              The card id (from {@see listCards}).
     * @param string $since               ISO 8601 start of the pull window (inclusive).
     * @param string $until               ISO 8601 end of the pull window (inclusive).
     *
     * @return array<int, array<string, mixed>> Provider-normalised transaction rows (each with a `transactionId`).
     *
     * @throws CardfeedProviderException When the provider is unreachable or errors (the caller MUST NOT advance its watermark).
     *
     * @spec openspec/changes/corporate-card-feed/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003
     */
    public function listTransactions(array $sourceConfiguration, string $cardId, string $since, string $until): array;
}//end interface
