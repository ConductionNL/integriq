<?php

/**
 * Integriq Bankfeed Sync Run.
 *
 * What one sync of one bankfeed connection pulls with: the aggregator binding
 * and its source configuration, the connection id, and the transaction window
 * from the last watermark (or the consent grant, or a fixed backfill) until now.
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
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateInterval;
use DateTime;
use OCA\Integriq\Service\Psd2\Psd2AggregatorProviderInterface;

/**
 * The aggregator, connection and transaction window of one connection sync.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
 */
final class BankfeedSyncRun {

	/**
	 * Default backfill window in days for a connection that has never synced.
	 *
	 * @var integer
	 */
	private const DEFAULT_BACKFILL_DAYS = 30;

	/**
	 * The connection's `connectionId`, as stored.
	 *
	 * @var mixed
	 */
	public readonly mixed $connectionId;

	/**
	 * Start of the window: the last watermark, else the consent grant, else the backfill start.
	 *
	 * @var string
	 */
	public readonly string $since;

	/**
	 * End of the window: now.
	 *
	 * @var string
	 */
	public readonly string $until;

	/**
	 * Constructor.
	 *
	 * @param Psd2AggregatorProviderInterface $provider      The aggregator binding of the connection's source.
	 * @param array                           $configuration The source's `configuration` object.
	 * @param array                           $connection    The bankfeed_connection data.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
	 */
	public function __construct(
		public readonly Psd2AggregatorProviderInterface $provider,
		public readonly array $configuration,
		array $connection,
	) {
		$this->connectionId = ($connection['connectionId'] ?? null);

		$defaultSince = (new DateTime())->sub(new DateInterval('P' . self::DEFAULT_BACKFILL_DAYS . 'D'))->format('c');
		$this->since = (string)(($connection['lastSyncAt'] ?? null) ?? ($connection['consentGrantedAt'] ?? null) ?? $defaultSince);
		$this->until = (new DateTime())->format('c');
	}//end __construct()
}//end class
