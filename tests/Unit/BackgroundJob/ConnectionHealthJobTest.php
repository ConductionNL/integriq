<?php

/**
 * Unit tests for ConnectionHealthJob (connection-registry, umbrella D7).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Integriq\BackgroundJob\ConnectionHealthJob;
use OCA\Integriq\Service\CatalogRegistryService;
use OCA\Integriq\Service\ConnectionDeclarationValidator;
use OCA\Integriq\Service\ConnectionProbeService;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCA\Integriq\Service\ConnectionStatusResolver;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\SourceTestService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The job runs its three phases in order, isolates failures, and runs hourly.
 */
class ConnectionHealthJobTest extends TestCase {

	/**
	 * Invoke the protected run().
	 *
	 * @param ConnectionHealthJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(ConnectionHealthJob $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * Build a container answering with the two services.
	 *
	 * @param ConnectionRegistryService $registry The registry double.
	 * @param ConnectionProbeService $probe The probe double.
	 *
	 * @return ContainerInterface
	 */
	private function container(ConnectionRegistryService $registry, ConnectionProbeService $probe): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($registry, $probe): object {
				if ($id === ConnectionProbeService::class) {
					return $probe;
				}

				return $registry;
			}
		);

		return $container;
	}//end container()

	/**
	 * The interval is 3600 seconds.
	 *
	 * @return void
	 */
	public function testRunsHourly(): void {
		$job = new ConnectionHealthJob(
			time: $this->createMock(originalClassName: ITimeFactory::class),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$interval = new \ReflectionProperty(\OCP\BackgroundJob\TimedJob::class, 'interval');
		$this->assertSame(expected: 3600, actual: $interval->getValue($job));
	}//end testRunsHourly()

	/**
	 * The job syncs moved declarations, probes with the cap of 25, then resolves every row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
	 */
	public function testRunsAllPhasesWithTheCap(): void {
		$calls = [];
		$registry = $this->getMockBuilder(className: ConnectionRegistryService::class)->disableOriginalConstructor()
			->onlyMethods(['syncChangedDeclarations', 'refresh'])->getMock();
		$registry->expects($this->once())->method('syncChangedDeclarations')->willReturnCallback(
			static function () use (&$calls): array {
				$calls[] = 'sync';
				return [];
			}
		);
		$registry->expects($this->once())->method('refresh')->with(null, null)->willReturnCallback(
			static function () use (&$calls): int {
				$calls[] = 'refresh';
				return 0;
			}
		);

		$probe = $this->getMockBuilder(className: ConnectionProbeService::class)->disableOriginalConstructor()
			->onlyMethods(['probeDue'])->getMock();
		$probe->expects($this->once())->method('probeDue')->with(25)->willReturnCallback(
			static function () use (&$calls): int {
				$calls[] = 'probes';
				return 0;
			}
		);

		$this->runJob(
			job: new ConnectionHealthJob(
				time: $this->createMock(originalClassName: ITimeFactory::class),
				container: $this->container(registry: $registry, probe: $probe),
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			)
		);

		$this->assertSame(expected: ['sync', 'probes', 'refresh'], actual: $calls);
	}//end testRunsAllPhasesWithTheCap()

	/**
	 * Unlinked rows are resolved after the probes, with no source test and no cap.
	 *
	 * Real registry and probe services run over an in-memory store. The only
	 * outbound seam, SourceTestService, must not be called: none of the 30 rows
	 * has a source, and each one had a key set with occ since its last resolve.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-a-key-set-with-occ-shows-within-the-hour
	 */
	public function testResolvesUnlinkedRowsWithoutAnyCall(): void {
		$rows = [];
		for ($index = 0; $index < 30; $index++) {
			$rows['uuid-' . $index] = [
				'app' => 'dossiq',
				'key' => 'conn-' . $index,
				'title' => 'Connection ' . $index,
				'declaration' => ['key' => 'conn-' . $index, 'title' => 'Connection ' . $index, 'requiredConfig' => ['register']],
				'declaredVersion' => '1.0.0',
				'status' => 'unconfigured',
				'statusMessage' => 'Not checked yet.',
				'checkedAt' => null,
			];
		}

		$store = $this->getMockBuilder(className: ConnectionStore::class)->disableOriginalConstructor()
			->onlyMethods(['findRows', 'save', 'delete', 'findSource'])->getMock();
		$store->method('findRows')->willReturnCallback(
			static function () use (&$rows): array {
				$found = [];
				foreach ($rows as $uuid => $data) {
					$found[] = ['uuid' => $uuid, 'data' => $data];
				}

				return $found;
			}
		);
		$store->method('save')->willReturnCallback(
			static function (array $data, ?string $uuid = null) use (&$rows): string {
				$rows[(string)$uuid] = $data;
				return (string)$uuid;
			}
		);
		$store->expects($this->never())->method('delete');
		$store->expects($this->never())->method('findSource');

		$sourceTest = $this->getMockBuilder(className: SourceTestService::class)->disableOriginalConstructor()
			->onlyMethods(['run'])->getMock();
		$sourceTest->expects($this->never())->method('run');

		[$registry, $probe] = $this->realServices(store: $store, sourceTest: $sourceTest);

		$this->runJob(
			job: new ConnectionHealthJob(
				time: $this->createMock(originalClassName: ITimeFactory::class),
				container: $this->container(registry: $registry, probe: $probe),
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			)
		);

		$this->assertCount(expectedCount: 30, haystack: $rows);
		foreach ($rows as $uuid => $data) {
			$this->assertSame(expected: 'configured', actual: $data['status'], message: $uuid);
		}
	}//end testResolvesUnlinkedRowsWithoutAnyCall()

	/**
	 * A real registry and probe service over a store and a source test double.
	 *
	 * The app config has `register` filled, as `occ config:app:set` would leave it.
	 *
	 * @param ConnectionStore $store The store double.
	 * @param SourceTestService $sourceTest The source test double.
	 *
	 * @return array{0:ConnectionRegistryService,1:ConnectionProbeService}
	 */
	private function realServices(ConnectionStore $store, SourceTestService $sourceTest): array {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap(
			[
				['dossiq', 'register', '', true, 'dossiq'],
			]
		);
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-09-14T12:00:00+00:00'));
		$resolver = new ConnectionStatusResolver(appConfig: $appConfig, timeFactory: $time);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn([]);
		$appManager->method('isEnabledForAnyone')->willReturn(true);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$registry = new ConnectionRegistryService(
			appManager: $appManager,
			validator: new ConnectionDeclarationValidator(),
			resolver: $resolver,
			store: $store,
			logger: $logger
		);
		$probe = new ConnectionProbeService(
			store: $store,
			registry: $registry,
			resolver: $resolver,
			sourceTest: $sourceTest,
			catalog: $this->createMock(originalClassName: CatalogRegistryService::class),
			logger: $logger
		);

		return [$registry, $probe];
	}//end realServices()

	/**
	 * A failing sync is logged and the probes still run.
	 *
	 * @return void
	 */
	public function testFailingPhaseDoesNotStopTheNext(): void {
		$registry = $this->getMockBuilder(className: ConnectionRegistryService::class)->disableOriginalConstructor()
			->onlyMethods(['syncChangedDeclarations', 'refresh'])->getMock();
		$registry->method('syncChangedDeclarations')->willThrowException(new \RuntimeException('file system gone'));
		$registry->method('refresh')->willThrowException(new \RuntimeException('database gone'));

		$probe = $this->getMockBuilder(className: ConnectionProbeService::class)->disableOriginalConstructor()
			->onlyMethods(['probeDue'])->getMock();
		$probe->expects($this->once())->method('probeDue')->willReturn(3);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->exactly(count: 2))->method('error');

		$this->runJob(
			job: new ConnectionHealthJob(
				time: $this->createMock(originalClassName: ITimeFactory::class),
				container: $this->container(registry: $registry, probe: $probe),
				logger: $logger
			)
		);
	}//end testFailingPhaseDoesNotStopTheNext()
}//end class
