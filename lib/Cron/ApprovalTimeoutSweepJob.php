<?php

/**
 * OpenConnector Approval Timeout Sweep Job.
 *
 * Background job that sweeps `pending` `approval_request` rows whose
 * `expiresAt` has passed and applies their configured `onTimeout` outcome.
 * Mirrors `EventRetryJob`'s TimedJob idiom (5-minute cadence). No human is
 * waiting on this job's output — approve/reject resume synchronously in the
 * approver's own request (design.md Decision 3); this job only handles the
 * unattended-expiry path.
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
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Service\ApprovalService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically sweeps expired approval_request rows.
 *
 * @psalm-api
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */
class ApprovalTimeoutSweepJob extends TimedJob
{

    /**
     * Sweep interval in seconds (5 minutes) — matches EventRetryJob's cadence.
     *
     * @var integer
     */
    private const DEFAULT_INTERVAL = 300;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time            Time factory for job scheduling.
     * @param ApprovalService $approvalService The approval state-machine service.
     * @param LoggerInterface $logger          Logger for sweep outcomes.
     *
     * @spec openspec/specs/approval-workflow/spec.md
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ApprovalService $approvalService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        $this->setInterval(seconds: self::DEFAULT_INTERVAL);
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
        $this->setAllowParallelRuns(allow: false);

    }//end __construct()

    /**
     * Execute the expiry sweep.
     *
     * A single poisoned row must never wedge the cron pipeline, so any
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
     * @spec openspec/specs/approval-workflow/spec.md
     */
    public function run(mixed $argument): void
    {
        try {
            $result = $this->approvalService->sweepExpired();
            $this->logger->info('ApprovalTimeoutSweepJob: sweep complete', $result);
        } catch (Throwable $e) {
            $this->logger->error(
                'ApprovalTimeoutSweepJob: sweep failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end run()
}//end class
