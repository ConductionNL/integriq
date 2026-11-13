<?php

/**
 * DirectorySyncJob runs every directory connection hourly and logs one line per run.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\DirectorySyncJob;
use OCA\Integriq\Directory\DirectorySyncServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\DirectorySyncJob
 */
class DirectorySyncJobTest extends TestCase {

	private function runJob(DirectorySyncJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testItRunsHourlyWhenTheServerIsIdle(): void {
		$job = new DirectorySyncJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(DirectorySyncServiceInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(3600, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive());
	}

	public function testEachRunIsLoggedWithItsCountsAndIdentity(): void {
		$sync = $this->createMock(DirectorySyncServiceInterface::class);
		$sync->expects($this->once())->method('runAll')->willReturn(
			[
				['connectionName' => 'Entra', 'membershipsAdded' => 3, 'membershipsRemoved' => 1, 'runId' => 'run-1', 'status' => 'success'],
				[],
			]
		);
		$lines  = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context) use (&$lines): void {
				$lines[] = [$message, $context];
			}
		);
		$logger->expects($this->never())->method('error');

		$this->runJob(new DirectorySyncJob($this->createMock(ITimeFactory::class), $sync, $logger));

		$this->assertSame(
			[
				['[DirectorySyncJob] ran Entra: 3 added, 1 removed', ['runId' => 'run-1', 'status' => 'success']],
				['[DirectorySyncJob] ran : 0 added, 0 removed', ['runId' => null, 'status' => null]],
			],
			$lines
		);
	}

	public function testAFailedRunIsLoggedAsAnErrorAndNothingElse(): void {
		$failure = new RuntimeException('LDAP bind refused');
		$sync    = $this->createMock(DirectorySyncServiceInterface::class);
		$sync->method('runAll')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('[DirectorySyncJob] directory run failed: LDAP bind refused', ['exception' => $failure]);
		$logger->expects($this->never())->method('info');

		$this->runJob(new DirectorySyncJob($this->createMock(ITimeFactory::class), $sync, $logger));
	}
}
