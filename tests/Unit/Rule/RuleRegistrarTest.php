<?php

/**
 * Tests for the Rule component's container wiring.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Rule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Rule;

use OCA\Integriq\Rule\Plugin\RegisterEndpointRulePluginsEvent;
use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\AvgBsnPolicyRuleInterface;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\CompositeFanoutRuleInterface;
use OCA\Integriq\Rule\Plugin\ConnectRelationsPlugin;
use OCA\Integriq\Rule\Plugin\EndpointRulePluginRegistry;
use OCA\Integriq\Rule\Plugin\EndpointRulePluginRegistryInterface;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Rule\ReferenceNumberRuleInterface;
use OCA\Integriq\Rule\RuleRegistrar;
use OCA\Integriq\Service\SoftwareCatalogueServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The registrar binds each rule contract and builds the plug-in registry
 * with integriq's own connectRelations plug-in.
 *
 * @covers \OCA\Integriq\Rule\RuleRegistrar
 * @uses   \OCA\Integriq\Rule\Plugin\EndpointRulePluginRegistry
 * @uses   \OCA\Integriq\Rule\Plugin\ConnectRelationsPlugin
 * @uses   \OCA\Integriq\Rule\Plugin\RegisterEndpointRulePluginsEvent
 */
class RuleRegistrarTest extends TestCase {
	private const EXPECTED = [
		EndpointRulePluginRegistryInterface::class => EndpointRulePluginRegistry::class,
		CompositeFanoutRuleInterface::class => CompositeFanoutRule::class,
		ReferenceNumberRuleInterface::class => ReferenceNumberRule::class,
		AvgBsnPolicyRuleInterface::class => AvgBsnPolicyRule::class,
	];

	/**
	 * Registered aliases, in order.
	 *
	 * @var array<string, string>
	 */
	private array $aliases = [];

	/**
	 * Registered service factories, keyed by service name.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = [];

	/**
	 * Run the registrar against a recording registration context.
	 *
	 * @return void
	 */
	private function registerAll(): void {
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			function (string $alias, string $target): void {
				$this->aliases[$alias] = $target;
			}
		);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory): void {
				$this->factories[$name] = $factory;
			}
		);

		(new RuleRegistrar())->register(context: $context);
	}

	/**
	 * Each rule contract is aliased to its implementation, in this order, and
	 * exactly one factory (the plug-in registry) is registered.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant, no requirement states which contracts exist
	 */
	public function testRegistersEachAliasAndTheRegistryFactory(): void {
		$this->registerAll();

		$this->assertSame(expected: self::EXPECTED, actual: $this->aliases);
		$this->assertSame(expected: [EndpointRulePluginRegistry::class], actual: array_keys($this->factories));
	}

	/**
	 * The registry factory builds a registry holding connectRelations, wired to
	 * the dispatcher so sibling apps are asked for theirs on first lookup.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant
	 */
	public function testRegistryFactoryBuildsRegistryWithConnectRelationsAndDispatcher(): void {
		$this->registerAll();

		$plugin = new ConnectRelationsPlugin(
			catalogueService: $this->createMock(originalClassName: SoftwareCatalogueServiceInterface::class)
		);
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')
			->with($this->isInstanceOf(RegisterEndpointRulePluginsEvent::class));

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[ConnectRelationsPlugin::class, $plugin],
				[IEventDispatcher::class, $dispatcher],
				['Psr\Log\LoggerInterface', $this->createMock(originalClassName: LoggerInterface::class)],
			]
		);

		$registry = ($this->factories[EndpointRulePluginRegistry::class])($container);

		$this->assertInstanceOf(expected: EndpointRulePluginRegistry::class, actual: $registry);
		$this->assertSame(expected: $plugin, actual: $registry->pluginFor(pluginId: ConnectRelationsPlugin::ID));
		$this->assertSame(expected: [ConnectRelationsPlugin::ID], actual: $registry->ids());
	}

	/**
	 * Every bound implementation implements its contract.
	 *
	 * @return void
	 *
	 * @spec exclude container wiring invariant
	 */
	public function testEachImplementationImplementsItsContract(): void {
		foreach (self::EXPECTED as $contract => $implementation) {
			$this->assertContains(needle: $contract, haystack: class_implements($implementation), message: $implementation);
		}
	}
}
