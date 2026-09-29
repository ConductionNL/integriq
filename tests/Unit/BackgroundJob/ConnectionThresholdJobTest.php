<?php

/**
 * Unit tests for ConnectionThresholdJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\ConnectionThresholdJob;
use OCA\Integriq\Service\ConnectionAlertService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The job runs the count every five minutes and survives a failing count.
 *
 * @spec openspec/changes/observability-connection-run-summary/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
 */
final class ConnectionThresholdJobTest extends TestCase {

	/**
	 * Invoke the protected run().
	 *
	 * @param ConnectionThresholdJob $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(ConnectionThresholdJob $job): void {
		$reflection = new \ReflectionMethod($job, 'run');
		$reflection->setAccessible(true);
		$reflection->invoke($job, null);
	}//end invokeRun()

	/**
	 * Each run counts once, every five minutes.
	 *
	 * @return void
	 */
	public function testEachRunCountsOnceEveryFiveMinutes(): void {
		$alerts = $this->createMock(ConnectionAlertService::class);
		$alerts->expects($this->once())->method('evaluate')->willReturn(['opened' => 1, 'cleared' => 0]);

		$job = new ConnectionThresholdJob($this->createMock(ITimeFactory::class), $alerts, $this->createMock(LoggerInterface::class));
		$this->invokeRun($job);

		$this->assertSame(300, ConnectionThresholdJob::INTERVAL_SECONDS);
	}//end testEachRunCountsOnceEveryFiveMinutes()

	/**
	 * A failing count is logged, not thrown into the cron run.
	 *
	 * @return void
	 */
	public function testAFailingCountIsLogged(): void {
		$alerts = $this->createMock(ConnectionAlertService::class);
		$alerts->method('evaluate')->willThrowException(new RuntimeException('register gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('register gone'));

		$this->invokeRun(new ConnectionThresholdJob($this->createMock(ITimeFactory::class), $alerts, $logger));
	}//end testAFailingCountIsLogged()

	/**
	 * The job is registered, so cron runs it.
	 *
	 * @return void
	 */
	public function testTheJobIsRegistered(): void {
		$this->assertStringContainsString(
			'<job>OCA\\Integriq\\BackgroundJob\\ConnectionThresholdJob</job>',
			(string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml')
		);
	}//end testTheJobIsRegistered()
}//end class
