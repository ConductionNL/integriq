<?php

/**
 * Integriq — property-source container bindings tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\PropertySource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\PropertySource;

use OCA\Integriq\PropertySource\ListResyncService;
use OCA\Integriq\PropertySource\ListResyncServiceInterface;
use OCA\Integriq\PropertySource\PropertySourceBindings;
use OCA\Integriq\PropertySource\PropertySourceRegistry;
use OCA\Integriq\PropertySource\PropertySourceRegistryInterface;
use OCA\Integriq\PropertySource\PropertySourceResolver;
use OCA\Integriq\PropertySource\PropertySourceResolverInterface;
use OCA\Integriq\PropertySource\Provider\BagPropertySource;
use OCA\Integriq\PropertySource\Provider\BrpPropertySource;
use OCA\Integriq\PropertySource\Provider\KvkPropertySource;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The property-source wiring: the three interface aliases and a registry of BAG, BRP and KVK, in that order.
 *
 * @covers \OCA\Integriq\PropertySource\PropertySourceBindings
 * @uses   \OCA\Integriq\PropertySource\PropertySourceRegistry
 */
final class PropertySourceBindingsTest extends TestCase {

	/**
	 * The bindings alias each interface to its implementation.
	 *
	 * @return void
	 */
	public function testEachInterfaceIsAliasedToItsImplementation(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new PropertySourceBindings())->register($context);

		$this->assertSame(
			[
				ListResyncServiceInterface::class      => ListResyncService::class,
				PropertySourceResolverInterface::class => PropertySourceResolver::class,
				PropertySourceRegistryInterface::class => PropertySourceRegistry::class,
			],
			$aliases
		);
	}//end testEachInterfaceIsAliasedToItsImplementation()

	/**
	 * The registry factory registers BAG, BRP and KVK in that order, with the container's logger.
	 *
	 * @return void
	 */
	public function testTheRegistryHoldsBagBrpAndKvkInOrder(): void {
		$factories = [];
		$context   = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new PropertySourceBindings())->register($context);

		$this->assertSame([PropertySourceRegistry::class], array_keys($factories));

		$bag = $this->createMock(BagPropertySource::class);
		$bag->method('id')->willReturn('bag');
		$brp = $this->createMock(BrpPropertySource::class);
		$brp->method('id')->willReturn('brp');
		$kvk = $this->createMock(KvkPropertySource::class);
		$kvk->method('id')->willReturn('kvk');
		$logger    = $this->createMock(LoggerInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[BagPropertySource::class, $bag],
				[BrpPropertySource::class, $brp],
				[KvkPropertySource::class, $kvk],
				['Psr\Log\LoggerInterface', $logger],
			]
		);

		$registry = $factories[PropertySourceRegistry::class]($container);

		$this->assertInstanceOf(PropertySourceRegistry::class, $registry);
		$this->assertSame(['bag', 'brp', 'kvk'], $registry->ids());
		$this->assertSame($kvk, $registry->get('kvk'));
	}//end testTheRegistryHoldsBagBrpAndKvkInOrder()
}//end class
