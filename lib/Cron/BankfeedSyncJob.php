<?php
/**
 * OpenConnector Bankfeed Sync Job.
 *
 * Background job that runs the scheduled PSD2 AIS transaction sync: for every
 * `active` bankfeed_connection it pulls transactions since the last watermark,
 * persists a `bankfeed_batch`, and emits
 * `nl.conduction.bankfeed.transactions.synced` (REQ-004). Runs 4x daily by
 * default, mirroring EventRetryJob's TimedJob registration pattern.
 *
 * @category Cron
 * @package  OCA\OpenConnector\Cron
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
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Service\BankfeedSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically pulls PSD2 bank transactions per connection.
 *
 * Skips non-active connections (expired/revoked consents make no aggregator
 * call) and advances each connection's watermark only on a successful pull,
 * so a failed pull re-attempts the same window on the next sweep.
 *
 * @psalm-api
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
 */
class BankfeedSyncJob extends TimedJob
{

    /**
     * Default sweep interval in seconds (6 hours — 4x daily per REQ-004).
     *
     * @var integer
     */
    private const DEFAULT_INTERVAL = 21600;

    /**
     * BankfeedSyncJob constructor.
     *
     * @param ITimeFactory        $time        Time factory for job scheduling.
     * @param BankfeedSyncService $syncService The bankfeed sync service.
     * @param LoggerInterface     $logger      Logger for sweep outcomes and containment.
     *
     * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
     */
    public function __construct(
        ITimeFactory $time,
        private readonly BankfeedSyncService $syncService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        $this->setInterval(seconds: self::DEFAULT_INTERVAL);

        // Bank transaction pulls are not strictly time-sensitive.
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

        // Only one sweep at a time to avoid double-pulling a window.
        $this->setAllowParallelRuns(allow: false);

    }//end __construct()

    /**
     * Execute the bankfeed sync sweep.
     *
     * A single failing connection must never wedge the cron pipeline — the
     * service already contains per-connection failures, and any sweep-level
     * exception is caught and logged rather than rethrown.
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
     * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#requirement-scheduled-transaction-sync-emitting-a-synced-event-with-a-batch-uri-req-004
     */
    public function run(mixed $argument): void
    {
        try {
            $batches = $this->syncService->syncAll();
            $this->logger->info(
                'BankfeedSyncJob: sync sweep complete',
                ['batches' => $batches]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'BankfeedSyncJob: sync sweep failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end run()
}//end class
