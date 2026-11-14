<?php

/**
 * Unit tests for the file pickup branch of SynchronizationService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\FilePickup;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\FilePickupSynchronization;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileEntry;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A synchronization with an sftp or ftps source type runs as a file pickup.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */
class SynchronizationFilePickupTest extends TestCase {

	/**
	 * @var RemoteFileClient&MockObject
	 */
	private RemoteFileClient $client;

	/**
	 * @var CallService&MockObject
	 */
	private CallService $callService;

	/**
	 * The messages the run log was given.
	 *
	 * @var list<string|null>
	 */
	private array $messages = [];

	/**
	 * Build the service, with or without a pickup on the instance.
	 *
	 * @param boolean $withPickup Whether the container provides the pickup.
	 *
	 * @return SynchronizationService
	 */
	private function service(bool $withPickup = true): SynchronizationService {
		$source = new ObjectEntity();
		$source->setUuid('source-1');
		$source->setObject(['type' => 'sftp', 'location' => 'sftp://sftp.example.nl', 'isEnabled' => true]);
		$source->setOrganisation('org-a');

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('find')->willReturn($source);

		$this->client = $this->createMock(originalClassName: RemoteFileClient::class);
		$this->client->method('listFiles')->willReturn(
			[
				new RemoteFileEntry(name: 'besluiten-1.csv', path: 'besluiten-1.csv', size: 10, modifiedAt: 1000),
				new RemoteFileEntry(name: 'besluiten-2.csv', path: 'besluiten-2.csv', size: 20, modifiedAt: 1000),
			]
		);

		$contracts = $this->createMock(originalClassName: SynchronizationContractServiceInterface::class);
		$contracts->method('findAllObjects')->willReturn([]);

		$pickup = new FilePickupSynchronization(
			pickup: new FilePickup(client: $this->client, logger: $this->createMock(originalClassName: LoggerInterface::class)),
			client: $this->client,
			contracts: $contracts,
			rootFolder: $this->createMock(originalClassName: IRootFolder::class)
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): ?object => ($withPickup === true && $id === FilePickupSynchronization::class) ? $pickup : null
		);

		$logs = $this->createMock(originalClassName: SynchronizationLogService::class);
		$logs->method('createFromArray')->willReturnCallback(
			static fn (array $object): SynchronizationRunLog => (new SynchronizationRunLog())->hydrate($object)
		);
		$logs->method('update')->willReturnCallback(
			function (SynchronizationRunLog $log): SynchronizationRunLog {
				$this->messages[] = $log->getMessage();
				return $log;
			}
		);

		$this->callService = $this->createMock(originalClassName: CallService::class);
		$this->callService->method('applyConfigDot')->willReturnArgument(0);

		return new SynchronizationService(
			callService: $this->callService,
			mappingService: $this->createMock(originalClassName: MappingService::class),
			containerInterface: $container,
			orObjectService: $orObjectService,
			objectService: $this->createMock(originalClassName: ObjectService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			synchronizationLogService: $logs,
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			approvalGate: $this->createMock(originalClassName: SynchronizationApprovalGate::class)
		);

	}//end service()

	/**
	 * An sftp synchronization.
	 *
	 * @param array $self The `@self` block.
	 *
	 * @return array
	 */
	private function synchronization(array $self = ['organisation' => 'org-a', 'owner' => 'alice']): array {
		return [
			'id'           => 'sync-1',
			'sourceType'   => 'sftp',
			'sourceId'     => 'source-1',
			'targetType'   => 'register/schema',
			'@self'        => $self,
			'sourceConfig' => ['pattern' => 'besluiten-*.csv', 'after' => 'keep', 'filesFolder' => 'Partner/besluiten', 'stableSeconds' => 0],
		];

	}//end synchronization()

	/**
	 * A test run of a pickup lists the new files on the run log and calls no HTTP source.
	 *
	 * @return void
	 */
	public function testAPickupTestRunReportsTheNewFiles(): void {
		$service = $this->service();
		$this->client->expects($this->never())->method('fetch');
		$this->callService->expects($this->never())->method('call');

		$log = $service->synchronize(synchronization: $this->synchronization(), isTest: true);

		$this->assertSame(2, $log['result']['objects']['found']);
		$this->assertSame(0, $log['result']['objects']['created']);
		$this->assertSame(['besluiten-1.csv', 'besluiten-2.csv'], array_column($log['result']['files']['new'], 'name'));
		$this->assertArrayHasKey('file_pickup', $log['result']['timing']['stages']);

	}//end testAPickupTestRunReportsTheNewFiles()

	/**
	 * A pickup that cannot run stops the run with its reason on the run log.
	 *
	 * @return void
	 */
	public function testAPickupThatCannotRunStopsWithItsReason(): void {
		$service = $this->service();
		$this->client->expects($this->never())->method('fetch');

		try {
			$service->synchronize(synchronization: $this->synchronization(self: ['organisation' => 'org-a']));
			$this->fail('the pickup ran without an owner');
		} catch (\Exception $exception) {
			$this->assertStringContainsString('has no owner', $exception->getMessage());
		}

		$this->assertStringContainsString('has no owner', (string)end($this->messages));

	}//end testAPickupThatCannotRunStopsWithItsReason()

	/**
	 * Without the pickup on the instance, a pickup synchronization is refused rather than run as an HTTP one.
	 *
	 * @return void
	 */
	public function testWithoutThePickupTheRunIsRefused(): void {
		$service = $this->service(withPickup: false);
		$this->callService->expects($this->never())->method('call');

		$this->expectExceptionMessage('File pickup is not available on this instance.');
		$service->synchronize(synchronization: $this->synchronization(), isTest: true);

	}//end testWithoutThePickupTheRunIsRefused()

}//end class
