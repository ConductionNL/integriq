<?php

/**
 * Unit tests for EventRetryJob.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Cron;

use OCA\OpenConnector\Cron\EventRetryJob;
use OCA\OpenConnector\Service\EventService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the event retry sweep background job.
 *
 * @spec openspec/changes/openconnector-event-retry-hardening/tasks.md#task-4
 */
class EventRetryJobTest extends TestCase {

	/**
	 * @var EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var EventRetryJob
	 */
	private EventRetryJob $job;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->eventService = $this->createMock(EventService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new EventRetryJob($timeFactory, $this->eventService, $this->logger);
	}//end setUp()

	/**
	 * The job wires its dependencies and constructs without error.
	 *
	 * @return void
	 */
	public function testConstructs(): void {
		$this->assertInstanceOf(EventRetryJob::class, $this->job);
	}//end testConstructs()

	/**
	 * REQ-007: running the job invokes processRetries.
	 *
	 * @return void
	 */
	public function testRunInvokesProcessRetries(): void {
		$this->eventService->expects($this->once())
			->method('processRetries')
			->willReturn(3);

		// run() is protected; exercise it via the public TimedJob entry shape.
		$reflection = new \ReflectionMethod($this->job, 'run');
		$reflection->setAccessible(true);
		$reflection->invoke($this->job, null);
	}//end testRunInvokesProcessRetries()

	/**
	 * REQ-007: a sweep exception is caught and logged, never rethrown.
	 *
	 * @return void
	 */
	public function testRunContainsExceptions(): void {
		$this->eventService->method('processRetries')
			->willThrowException(new \RuntimeException('poisoned message'));

		$this->logger->expects($this->once())->method('error');

		$reflection = new \ReflectionMethod($this->job, 'run');
		$reflection->setAccessible(true);

		// Must not throw.
		$reflection->invoke($this->job, null);
		$this->assertTrue(true);
	}//end testRunContainsExceptions()
}//end class
