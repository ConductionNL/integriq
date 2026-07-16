<?php

/**
 * OpenConnector StUF-ZKN Retry Job.
 *
 * Background job that re-drives outbound StUF-ZKN kennisgeving sends that
 * previously failed transport: for every `stuf_message` row with
 * `status: failed` (direction=outbound), re-attempts dispatch via the
 * currently configured provider. Runs hourly by default, mirrors
 * `IwmoIjwRetryJob`'s `TimedJob` registration pattern. A bridge with no
 * eligible rows is a clean no-op (`StufZknSyncService::retryFailed()`
 * never throws out of the sweep loop).
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
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-outbound-kennisgeving-dispatch-with-per-message-audit-req-006
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Service\StufZknSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically retries failed StUF-ZKN outbound sends.
 *
 * @psalm-api
 *
 * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-outbound-kennisgeving-dispatch-with-per-message-audit-req-006
 */
class StufZknRetryJob extends TimedJob
{

    /**
     * Default sweep interval in seconds (1 hour).
     *
     * @var integer
     */
    private const DEFAULT_INTERVAL = 3600;

    /**
     * StufZknRetryJob constructor.
     *
     * @param ITimeFactory       $time        Time factory for job scheduling.
     * @param StufZknSyncService $syncService The StUF-ZKN sync service.
     * @param LoggerInterface    $logger      Logger for sweep outcomes and containment.
     *
     * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-outbound-kennisgeving-dispatch-with-per-message-audit-req-006
     */
    public function __construct(
        ITimeFactory $time,
        private readonly StufZknSyncService $syncService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        $this->setInterval(seconds: self::DEFAULT_INTERVAL);

        // Retries are not strictly time-sensitive.
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

        // Only one sweep at a time to avoid double-retrying the same row.
        $this->setAllowParallelRuns(allow: false);

    }//end __construct()

    /**
     * Execute the StUF-ZKN retry sweep.
     *
     * A single failing message must never wedge the cron pipeline — the
     * service already contains per-message failures, and any sweep-level
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
     * @spec openspec/changes/stuf-zkn-bridge/specs/stuf-zkn-bridge/spec.md#requirement-outbound-kennisgeving-dispatch-with-per-message-audit-req-006
     */
    public function run(mixed $argument): void
    {
        try {
            $retried = $this->syncService->retryFailed();
            $this->logger->info(
                'StufZknRetryJob: retry sweep complete',
                ['retried' => $retried]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'StufZknRetryJob: retry sweep failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end run()
}//end class
