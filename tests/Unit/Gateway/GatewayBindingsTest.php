<?php

/**
 * The gateway bindings: the interface aliases and the registry built from the catalogue.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Gateway;

use OCA\Integriq\Gateway\Adapter\PropertyRestrictionGatewayInterface;
use OCA\Integriq\Gateway\Adapter\PublicationGateway;
use OCA\Integriq\Gateway\Adapter\PublicationGatewayInterface;
use OCA\Integriq\Gateway\Adapter\WkpbGateway;
use OCA\Integriq\Gateway\GatewayBindings;
use OCA\Integriq\Gateway\GatewayCatalogue;
use OCA\Integriq\Gateway\GatewayRegistry;
use OCA\Integriq\Gateway\GatewayRegistryInterface;
use OCA\Integriq\Gateway\GatewayTransport;
use OCA\Integriq\Gateway\SourceGatewayTransport;
use OCA\Integriq\Gateway\ZgwRegistryBinding;
use OCA\Integriq\Gateway\ZgwRegistryBindingInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Gateway\GatewayBindings
 * @uses   \OCA\Integriq\Gateway\GatewayCatalogue
 * @uses   \OCA\Integriq\Gateway\GatewayRegistry
 * @uses   \OCA\Integriq\Gateway\GatewayDescriptor
 */
class GatewayBindingsTest extends TestCase {

	/**
	 * Every gateway interface is bound to its implementation, and nothing else.
	 *
	 * @return void
	 */
	public function testEveryInterfaceIsBoundToItsImplementation(): void {
		$aliases = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			callback: static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		(new GatewayBindings())->register(context: $context);

		$this->assertSame(
			expected: [
				ZgwRegistryBindingInterface::class => ZgwRegistryBinding::class,
				GatewayRegistryInterface::class => GatewayRegistry::class,
				PublicationGatewayInterface::class => PublicationGateway::class,
				PropertyRestrictionGatewayInterface::class => WkpbGateway::class,
				GatewayTransport::class => SourceGatewayTransport::class,
			],
			actual: $aliases
		);
	}//end testEveryInterfaceIsBoundToItsImplementation()

	/**
	 * The registry service is built from the catalogue, so the catalogue page and the overview agree.
	 *
	 * @return void
	 */
	public function testTheRegistryIsBuiltFromTheCatalogue(): void {
		$factories = [];
		$context   = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->expects($this->once())->method('registerService')->willReturnCallback(
			callback: static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new GatewayBindings())->register(context: $context);

		$this->assertSame(expected: [GatewayRegistry::class], actual: array_keys($factories));
		$registry = $factories[GatewayRegistry::class](null);
		$this->assertInstanceOf(expected: GatewayRegistry::class, actual: $registry);

		$expected = array_map(static fn (array $entry): string => (string) $entry['id'], GatewayCatalogue::entries());
		$actual   = array_map(static fn ($descriptor): string => $descriptor->getId(), $registry->all());
		sort($expected);
		sort($actual);
		$this->assertNotSame(expected: [], actual: $expected);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testTheRegistryIsBuiltFromTheCatalogue()
}//end class
