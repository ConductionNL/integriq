<?php

/**
 * A synchronization on an SFTP or FTPS source runs the pickup, not the object pipeline.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\FilePickupService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\IAppConfig;
use OCP\ISession;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class SynchronizationFilePickupDispatchTest extends TestCase {

	/**
	 * Run logs written.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $logs = [];

	/**
	 * synchronize() hands an sftp synchronization and its source to the pickup and logs the run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function testAnSftpSynchronizationRunsThePickup(): void {
		$orObjects = ObjectServiceMockBuilder::make($this);
		$orObjects->method('find')->willReturnCallback(
			fn ($id, $register = null, $schema = null) => ObjectServiceMockBuilder::objectEntity(
				$this,
				['type' => 'sftp', 'location' => 'sftp.example.nl', 'schemaAsked' => $schema],
				(string)$id
			)
		);
		// The object pipeline would list contracts and objects; the pickup must not.
		$orObjects->expects($this->never())->method('findAll');

		$result = ['pickup' => ['listed' => 3, 'fetched' => 3, 'stored' => 3, 'archived' => 3, 'skipped' => 0, 'wouldFetch' => [], 'failed' => []], 'objects' => ['found' => 0, 'created' => 0]];
		$pickup = $this->createMock(FilePickupService::class);
		$pickup->expects($this->once())->method('run')->with(
			$this->callback(static fn (array $sync): bool => $sync['sourceType'] === 'sftp'),
			$this->callback(static fn (array $source): bool => $source['id'] === 'source-uuid-1' && $source['schemaAsked'] === 'source'),
			false
		)->willReturn($result);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === FilePickupService::class ? $pickup : null));

		$callService = $this->createMock(CallService::class);
		$callService->expects($this->never())->method('call');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$logOrService = ObjectServiceMockBuilder::make($this);
		$logOrService->method('saveObject')->willReturnCallback(
			function ($object, ...$rest) {
				$this->logs[] = (array)$object;
				return ObjectServiceMockBuilder::objectEntity($this, (array)$object, 'log-uuid');
			}
		);

		$service = new SynchronizationService(
			$callService,
			$this->createMock(MappingService::class),
			$container,
			$orObjects,
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			new SynchronizationLogService($logOrService, $this->createMock(IUserSession::class), $this->createMock(ISession::class)),
			$appConfig,
			$this->createMock(SynchronizationApprovalGate::class)
		);

		$returned = $service->synchronize(
			synchronization: ['id' => 'sync-1', 'sourceId' => 'source-uuid-1', 'sourceType' => 'sftp', 'sourceConfig' => ['pattern' => '*.csv']]
		);

		$this->assertSame($result, $returned);
		$this->assertCount(1, $this->logs);
		$this->assertSame('filePickup', $this->logs[0]['result']['type']);
		$this->assertSame(3, $this->logs[0]['result']['pickup']['stored']);
	}//end testAnSftpSynchronizationRunsThePickup()
}//end class
