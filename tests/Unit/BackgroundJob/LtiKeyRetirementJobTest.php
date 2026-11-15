<?php

/**
 * LtiKeyRetirementJob retires expired LTI signing keys hourly, one sweep at a time.
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

use OCA\Integriq\BackgroundJob\LtiKeyRetirementJob;
use OCA\Integriq\Service\Lti\LtiKeyServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\LtiKeyRetirementJob
 */
class LtiKeyRetirementJobTest extends TestCase {

	public function testItRunsHourlyAloneWhenTheServerIsIdle(): void {
		$job = new LtiKeyRetirementJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(LtiKeyServiceInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(3600, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive());
		$this->assertFalse($job->getAllowParallelRuns());
	}

	public function testASweepLogsHowManyKeysItRetired(): void {
		$keys = $this->createMock(LtiKeyServiceInterface::class);
		$keys->expects($this->once())->method('retireExpiredKeys')->willReturn(2);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('LtiKeyRetirementJob: retirement sweep complete', ['retired' => 2]);
		$logger->expects($this->never())->method('error');

		(new LtiKeyRetirementJob($this->createMock(ITimeFactory::class), $keys, $logger))->run(null);
	}

	public function testAFailedSweepIsLoggedAsAnError(): void {
		$failure = new RuntimeException('key store locked');
		$keys    = $this->createMock(LtiKeyServiceInterface::class);
		$keys->method('retireExpiredKeys')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->once())->method('error')
			->with('LtiKeyRetirementJob: retirement sweep failed: key store locked', ['exception' => $failure]);

		(new LtiKeyRetirementJob($this->createMock(ITimeFactory::class), $keys, $logger))->run(null);
	}
}
