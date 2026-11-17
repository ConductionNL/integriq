<?php

/**
 * Integriq — property-source registrar tests.
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
use OCA\Integriq\PropertySource\PropertySourceRegistrar;
use OCA\Integriq\PropertySource\PropertySourceRegistry;
use OCA\Integriq\PropertySource\PropertySourceRegistryInterface;
use OCA\Integriq\PropertySource\PropertySourceResolver;
use OCA\Integriq\PropertySource\PropertySourceResolverInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\PropertySource\PropertySourceRegistrar
 * @uses   \OCA\Integriq\PropertySource\PropertySourceBindings
 */
final class PropertySourceRegistrarTest extends TestCase {

	/**
	 * The registrar hands the context to the component's bindings: the
	 * interfaces get their aliases and the registry gets its factory.
	 *
	 * @return void
	 */
	public function testTheRegistrarWiresTheComponentBindings(): void {
		$aliases  = [];
		$services = [];
		$context  = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$services): void {
				$services[] = $name;
			}
		);

		(new PropertySourceRegistrar())->register($context);

		$this->assertSame(
			[
				ListResyncServiceInterface::class      => ListResyncService::class,
				PropertySourceResolverInterface::class => PropertySourceResolver::class,
				PropertySourceRegistryInterface::class => PropertySourceRegistry::class,
			],
			$aliases
		);
		$this->assertSame([PropertySourceRegistry::class], $services);
	}//end testTheRegistrarWiresTheComponentBindings()
}//end class
