<?php

/**
 * The register initialisation step on an instance without OpenRegister.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/repair-and-app-boot/spec.md#requirement-register-descriptor-import-via-or-configurationservice-on-install-upgrade-req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\InitializeRegister;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs in its own process: the other InitializeRegister tests load the
 * OpenRegister ConfigurationService stub, after which the class exists for
 * the rest of the run.
 *
 * @covers \OCA\Integriq\Repair\InitializeRegister
 */
class InitializeRegisterWithoutOpenRegisterTest extends TestCase {

	/**
	 * Without OpenRegister the step warns, logs and touches nothing else.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function testWithoutOpenRegisterItWarnsAndStops(): void {
		$this->assertFalse(class_exists('\\OCA\\OpenRegister\\Service\\ConfigurationService'));

		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('getValueString');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Integriq: OpenRegister not available, skipping register initialization');
		$logger->expects($this->never())->method('error');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Integriq: initializing register + schemas via OR ConfigurationService…');
		$output->expects($this->once())->method('warning')
			->with('Integriq: OpenRegister is not installed or enabled. Skipping register initialization.');

		(new InitializeRegister($container, $appConfig, $logger))->run($output);
	}//end testWithoutOpenRegisterItWarnsAndStops()

	/**
	 * The step names itself for occ.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new InitializeRegister(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame('Initialize Integriq register and 15 schemas via ConfigurationService', $step->getName());
	}//end testName()
}//end class
