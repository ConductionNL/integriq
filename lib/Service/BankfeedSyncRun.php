<?php

/**
 * Integriq Bankfeed Sync Run.
 *
 * What one sync of one bankfeed connection pulls with: the source
 * configuration, the connection id and accounts, and the transaction window
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

/**
 * The source configuration, connection and transaction window of one connection sync.
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
	 * The connection's accounts.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public readonly array $accounts;

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
	 * @param array $configuration The source's `configuration` object.
	 * @param array $connection    The bankfeed_connection data.
	 *
	 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
	 */
	public function __construct(
		public readonly array $configuration,
		array $connection,
	) {
		$this->connectionId = ($connection['connectionId'] ?? null);
		$this->accounts = (array)($connection['accounts'] ?? []);

		$defaultSince = (new DateTime())->sub(new DateInterval('P' . self::DEFAULT_BACKFILL_DAYS . 'D'))->format('c');
		$this->since = (string)(($connection['lastSyncAt'] ?? null) ?? ($connection['consentGrantedAt'] ?? null) ?? $defaultSince);
		$this->until = (new DateTime())->format('c');
	}//end __construct()
}//end class
