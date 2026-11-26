<?php

/**
 * Unit tests for OutboundReconciliationJob.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Synchronization\OutboundReconciliationJob;
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
		$service = $this->createMock(originalClassName: OutboundReconciliationService::class);
		$service->method('findReconcilable')->willReturn([['slug' => 'a'], ['slug' => 'b']]);
		$seen = [];
		$service->expects($this->exactly(count: 2))->method('reconcile')->willReturnCallback(
			function (array $synchronization) use (&$seen): array {
				$seen[] = $synchronization['slug'];
				if ($synchronization['slug'] === 'a') {
					throw new RuntimeException(message: 'target down');
				}

				return ['message' => 'Reconciled: 1 created'];
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[OutboundReconciliationJob] a failed: target down');
		$logger->expects($this->once())->method('info')->with('[OutboundReconciliationJob] b: Reconciled: 1 created');

		$this->runJob(job: $this->job(service: $service, logger: $logger));

		$this->assertSame(expected: ['a', 'b'], actual: $seen);
	}//end testEachSynchronizationIsReconciledAndAFailureDoesNotStopTheNext()

	/**
	 * A store that cannot be read is logged and nothing runs.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreIsLogged(): void {
		$service = $this->createMock(originalClassName: OutboundReconciliationService::class);
		$service->method('findReconcilable')->willThrowException(new RuntimeException(message: 'no register'));
		$service->expects($this->never())->method('reconcile');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[OutboundReconciliationJob] could not read synchronizations: no register');

		$this->runJob(job: $this->job(service: $service, logger: $logger));
	}//end testAnUnreadableStoreIsLogged()

	/**
	 * The job runs once a day.
	 *
	 * @return void
	 */
	public function testTheJobRunsDaily(): void {
		$job = $this->job(
			service: $this->createMock(originalClassName: OutboundReconciliationService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: 86400, actual: $job->getInterval());
	}//end testTheJobRunsDaily()

	/**
	 * The job over a service and a logger.
	 *
	 * @param OutboundReconciliationService $service The service.
	 * @param LoggerInterface               $logger  The logger.
	 *
	 * @return OutboundReconciliationJob The job.
	 */
	private function job(OutboundReconciliationService $service, LoggerInterface $logger): OutboundReconciliationJob {
		return new OutboundReconciliationJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			reconciliation: $service,
			logger: $logger
		);
	}//end job()

	/**
	 * Call the protected run().
	 *
	 * @param OutboundReconciliationJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(OutboundReconciliationJob $job): void {
		(new ReflectionMethod(objectOrMethod: $job, method: 'run'))->invoke($job, null);
	}//end runJob()
}//end class
