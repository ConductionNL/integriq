<?php

/**
 * OptOutLogRetentionJob logs a failed deletion and stays quiet when nothing was old enough to delete.
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

use OCA\Integriq\BackgroundJob\OptOutLogRetentionJob;
use OCA\Integriq\Db\OptOutLogMapperInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\OptOutLogRetentionJob
 */
class OptOutLogRetentionJobOutcomeTest extends TestCase {

	private function runJob(OptOutLogMapperInterface $log, LoggerInterface $logger): void {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1791201600);
		$job = new OptOutLogRetentionJob($clock, $log, $logger);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testAFailedDeletionIsLoggedAsAWarning(): void {
		$log = $this->createMock(OptOutLogMapperInterface::class);
		$log->expects($this->once())->method('deleteOlderThan')->willThrowException(new RuntimeException('table locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[OptOutLogRetentionJob] could not delete old entries: table locked');
		$logger->expects($this->never())->method('info');

		$this->runJob($log, $logger);
	}

	public function testNothingIsLoggedWhenNoEntryWasOldEnough(): void {
		$log = $this->createMock(OptOutLogMapperInterface::class);
		$log->expects($this->once())->method('deleteOlderThan')->with(1570276800)->willReturn(0);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->never())->method('warning');

		$this->runJob($log, $logger);
	}
}
