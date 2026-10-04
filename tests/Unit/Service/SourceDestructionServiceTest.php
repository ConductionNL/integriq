<?php

/**
 * A destruction notice purges one object without a full run.
 *
 * A ZGW `destroy` notification, or a signed call to the destroyed route,
 * resolves the one contract whose `originId` names the destroyed record.
 * With `onSourceDestroyed: purge` that object is purged at once; otherwise
 * the synchronization's disappearance policy is applied to that one object.
 * An object without a contract is never touched.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SourceDestructionService;
use OCA\Integriq\Service\SynchronizationContractLogService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The notice path through the real SourceDestructionService and SynchronizationService.
 */
class SourceDestructionServiceTest extends TestCase {
	private const SYNC_ID = 'sync-uuid-woo-publications';

	private const SOURCE_ID = 'source-uuid-dms';

	private const DRC = 'https://drc.example.nl/api/v1/enkelvoudiginformatieobjecten/';

	/**
	 * Every deleteObject() call: uuid and permanent.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $deletes = [];

	/**
	 * Every object (not contract) the engine saved.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $savedObjects = [];

	/**
	 * Every contract the engine persisted.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $contracts = [];

	/**
	 * Every contract log entry the engine wrote.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $contractLogs = [];

	/**
	 * Every findAll() filter set, so the test can see what was looked up.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $lookups = [];

	/**
	 * The service under test on one synchronization with three synchronized documents.
	 *
	 * @param array $sourceConfig The synchronization's sourceConfig.
	 *
	 * @return SourceDestructionService
	 */
	private function service(array $sourceConfig): SourceDestructionService {
		$synchronization = [
			'sourceId' => self::SOURCE_ID,
			'sourceType' => 'api',
			'targetType' => 'register/schema',
			'targetId' => '1/2',
			'sourceConfig' => $sourceConfig,
		];
		$syncEntity = ObjectServiceMockBuilder::objectEntity($this, $synchronization, self::SYNC_ID);
		$otherSync = ObjectServiceMockBuilder::objectEntity($this, ['sourceId' => 'another-source'] + $synchronization, 'sync-uuid-other');

		$contracts = [];
		$objects = [self::SYNC_ID => $syncEntity];
		foreach (['doc-1' => 'pub-1', 'doc-2' => 'pub-2', 'doc-3' => 'pub-3', self::DRC . 'doc-4' => 'pub-4'] as $docId => $targetId) {
			$contracts[] = ObjectServiceMockBuilder::objectEntity(
				$this,
				[
					'synchronizationId' => self::SYNC_ID,
					'originId' => $docId,
					'targetId' => $targetId,
					'targetLastAction' => 'create',
				],
				'contract-' . $docId
			);
			$objects[$targetId] = ObjectServiceMockBuilder::objectEntity($this, ['title' => 'Besluit ' . $targetId], $targetId);
		}

		$contracts[] = ObjectServiceMockBuilder::objectEntity(
			$this,
			['synchronizationId' => 'sync-uuid-other', 'originId' => 'doc-9', 'targetId' => 'pub-9', 'targetLastAction' => 'create'],
			'contract-doc-9'
		);

		$orObjectService = $this->getMockBuilder(OrObjectService::class)->disableOriginalConstructor()->getMock();
		// The mock answers every lookup with every row of the schema, as an
		// OpenRegister that ignores a filter would: the service must still
		// pick only the row the notice names.
		$orObjectService->method('findAll')->willReturnCallback(
			function (array $config) use ($contracts, $syncEntity, $otherSync): array {
				$this->lookups[] = ($config['filters'] ?? []);
				if (($config['filters']['schema'] ?? null) === 'synchronization') {
					return ['results' => [$syncEntity, $otherSync]];
				}

				return ['results' => $contracts];
			}
		);
		$orObjectService->method('find')->willReturnCallback(fn (...$args) => ($objects[(string)$args[0]] ?? null));
		$orObjectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				if (in_array('synchronization_contract', $args, true) === true) {
					$this->contracts[] = $args[0];
				} else {
					$this->savedObjects[] = $args[0];
				}

				return ObjectServiceMockBuilder::objectEntity($this, (array)$args[0], 'saved');
			}
		);
		$orObjectService->method('deleteObject')->willReturnCallback(
			function (...$args): bool {
				// By position: 0 is the uuid, 7 is `permanent` (openregister 98a3469c0f).
				$this->deletes[] = ['uuid' => $args[0], 'permanent' => ($args[7] ?? false)];
				return true;
			}
		);

		$contractLogService = $this->createMock(SynchronizationContractLogService::class);
		$contractLogService->method('createFromArray')->willReturnCallback(
			function (array $object): array {
				$this->contractLogs[] = $object;
				return $object;
			}
		);

		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);
		// A notice never runs the synchronization: the source is never called.
		$callService->expects($this->never())->method('call');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				IEventDispatcher::class => $this->createMock(IEventDispatcher::class),
				SynchronizationContractLogService::class => $contractLogService,
				default => null,
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$engine = new SynchronizationService(
			$callService,
			$this->createMock(MappingService::class),
			$container,
			$orObjectService,
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			new SynchronizationLogService(ObjectServiceMockBuilder::make($this), $this->createMock(\OCP\IUserSession::class), $this->createMock(\OCP\ISession::class)),
			$appConfig,
			$this->createMock(\OCA\Integriq\Service\SynchronizationApprovalGate::class),
		);

		return new SourceDestructionService($orObjectService, $engine, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A ZGW destroy for a synchronized document purges only that object, at once, and records the notice.
	 *
	 * @return void
	 */
	public function testAZgwDestroyPurgesOnlyThatObject(): void {
		$outcomes = $this->service(['onSourceDestroyed' => 'purge'])->handleZgwDestroyed(
			sourceId: self::SOURCE_ID,
			resourceUrl: self::DRC . 'doc-2'
		);

		$this->assertSame([['uuid' => 'pub-2', 'permanent' => true]], $this->deletes, 'Only the destroyed document\'s object goes, permanently.');
		$this->assertCount(1, $outcomes, 'One synchronization of this source held the document.');
		$this->assertSame('purged', $outcomes[0]['outcome']);
		$this->assertSame(self::SYNC_ID, $outcomes[0]['synchronizationId']);
		$this->assertSame('pub-2', $outcomes[0]['targetId']);

		$this->assertCount(1, $this->contractLogs, 'The purge is on the contract log.');
		$this->assertSame('purged', $this->contractLogs[0]['targetResult']);
		$this->assertSame('doc-2', $this->contractLogs[0]['source']['originId']);
		$this->assertSame('destructionNotice', $this->contractLogs[0]['source']['trigger']);
		$this->assertSame(self::DRC . 'doc-2', $this->contractLogs[0]['source']['reference']);

		$kept = end($this->contracts);
		$this->assertSame('doc-2', $kept['originId']);
		$this->assertNull($kept['targetId']);
		$this->assertSame('purge', $kept['targetLastAction']);

		$this->assertContains(['register' => 'integriq', 'schema' => 'synchronization', 'sourceId' => self::SOURCE_ID], $this->lookups, 'The synchronizations are those of the notice\'s source.');
	}//end testAZgwDestroyPurgesOnlyThatObject()

	/**
	 * A contract whose originId is the full resource URL is found too.
	 *
	 * @return void
	 */
	public function testAContractKeyedOnTheFullUrlIsFound(): void {
		$outcomes = $this->service(['onSourceDestroyed' => 'purge'])->handleZgwDestroyed(
			sourceId: self::SOURCE_ID,
			resourceUrl: self::DRC . 'doc-4'
		);

		$this->assertSame('purged', $outcomes[0]['outcome']);
		$this->assertSame([['uuid' => 'pub-4', 'permanent' => true]], $this->deletes);
	}//end testAContractKeyedOnTheFullUrlIsFound()

	/**
	 * The destroyed route names the record by its id; the id is the reference when none is given.
	 *
	 * @return void
	 */
	public function testTheDestroyedRouteNamesTheRecordById(): void {
		$outcome = $this->service(['onSourceDestroyed' => 'purge'])->handleDestroyed(
			synchronizationId: self::SYNC_ID,
			originId: 'doc-3',
			reference: null
		);

		$this->assertSame('purged', $outcome['outcome']);
		$this->assertSame([['uuid' => 'pub-3', 'permanent' => true]], $this->deletes);
		$this->assertSame('doc-3', $this->contractLogs[0]['source']['reference'], 'Without a reference the record id is the reference.');
	}//end testTheDestroyedRouteNamesTheRecordById()

	/**
	 * Without onSourceDestroyed the disappearance policy is applied to that one object: a soft delete by default.
	 *
	 * @return void
	 */
	public function testWithoutOnSourceDestroyedTheDisappearancePolicyApplies(): void {
		$outcome = $this->service([])->handleDestroyed(synchronizationId: self::SYNC_ID, originId: 'doc-1', reference: null);

		$this->assertSame('deleted', $outcome['outcome']);
		$this->assertSame([['uuid' => 'pub-1', 'permanent' => false]], $this->deletes, 'The default policy is a soft delete of that one object.');
		$this->assertSame([], $this->contractLogs, 'A soft delete is not a purge.');
		$kept = end($this->contracts);
		$this->assertSame('delete', $kept['targetLastAction']);
	}//end testWithoutOnSourceDestroyedTheDisappearancePolicyApplies()

	/**
	 * A disappearance policy of purge purges on a notice too.
	 *
	 * @return void
	 */
	public function testAPurgePolicyPurgesOnANotice(): void {
		$outcome = $this->service(['disappearancePolicy' => 'purge'])->handleDestroyed(synchronizationId: self::SYNC_ID, originId: 'doc-1', reference: 'ref-1');

		$this->assertSame('purged', $outcome['outcome']);
		$this->assertSame([['uuid' => 'pub-1', 'permanent' => true]], $this->deletes);
	}//end testAPurgePolicyPurgesOnANotice()

	/**
	 * Under keepAndFlag the object stays and is flagged.
	 *
	 * @return void
	 */
	public function testKeepAndFlagKeepsTheObject(): void {
		$outcome = $this->service(['disappearancePolicy' => 'keepAndFlag'])->handleDestroyed(synchronizationId: self::SYNC_ID, originId: 'doc-1', reference: null);

		$this->assertSame('flagged', $outcome['outcome']);
		$this->assertSame([], $this->deletes, 'Nothing is deleted.');
		$this->assertCount(1, $this->savedObjects, 'The object is saved with its flag.');
	}//end testKeepAndFlagKeepsTheObject()

	/**
	 * A notice for a record without a contract touches nothing.
	 *
	 * @return void
	 */
	public function testANoticeWithoutAContractTouchesNothing(): void {
		$service = $this->service(['onSourceDestroyed' => 'purge']);

		$this->assertSame([], $service->handleZgwDestroyed(sourceId: self::SOURCE_ID, resourceUrl: self::DRC . 'doc-unknown'));
		// doc-9 is synchronized, but by another source's synchronization.
		$this->assertSame([], $service->handleZgwDestroyed(sourceId: self::SOURCE_ID, resourceUrl: self::DRC . 'doc-9'));
		$this->assertSame('no_contract', $service->handleDestroyed(synchronizationId: self::SYNC_ID, originId: 'doc-9', reference: null)['outcome']);

		$this->assertSame([], $this->deletes);
		$this->assertSame([], $this->contracts);
		$this->assertSame([], $this->contractLogs);
	}//end testANoticeWithoutAContractTouchesNothing()

	/**
	 * An unknown synchronization touches nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownSynchronizationTouchesNothing(): void {
		$outcome = $this->service(['onSourceDestroyed' => 'purge'])->handleDestroyed(synchronizationId: 'nope', originId: 'doc-1', reference: null);

		$this->assertSame('no_synchronization', $outcome['outcome']);
		$this->assertSame([], $this->deletes);
	}//end testAnUnknownSynchronizationTouchesNothing()
}//end class
