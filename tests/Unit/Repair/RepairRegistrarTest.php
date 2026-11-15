<?php

/**
 * The repair registrar binds the register initializer and builds the action-matrix step.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\InitializeActions;
use OCA\Integriq\Repair\InitializeRegister;
use OCA\Integriq\Repair\InitializeRegisterInterface;
use OCA\Integriq\Repair\RepairRegistrar;
use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What RepairRegistrar::register() tells the registration context.
 *
 * @covers \OCA\Integriq\Repair\RepairRegistrar
 * @uses   \OCA\Integriq\Repair\InitializeActions
 */
class RepairRegistrarTest extends TestCase {

	/**
	 * The interface resolves to InitializeRegister, and InitializeActions is
	 * built for this app from the container's action-auth service.
	 *
	 * @return void
	 */
	public function testRegistersTheInitializerAliasAndTheActionsFactory(): void {
		$aliases = [];
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, \Closure $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new RepairRegistrar())->register($context);

		$this->assertSame([InitializeRegisterInterface::class => InitializeRegister::class], $aliases);
		$this->assertSame([InitializeActions::class], array_keys($factories));

		$actionAuth = new GenericActionAuthService(
			'integriq',
			$this->createMock(IAppConfig::class),
			$this->createMock(IGroupManager::class)
		);
		$asked = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($actionAuth, &$asked): object {
				$asked[] = $id;
				return match ($id) {
					GenericActionAuthService::class => $actionAuth,
					IAppManager::class => $this->createMock(IAppManager::class),
					LoggerInterface::class => $this->createMock(LoggerInterface::class),
				};
			}
		);

		$step = $factories[InitializeActions::class]($container);

		$this->assertInstanceOf(InitializeActions::class, $step);
		$this->assertSame('Initialize integriq action-authorization matrix (ADR-023)', $step->getName());
		$this->assertSame([GenericActionAuthService::class, IAppManager::class, LoggerInterface::class], $asked);
	}//end testRegistersTheInitializerAliasAndTheActionsFactory()
}//end class
