<?php

/**
 * ServiceBindings factory unit tests
 *
 * The service bindings register factories for the provider registries and
 * the OCS clients, and on boot hand integriq's integration providers to
 * OpenRegister. These tests run every registered factory against a container
 * double and pin which providers each registry is built from, and that a boot
 * failure is logged rather than thrown.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Adapter\DataInfra\S3Adapter;
use OCA\Integriq\Service\Adapter\DocumentCms\SharePointOnlineAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\AzureVirtualDesktopAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\CitrixDaasAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\IntuneAdapter;
use OCA\Integriq\Service\Adapter\EndpointWorkspace\JamfProAdapter;
use OCA\Integriq\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\Integriq\Service\DigitalPost\BerichtenboxProvider;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\DigitalPost\LogDigitalPostProvider;
use OCA\Integriq\Service\DigitalPost\PostexProvider;
use OCA\Integriq\Service\Event\EventForwardingGuardInterface;
use OCA\Integriq\Service\Event\EventLoopGuard;
use OCA\Integriq\Service\Forms\FormsClientInterface;
use OCA\Integriq\Service\Forms\FormsOcsClient;
use OCA\Integriq\Service\Integration\SynchronizationContractProvider;
use OCA\Integriq\Service\Oso\LogOsoProvider;
use OCA\Integriq\Service\Oso\OsoKennisnetClient;
use OCA\Integriq\Service\Oso\OsoProviderRegistry;
use OCA\Integriq\Service\Registry\BrpVolgindicatieProvider;
use OCA\Integriq\Service\Registry\KvkMutatieProvider;
use OCA\Integriq\Service\Registry\LogSubscriptionProvider;
use OCA\Integriq\Service\Registry\SubscriptionRegistry;
use OCA\Integriq\Service\Rod\LogRodProvider;
use OCA\Integriq\Service\Rod\RodEdukoppelingClient;
use OCA\Integriq\Service\Rod\RodProviderRegistry;
use OCA\Integriq\Service\ServiceBindings;
use OCA\Integriq\Service\SettingsService;
use OCA\Integriq\Service\Tables\TablesClientInterface;
use OCA\Integriq\Service\Tables\TablesOcsClient;
use OCA\Integriq\Service\UwlrEduV\LogUwlrEduVProvider;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVKennisnetClient;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVProviderRegistry;
use OCA\Integriq\Service\Verzuimloket\LogVerzuimloketProvider;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketEdukoppelingClient;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketProviderRegistry;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the registered factories and the boot hand-off.
 *
 * @covers \OCA\Integriq\Service\ServiceBindings
 * @uses \OCA\Integriq\Service\Registry\SubscriptionRegistry
 * @uses \OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry
 * @uses \OCA\Integriq\Service\Rod\RodProviderRegistry
 * @uses \OCA\Integriq\Service\Verzuimloket\VerzuimloketProviderRegistry
 * @uses \OCA\Integriq\Service\Oso\OsoProviderRegistry
 * @uses \OCA\Integriq\Service\UwlrEduV\UwlrEduVProviderRegistry
 * @uses \OCA\Integriq\Service\SettingsService
 * @uses \OCA\Integriq\Service\Objecten\ObjectenWiring
 */
class ServiceBindingsFactoriesTest extends TestCase {

	/**
	 * The doubles the container handed out, by id.
	 *
	 * @var array<string, object>
	 */
	private array $resolved = [];

	public function testEachProviderRegistryIsBuiltFromItsProviders(): void {
		$factories = $this->factories();
		$container = $this->container();

		$subscriptions = $factories[SubscriptionRegistry::class]($container);
		$this->assertSame(
			[BrpVolgindicatieProvider::class, KvkMutatieProvider::class, LogSubscriptionProvider::class],
			array_map(fn (string $id): string => $this->classOf($subscriptions->get($id)), ['BrpVolgindicatieProvider', 'KvkMutatieProvider', 'LogSubscriptionProvider'])
		);

		$this->assertRegistryHolds($factories[DigitalPostProviderRegistry::class]($container), [BerichtenboxProvider::class, PostexProvider::class, LogDigitalPostProvider::class]);
		$this->assertRegistryHolds($factories[RodProviderRegistry::class]($container), [RodEdukoppelingClient::class, LogRodProvider::class]);
		$this->assertRegistryHolds($factories[VerzuimloketProviderRegistry::class]($container), [VerzuimloketEdukoppelingClient::class, LogVerzuimloketProvider::class]);
		$this->assertRegistryHolds($factories[OsoProviderRegistry::class]($container), [OsoKennisnetClient::class, LogOsoProvider::class]);
		$this->assertRegistryHolds($factories[UwlrEduVProviderRegistry::class]($container), [UwlrEduVKennisnetClient::class, LogUwlrEduVProvider::class]);
	}//end testEachProviderRegistryIsBuiltFromItsProviders()

	public function testTheOcsClientsResolveToTheirImplementations(): void {
		$factories = $this->factories();
		$container = $this->container();

		$this->assertSame($this->resolved[TablesOcsClient::class] ?? $container->get(TablesOcsClient::class), $factories[TablesClientInterface::class]($container));
		$this->assertSame($container->get(FormsOcsClient::class), $factories[FormsClientInterface::class]($container));
		$this->assertInstanceOf(EventLoopGuard::class, $factories[EventForwardingGuardInterface::class]());
	}//end testTheOcsClientsResolveToTheirImplementations()

	public function testTheSettingsServiceIsBuiltFromTheDatabaseConfigAndLogger(): void {
		$container = $this->container();

		$settings = $this->factories()[SettingsService::class]($container);

		$this->assertInstanceOf(SettingsService::class, $settings);
		foreach ([IDBConnection::class, IAppConfig::class, LoggerInterface::class] as $id) {
			$this->assertArrayHasKey($id, $this->resolved);
		}
	}//end testTheSettingsServiceIsBuiltFromTheDatabaseConfigAndLogger()

	public function testBootHandsTheEightIntegrationProvidersToOpenRegisterInOrder(): void {
		$registry = new class extends IntegrationRegistry {
			/** @var array<int, object> */
			public array $added = [];

			/**
			 * @param object $provider The provider.
			 *
			 * @return void
			 */
			public function addProvider(object $provider): void {
				$this->added[] = $provider;
			}
		};
		$this->resolved[IntegrationRegistry::class] = $registry;
		$context = $this->createMock(IBootContext::class);
		$context->method('getServerContainer')->willReturn($this->container());

		(new ServiceBindings())->boot(context: $context);

		$this->assertSame(
			[SynchronizationContractProvider::class, AzureVirtualDesktopAdapter::class, IntuneAdapter::class, JamfProAdapter::class, CitrixDaasAdapter::class, SharePointOnlineAdapter::class, Microsoft365Adapter::class, S3Adapter::class],
			array_map(fn (object $provider): string => $this->classOf($provider), $registry->added)
		);
	}//end testBootHandsTheEightIntegrationProvidersToOpenRegisterInOrder()

	public function testABootFailureIsLoggedNotThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('failed to register IntegrationProviders with OR — container broke'),
			$this->callback(static fn (array $context): bool => $context['exception'] instanceof RuntimeException)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($logger): object {
				if ($id === 'Psr\Log\LoggerInterface') {
					return $logger;
				}

				throw new RuntimeException('container broke');
			}
		);
		$context = $this->createMock(IBootContext::class);
		$context->method('getServerContainer')->willReturn($container);

		(new ServiceBindings())->boot(context: $context);
	}//end testABootFailureIsLoggedNotThrown()

	public function testABootFailureWithoutALoggerIsSwallowed(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no container'));
		$context = $this->createMock(IBootContext::class);
		$context->method('getServerContainer')->willReturn($container);

		(new ServiceBindings())->boot(context: $context);

		$this->addToAssertionCount(1);
	}//end testABootFailureWithoutALoggerIsSwallowed()

	/**
	 * Every factory the bindings register, by service id.
	 *
	 * @return array<string, callable>
	 */
	private function factories(): array {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new ServiceBindings())->register(context: $context);

		return $factories;
	}//end factories()

	/**
	 * A container handing out one double per id; providers answer to their short class name.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if (isset($this->resolved[$id]) === false) {
					$double = $this->createMock($id);
					$short = substr($id, (strrpos($id, '\\') + 1));
					foreach (['getProviderId', 'registryId'] as $method) {
						if (method_exists($id, $method) === true) {
							$double->method($method)->willReturn($short);
						}
					}

					$this->resolved[$id] = $double;
				}

				return $this->resolved[$id];
			}
		);

		return $container;
	}//end container()

	/**
	 * Assert a ProviderIndex-backed registry answers each provider by its id.
	 *
	 * @param object             $registry The registry.
	 * @param array<int, string> $classes  The provider classes it should hold.
	 *
	 * @return void
	 */
	private function assertRegistryHolds(object $registry, array $classes): void {
		foreach ($classes as $class) {
			$short = substr($class, (strrpos($class, '\\') + 1));
			$this->assertTrue($registry->has($short), $short);
			$this->assertSame($this->resolved[$class], $registry->get($short));
		}
	}//end assertRegistryHolds()

	/**
	 * The class a double stands in for.
	 *
	 * @param object $double The double.
	 *
	 * @return string
	 */
	private function classOf(object $double): string {
		return (string)array_search($double, $this->resolved, true);
	}//end classOf()
}//end class
