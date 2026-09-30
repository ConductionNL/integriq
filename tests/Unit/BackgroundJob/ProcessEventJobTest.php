<?php

/**
 * ProcessEventJob hands its queued event to the fan-out, off the request.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
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
 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\ProcessEventJob;
use OCA\Integriq\Service\EventService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The job is a QueuedJob that processes the event it names.
 */
class ProcessEventJobTest extends TestCase {

	/**
	 * Run the job's protected run() with an argument.
	 *
	 * @param ProcessEventJob $job      The job.
	 * @param mixed           $argument The queued argument.
	 *
	 * @return void
	 */
	private function runJob(ProcessEventJob $job, mixed $argument): void {
		$method = new ReflectionMethod($job, 'run');
		$method->invoke($job, $argument);
	}//end runJob()

	/**
	 * The queued event id reaches the fan-out; the job is one-shot.
	 *
	 * @return void
	 */
	public function testTheQueuedEventIsProcessed(): void {
		$service = $this->createMock(EventService::class);
		$service->expects($this->once())->method('processQueuedEvents')->with(['event-1'])->willReturn(2);

		$job = new ProcessEventJob($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class));
		$this->assertInstanceOf(QueuedJob::class, $job);

		$this->runJob($job, ['eventId' => 'event-1']);
	}//end testTheQueuedEventIsProcessed()

	/**
	 * An argument without an event id is dropped with a warning.
	 *
	 * @return void
	 */
	public function testAnIncompleteArgumentIsDropped(): void {
		$service = $this->createMock(EventService::class);
		$service->expects($this->never())->method('processQueuedEvents');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->runJob(new ProcessEventJob($this->createMock(ITimeFactory::class), $service, $logger), ['other' => 1]);
	}//end testAnIncompleteArgumentIsDropped()

	/**
	 * A failing fan-out is logged and does not take the cron worker down.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$service = $this->createMock(EventService::class);
		$service->method('processQueuedEvents')->willThrowException(new RuntimeException('boom'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->runJob(new ProcessEventJob($this->createMock(ITimeFactory::class), $service, $logger), ['eventId' => 'event-1']);
	}//end testAFailureIsLoggedNotThrown()
}//end class
