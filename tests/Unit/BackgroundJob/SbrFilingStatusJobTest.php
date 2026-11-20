<?php

/**
 * Unit tests for SbrFilingStatusJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/tasks.md#task-3-status-poll-and-status-events
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\SbrFilingStatusJob;
use OCA\Integriq\Service\Digipoort\SbrFilingService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The status job runs one sweep and never lets a failure escape.
 */
#[CoversClass(SbrFilingStatusJob::class)]
class SbrFilingStatusJobTest extends TestCase {
	/**
	 * A run sweeps once and logs what the sweep did.
	 *
	 * @return void
	 */
	public function testARunSweepsAndLogsTheOutcome(): void {
		$filings = $this->createMock(SbrFilingService::class);
		$filings->expects($this->once())->method('sweep')->willReturn(['retried' => 1, 'polled' => 2, 'changed' => 1]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('SbrFilingStatusJob: sweep complete', ['retried' => 1, 'polled' => 2, 'changed' => 1]);
		$logger->expects($this->never())->method('error');

		$this->runJob(job: new SbrFilingStatusJob($this->createMock(ITimeFactory::class), $filings, $logger));
	}//end testARunSweepsAndLogsTheOutcome()

	/**
	 * A failing sweep is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAFailingSweepIsLogged(): void {
		$filings = $this->createMock(SbrFilingService::class);
		$filings->method('sweep')->willThrowException(new RuntimeException('store down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('SbrFilingStatusJob: sweep failed: store down', $this->anything());

		$this->runJob(job: new SbrFilingStatusJob($this->createMock(ITimeFactory::class), $filings, $logger));
	}//end testAFailingSweepIsLogged()

	/**
	 * The job sweeps every ten minutes.
	 *
	 * @return void
	 */
	public function testTheJobRunsEveryTenMinutes(): void {
		$job = new SbrFilingStatusJob($this->createMock(ITimeFactory::class), $this->createMock(SbrFilingService::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(600, $job->getInterval());
	}//end testTheJobRunsEveryTenMinutes()

	/**
	 * Call the protected run().
	 *
	 * @param SbrFilingStatusJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(SbrFilingStatusJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()
}//end class
