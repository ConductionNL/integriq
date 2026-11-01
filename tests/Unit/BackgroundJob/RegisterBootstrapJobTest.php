<?php

/**
 * Unit tests for the register bootstrap background job.
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
 *
 * @spec openspec/specs/repair-and-app-boot/spec.md#requirement-register-descriptor-import-via-or-configurationservice-on-install-upgrade-req-001
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
use RuntimeException;

/**
 * The job runs the register repair step once per installed version.
 *
 * @covers \OCA\Integriq\BackgroundJob\RegisterBootstrapJob
 */
class RegisterBootstrapJobTest extends TestCase {

	/**
	 * Config values keyed by app config key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Keys written through setValueString, in order.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Nothing runs before the app has an installed version.
	 *
	 * @return void
	 */
	public function testNothingRunsWithoutAnInstalledVersion(): void {
		$repair = $this->createMock(InitializeRegisterInterface::class);
		$repair->expects($this->never())->method('run');

		$this->runJob(repair: $repair, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame([], $this->written);

	}//end testNothingRunsWithoutAnInstalledVersion()

	/**
	 * Nothing runs when this version is already bootstrapped.
	 *
	 * @return void
	 */
	public function testNothingRunsWhenTheVersionIsAlreadyBootstrapped(): void {
		$this->config = ['installed_version' => '0.4.13', 'register_bootstrapped_version' => '0.4.13'];
		$repair = $this->createMock(InitializeRegisterInterface::class);
		$repair->expects($this->never())->method('run');

		$this->runJob(repair: $repair, logger: $this->createMock(LoggerInterface::class));

		$this->assertSame([], $this->written);

	}//end testNothingRunsWhenTheVersionIsAlreadyBootstrapped()

	/**
	 * A new version runs the repair step with an output sink and records the marker.
	 *
	 * @return void
	 */
	public function testANewVersionRunsTheRepairStepAndRecordsTheMarker(): void {
		$this->config = ['installed_version' => '0.4.13', 'register_bootstrapped_version' => '0.4.12'];
		$repair = $this->createMock(InitializeRegisterInterface::class);
		$repair->expects($this->once())->method('run')->with($this->isInstanceOf(IOutput::class));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');
		$logger->expects($this->never())->method('warning');

		$this->runJob(repair: $repair, logger: $logger);

		$this->assertSame(['register_bootstrapped_version' => '0.4.13'], $this->written);

	}//end testANewVersionRunsTheRepairStepAndRecordsTheMarker()

	/**
	 * A failing repair step leaves the marker unset and logs a warning.
	 *
	 * @return void
	 */
	public function testAFailingRepairStepLeavesTheMarkerUnset(): void {
		$this->config = ['installed_version' => '0.4.13'];
		$repair = $this->createMock(InitializeRegisterInterface::class);
		$repair->method('run')->willThrowException(new RuntimeException('register unavailable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('register unavailable'));

		$this->runJob(repair: $repair, logger: $logger);

		$this->assertSame([], $this->written);

	}//end testAFailingRepairStepLeavesTheMarkerUnset()

	/**
	 * Build the job over an in-memory app config and invoke its run().
	 *
	 * @param InitializeRegisterInterface $repair The repair step double.
	 * @param LoggerInterface             $logger The logger double.
	 *
	 * @return void
	 */
	private function runJob(InitializeRegisterInterface $repair, LoggerInterface $logger): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;
				return true;
			}
		);

		$job = new RegisterBootstrapJob($this->createMock(ITimeFactory::class), $appConfig, $repair, $logger);
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

	}//end runJob()
}//end class
