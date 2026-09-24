<?php

/**
 * Unit tests for the connection-registry listeners (umbrella D5 and D6).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-apps-report-and-refresh-through-two-typed-events-req-conn-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Integriq\EventListener\ConnectionAppLifecycleListener;
use OCA\Integriq\EventListener\ConnectionRefreshRequestedListener;
use OCA\Integriq\EventListener\ConnectionStatusReportedListener;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCP\App\Events\AppDisableEvent;
use OCP\App\Events\AppEnableEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The listeners pass events on, and never throw into the sender.
 */
class ConnectionEventListenersTest extends TestCase {

	/**
	 * A registry service double.
	 *
	 * @return ConnectionRegistryService&MockObject
	 */
	private function registry(): ConnectionRegistryService {
		return $this->getMockBuilder(className: ConnectionRegistryService::class)
			->disableOriginalConstructor()
			->onlyMethods(['report', 'refresh', 'refreshRequested', 'sync'])
			->getMock();
	}//end registry()

	/**
	 * A container that answers with the given service, or throws.
	 *
	 * @param ConnectionRegistryService|null $registry The service, or null to throw like a missing OpenRegister.
	 *
	 * @return ContainerInterface
	 */
	private function container(?ConnectionRegistryService $registry): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($registry === null) {
			$container->method('get')->willThrowException(new \RuntimeException('OpenRegister is not installed'));
			return $container;
		}

		$container->method('get')->with(ConnectionRegistryService::class)->willReturn($registry);
		return $container;
	}//end container()

	/**
	 * A report reaches the registry with every field.
	 *
	 * @return void
	 */
	public function testReportIsPassedOn(): void {
		$registry = $this->registry();
		$registry->expects($this->once())->method('report')
			->with('dossiq', 'mailbox', 'configured', 'Logged in to imap.example.nl')
			->willReturn(true);

		$listener = new ConnectionStatusReportedListener(
			container: $this->container(registry: $registry),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$listener->handle(new ConnectionStatusReportedEvent(app: 'dossiq', key: 'mailbox', status: 'configured', message: 'Logged in to imap.example.nl'));
	}//end testReportIsPassedOn()

	/**
	 * An unknown key is refused by the registry and the listener returns normally.
	 *
	 * @return void
	 */
	public function testUnknownKeyReturnsNormally(): void {
		$registry = $this->registry();
		$registry->expects($this->once())->method('report')->willReturn(false);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$listener = new ConnectionStatusReportedListener(container: $this->container(registry: $registry), logger: $logger);
		$listener->handle(new ConnectionStatusReportedEvent(app: 'dossiq', key: 'never-declared', status: 'configured'));
	}//end testUnknownKeyReturnsNormally()

	/**
	 * A failing write is logged and not thrown into the sender.
	 *
	 * @return void
	 */
	public function testReportFailureDoesNotThrow(): void {
		$registry = $this->registry();
		$registry->method('report')->willThrowException(new \RuntimeException('database gone'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$listener = new ConnectionStatusReportedListener(container: $this->container(registry: $registry), logger: $logger);
		$listener->handle(new ConnectionStatusReportedEvent(app: 'dossiq', key: 'mailbox', status: 'error', message: 'Login failed'));
	}//end testReportFailureDoesNotThrow()

	/**
	 * A missing OpenRegister costs a log line, not an exception.
	 *
	 * @return void
	 */
	public function testMissingServiceDoesNotThrow(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->exactly(count: 4))->method('error');

		$reported = new ConnectionStatusReportedListener(container: $this->container(registry: null), logger: $logger);
		$reported->handle(new ConnectionStatusReportedEvent(app: 'dossiq', key: 'mailbox', status: 'error'));
		$refresh = new ConnectionRefreshRequestedListener(container: $this->container(registry: null), logger: $logger);
		$refresh->handle(new ConnectionRefreshRequestedEvent(app: 'dossiq'));
		$lifecycle = new ConnectionAppLifecycleListener(container: $this->container(registry: null), logger: $logger);
		$lifecycle->handle(new AppEnableEvent('dossiq'));
		$lifecycle->handle(new AppDisableEvent('dossiq'));
	}//end testMissingServiceDoesNotThrow()

	/**
	 * Listeners ignore events of another type.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		(new ConnectionStatusReportedListener(container: $container, logger: $logger))->handle(new Event());
		(new ConnectionRefreshRequestedListener(container: $container, logger: $logger))->handle(new Event());
		(new ConnectionAppLifecycleListener(container: $container, logger: $logger))->handle(new Event());
	}//end testOtherEventsAreIgnored()

	/**
	 * A refresh request with the key reaches the stamping path with that key, not the plain resolve.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-save-retires-an-older-error
	 */
	public function testRefreshCarriesTheKey(): void {
		$registry = $this->registry();
		$registry->expects($this->once())->method('refreshRequested')->with('dossiq', 'berichtenbox')->willReturn(1);
		$registry->expects($this->never())->method('refresh');

		$listener = new ConnectionRefreshRequestedListener(
			container: $this->container(registry: $registry),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$listener->handle(new ConnectionRefreshRequestedEvent(app: 'dossiq', key: 'berichtenbox'));
	}//end testRefreshCarriesTheKey()

	/**
	 * A refresh request without a key reaches the stamping path with a null key.
	 *
	 * @return void
	 */
	public function testRefreshWithoutKeyCarriesNull(): void {
		$registry = $this->registry();
		$registry->expects($this->once())->method('refreshRequested')->with('dossiq', null)->willReturn(2);

		$listener = new ConnectionRefreshRequestedListener(
			container: $this->container(registry: $registry),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$listener->handle(new ConnectionRefreshRequestedEvent(app: 'dossiq'));
	}//end testRefreshWithoutKeyCarriesNull()

	/**
	 * Enabling an app syncs that app; enabling integriq syncs every app.
	 *
	 * @return void
	 */
	public function testAppEnableSyncs(): void {
		$synced = [];
		$registry = $this->registry();
		$registry->expects($this->exactly(count: 2))->method('sync')->willReturnCallback(
			static function (?string $app = null) use (&$synced): array {
				$synced[] = $app;
				return ['created' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0, 'skipped' => []];
			}
		);

		$listener = new ConnectionAppLifecycleListener(
			container: $this->container(registry: $registry),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$listener->handle(new AppEnableEvent('dossiq'));
		$listener->handle(new AppEnableEvent('integriq'));

		$this->assertSame(expected: ['dossiq', null], actual: $synced);
	}//end testAppEnableSyncs()

	/**
	 * Disabling an app resolves its rows again.
	 *
	 * @return void
	 */
	public function testAppDisableRefreshes(): void {
		$registry = $this->registry();
		$registry->expects($this->once())->method('refresh')->with('dossiq')->willReturn(3);
		$registry->expects($this->never())->method('sync');

		$listener = new ConnectionAppLifecycleListener(
			container: $this->container(registry: $registry),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
		$listener->handle(new AppDisableEvent('dossiq'));
	}//end testAppDisableRefreshes()
}//end class
