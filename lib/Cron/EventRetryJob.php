<?php
/**
 * OpenConnector Event Retry Job.
 *
 * Background job that sweeps undelivered event messages and re-attempts their
 * delivery according to the retry-hardening backoff schedule. Without this job
 * EventService::processRetries() has no caller and retries never run.
 *
 * @category Cron
 * @package  OCA\OpenConnector\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Service\EventService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background job that periodically re-attempts undelivered event messages.
 *
 * Selects every event_message in a non-terminal state whose scheduled
 * nextAttempt has passed and replays it through EventService::deliverMessage,
 * so a transiently-failing sink eventually drains to delivered and a dead sink
 * stops being attempted once maxRetries is reached.
 *
 * @psalm-api
 *
 * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-4
 */
class EventRetryJob extends TimedJob
{

    /**
     * Default sweep interval in seconds (5 minutes).
     *
     * @var integer
     */
    private const DEFAULT_INTERVAL = 300;

    /**
     * EventRetryJob constructor.
     *
     * @param ITimeFactory    $time         Time factory for job scheduling.
     * @param EventService    $eventService The event delivery service.
     * @param LoggerInterface $logger       Logger for sweep outcomes and containment.
     *
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-4
     */
    public function __construct(
        ITimeFactory $time,
        private readonly EventService $eventService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        $this->setInterval(seconds: self::DEFAULT_INTERVAL);

        // Delivery retries are not strictly time-sensitive.
        $this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

        // Only one sweep at a time to avoid double-attempting a message.
        $this->setAllowParallelRuns(allow: false);

    }//end __construct()

    /**
     * Execute the retry sweep.
     *
     * A single poisoned message must never wedge the cron pipeline, so any
     * exception from the sweep is caught and logged rather than rethrown.
     *
     * @param mixed $argument Task arguments (not used).
     *
     * @return void
     *
     * @psalm-param   mixed $argument
     * @phpstan-param mixed $argument
     *
     * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-4
     */
    public function run(mixed $argument): void
    {
        try {
            $delivered = $this->eventService->processRetries();
            $this->logger->info(
                'EventRetryJob: retry sweep complete',
                ['delivered' => $delivered]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'EventRetryJob: retry sweep failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }

    }//end run()
}//end class
