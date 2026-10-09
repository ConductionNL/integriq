<?php

/**
 * Integriq — fan out one object write's CloudEvent, off the request.
 *
 * @category Cron
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/integriq
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\EventService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Matches a stored CloudEvent against the active subscriptions, writes its
 * `event_message` rows and makes the push deliveries.
 *
 * WHY THIS EXISTS. EventService's object handlers used to do all of that
 * inline, in the request that created, changed or deleted someone else's
 * object: a subscription query, one message per match and a synchronous
 * HTTP post per push subscriber. One unreachable subscriber stalled every
 * app's writes. The handlers now save the event and queue this job.
 *
 * A QueuedJob, not a TimedJob: it runs once and removes itself, so it is
 * added through IJobList by EventService and is not listed in info.xml.
 * A failed delivery is recorded on the message and retried by EventRetryJob.
 *
 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
 */
class ProcessEventJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time         The time factory.
	 * @param EventService    $eventService The fan-out.
	 * @param LoggerInterface $logger       The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly EventService $eventService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Fan out the event this job was queued for.
	 *
	 * @param mixed $argument The queued argument: ['eventId' => uuid].
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
	 */
	protected function run($argument): void {
		$eventId = null;
		if (is_array($argument) === true) {
			$eventId = ($argument['eventId'] ?? null);
		}

		if (is_string($eventId) === false || $eventId === '') {
			$this->logger->warning('[ProcessEventJob] dropped: no eventId in the argument');

			return;
		}

		try {
			$this->eventService->processQueuedEvents([$eventId]);
		} catch (Throwable $exception) {
			// The next job in the queue is someone else's event.
			$this->logger->error(
				'[ProcessEventJob] fan-out failed for event ' . $eventId . ': ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}
	}//end run()
}//end class
