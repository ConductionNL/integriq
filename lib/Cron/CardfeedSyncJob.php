<?php

/**
 * Integriq Cardfeed Sync Job.
 *
 * Background job that runs the scheduled corporate-card transaction sync: for
 * every `active` cardfeed_account it pulls each card's transactions since the
 * last watermark, dedupes on transaction id, persists a `cardfeed_batch` of the
 * new transactions, and emits `nl.conduction.cardfeed.transactions.synced`
 * (REQ-003/REQ-004). Runs 4x daily by default, mirroring BankfeedSyncJob's
 * TimedJob registration pattern.
 *
 * @category Cron
 * @package  OCA\Integriq\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Cron;

use OCA\Integriq\Service\CardfeedSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically pulls corporate-card transactions per account.
 *
 * Skips non-active accounts (disabled programs make no provider call), dedupes
 * on transaction id so a replayed sweep does not double-emit, and advances each
 * account's watermark only on a successful pull.
 *
 * @psalm-api
 *
 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003
 */
class CardfeedSyncJob extends TimedJob {

	/**
	 * Default sweep interval in seconds (6 hours — 4x daily per REQ-003).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 21600;

	/**
	 * CardfeedSyncJob constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param CardfeedSyncService $syncService The cardfeed sync service.
	 * @param LoggerInterface $logger Logger for sweep outcomes and containment.
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CardfeedSyncService $syncService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);

		// Card transaction pulls are not strictly time-sensitive.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Only one sweep at a time to avoid double-pulling a window.
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute the cardfeed sync sweep.
	 *
	 * A single failing account must never wedge the cron pipeline — the service
	 * already contains per-account failures, and any sweep-level exception is
	 * caught and logged rather than rethrown.
	 *
	 * @param mixed $argument Task arguments (not used).
	 *
	 * @return void
	 *
	 * @psalm-param   mixed $argument
	 * @phpstan-param mixed $argument
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/corporate-card-feed/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-003
	 */
	public function run(mixed $argument): void {
		try {
			$batches = $this->syncService->syncAll();
			$this->logger->info(
				'CardfeedSyncJob: sync sweep complete',
				['batches' => $batches]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'CardfeedSyncJob: sync sweep failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end run()
}//end class
