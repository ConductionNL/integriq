<?php

/**
 * Unit tests for OutboundReconciliationJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\OutboundReconciliationJob;
use OCA\Integriq\Service\Synchronization\OutboundReconciliationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The job reconciles each opted-in synchronization, once a day.
 */
#[CoversClass(OutboundReconciliationJob::class)]
class OutboundReconciliationJobTest extends TestCase {

	/**
	 * Every synchronization is reconciled, and one failing does not stop the next.
	 *
	 * @return void
	 */
	public function testEachSynchronizationIsReconciledAndAFailureDoesNotStopTheNext(): void {
		$service = $this->createMock(OutboundReconciliationService::class);
		$service->method('findReconcilable')->willReturn([['slug' => 'a'], ['slug' => 'b']]);
		$seen = [];
		$service->expects($this->exactly(2))->method('reconcile')->willReturnCallback(
			function (array $synchronization) use (&$seen): array {
				$seen[] = $synchronization['slug'];
				if ($synchronization['slug'] === 'a') {
					throw new RuntimeException('target down');
				}

				return ['message' => 'Reconciled: 1 created'];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[OutboundReconciliationJob] a failed: target down');
		$logger->expects($this->once())->method('info')->with('[OutboundReconciliationJob] b: Reconciled: 1 created');

		$this->runJob(job: new OutboundReconciliationJob($this->createMock(ITimeFactory::class), $service, $logger));

		$this->assertSame(['a', 'b'], $seen);
	}//end testEachSynchronizationIsReconciledAndAFailureDoesNotStopTheNext()

	/**
	 * A store that cannot be read is logged and nothing runs.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreIsLogged(): void {
		$service = $this->createMock(OutboundReconciliationService::class);
		$service->method('findReconcilable')->willThrowException(new RuntimeException('no register'));
		$service->expects($this->never())->method('reconcile');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[OutboundReconciliationJob] could not read synchronizations: no register');

		$this->runJob(job: new OutboundReconciliationJob($this->createMock(ITimeFactory::class), $service, $logger));
	}//end testAnUnreadableStoreIsLogged()

	/**
	 * The job runs once a day.
	 *
	 * @return void
	 */
	public function testTheJobRunsDaily(): void {
		$job = new OutboundReconciliationJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(OutboundReconciliationService::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(86400, $job->getInterval());
	}//end testTheJobRunsDaily()

	/**
	 * Call the protected run().
	 *
	 * @param OutboundReconciliationJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(OutboundReconciliationJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()
}//end class
