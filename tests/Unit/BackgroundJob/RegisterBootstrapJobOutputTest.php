<?php

/**
 * The output sink RegisterBootstrapJob hands the repair step swallows everything it is told.
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

use OCA\Integriq\BackgroundJob\RegisterBootstrapJob;
use OCA\Integriq\Repair\InitializeRegisterInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\Integriq\BackgroundJob\RegisterBootstrapJob
 */
class RegisterBootstrapJobOutputTest extends TestCase {

	public function testTheRepairStepsChatterIsDiscardedAndTheMarkerStillWritten(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['integriq', 'installed_version', '', '1.4.0'],
				['integriq', 'register_bootstrapped_version', '', '1.3.0'],
			]
		);
		$appConfig->expects($this->once())->method('setValueString')
			->with('integriq', 'register_bootstrapped_version', '1.4.0')->willReturn(true);

		$received = null;
		$repair = $this->createMock(InitializeRegisterInterface::class);
		$repair->expects($this->once())->method('run')->willReturnCallback(
			static function (IOutput $output) use (&$received): void {
				$received = $output;
				$output->debug('debug line');
				$output->info('info line');
				$output->warning('warning line');
				$output->startProgress(3);
				$output->advance(1, 'step');
				$output->finishProgress();
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->once())->method('info')
			->with('[RegisterBootstrapJob] Bootstrapped the integriq register for version 1.4.0');

		$job = new RegisterBootstrapJob($this->createMock(ITimeFactory::class), $appConfig, $repair, $logger);
		$this->expectOutputString('');
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertInstanceOf(IOutput::class, $received);
		$this->assertSame(3600, $job->getInterval());
		$this->assertFalse($job->getAllowParallelRuns());
	}
}
