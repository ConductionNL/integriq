<?php

/**
 * A synchronization can purge a vanished record and its files.
 *
 * Under `disappearancePolicy: purge` a target object whose source record is
 * gone is deleted permanently on a complete full run, with every guard a
 * delete has; each purge is written to the contract log, the contract is kept
 * with `targetLastAction: purge`, and a purge OpenRegister refuses leaves the
 * object as it was, never soft deleted.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-synchronization-can-purge-a-vanished-record-and-its-files-req-sdp-001
 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-every-purge-is-recorded-and-a-refused-purge-stays-visible-req-sdp-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\Ownership\DisappearancePolicy;
use OCA\Integriq\Service\SynchronizationContractLogService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The purge policy through the real deleteInvalidObjects() and updateTarget().
 */
class SynchronizationServicePurgeTest extends TestCase {
	private const SYNC_ID = 'sync-uuid-woo-publications';

	/**
	 * Every deleteObject() call, with its named arguments.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $deletes = [];

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
	 * The service under test, built on the given contracts.
	 *
	 * @param list<string> $targetIds  One contract per target id; origin id `doc-<target id>`.
	 * @param string|null  $refuseUuid A target whose permanent delete OpenRegister refuses.
	 *
	 * @return SynchronizationService
	 */
	private function service(array $targetIds, ?string $refuseUuid = null): SynchronizationService {
		$contracts = [];
		$objects = [];
		foreach ($targetIds as $i => $targetId) {
			$contracts[] = ObjectServiceMockBuilder::objectEntity(
				$this,
				[
					'synchronizationId' => self::SYNC_ID,
					'originId' => 'doc-' . $targetId,
					'targetId' => $targetId,
					'targetLastAction' => 'create',
				],
				'contract-uuid-' . $i
			);
			$objects[$targetId] = ObjectServiceMockBuilder::objectEntity($this, ['title' => 'Besluit ' . $targetId], $targetId);
		}

		$orObjectService = $this->getMockBuilder(OrObjectService::class)->disableOriginalConstructor()->getMock();
		$orObjectService->method('findAll')->willReturn(['results' => $contracts, 'total' => count($contracts)]);
		$objects['source-uuid-woo'] = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://dms.example.nl', 'enabled' => true], 'source-uuid-woo');
		$orObjectService->method('find')->willReturnCallback(fn (...$args) => ($objects[(string)$args[0]] ?? null));
		$orObjectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				// A mock callback receives its arguments by position; the
				// schema is looked up by value so the stub's and OpenRegister's
				// parameter orders both work.
				if (in_array('synchronization_contract', $args, true) === true) {
					$this->contracts[] = $args[0];
				}

				return ObjectServiceMockBuilder::objectEntity($this, (array)$args[0], 'saved');
			}
		);
		$orObjectService->method('deleteObject')->willReturnCallback(
			function (...$args) use ($refuseUuid): bool {
				// By position: 0 is the uuid, 7 is `permanent` (openregister 98a3469c0f).
				$args = ['uuid' => $args[0], 'permanent' => ($args[7] ?? false)];
				if ($refuseUuid !== null && $args['uuid'] === $refuseUuid) {
					throw new ReferentialIntegrityException(new DeletionAnalysis(deletable: false, blockers: [['uuid' => 'restricting-object']]));
				}

				$this->deletes[] = $args;
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

		// The source answers one complete page that no longer lists anything.
		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);
		$callService->method('call')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['response' => ['statusCode' => 200, 'body' => json_encode(['items' => []]), 'encoding' => 'UTF-8', 'headers' => []]],
				'call-log-empty-page'
			)
		);

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

		return new SynchronizationService(
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
	}//end service()

	/**
	 * Run the cleanup with the source still carrying only the given targets.
	 *
	 * @param SynchronizationService $service    The service.
	 * @param list<string>           $stillThere The target ids the source still carries.
	 * @param bool                   $complete   Whether the fetch was complete.
	 * @param string                 $syncMode   full or incremental.
	 *
	 * @return array{deleted: int, guard: array|null}
	 */
	private function cleanUp(SynchronizationService $service, array $stillThere, bool $complete = true, string $syncMode = 'full'): array {
		$guardInfo = null;
		$deleted = $service->deleteInvalidObjects(
			synchronization: [
				'id' => self::SYNC_ID,
				'uuid' => self::SYNC_ID,
				'targetType' => 'register/schema',
				'targetId' => '1/2',
				'syncMode' => $syncMode,
				'sourceConfig' => ['disappearancePolicy' => 'purge'],
			],
			synchronizedTargetIds: $stillThere,
			fetchComplete: $complete,
			guardInfo: $guardInfo
		);

		return ['deleted' => $deleted, 'guard' => $guardInfo];
	}//end cleanUp()

	/**
	 * `purge` is a policy the engine knows.
	 *
	 * @return void
	 */
	public function testPurgeIsAnAcceptedPolicy(): void {
		$this->assertSame(DisappearancePolicy::PURGE, DisappearancePolicy::fromSourceConfig(['disappearancePolicy' => 'purge']));
	}//end testPurgeIsAnAcceptedPolicy()

	/**
	 * A vanished record on a complete run is deleted permanently, recorded, and its contract kept.
	 *
	 * @return void
	 */
	public function testAVanishedRecordIsPurgedPermanentlyAndRecorded(): void {
		$result = $this->cleanUp($this->service(['pub-a', 'pub-b']), ['pub-a']);

		$this->assertCount(1, $this->deletes, 'Exactly one object is deleted.');
		$this->assertSame('pub-b', $this->deletes[0]['uuid']);
		$this->assertTrue($this->deletes[0]['permanent'] ?? false, 'The delete is permanent, so the files go with it.');
		$this->assertSame(1, $result['guard']['purgedCount'], 'The run counts one purged object.');
		$this->assertSame(0, $result['deleted'], 'A purge is not counted as a soft delete.');

		$kept = end($this->contracts);
		$this->assertSame('doc-pub-b', $kept['originId'], 'The contract is kept.');
		$this->assertNull($kept['targetId']);
		$this->assertSame('purge', $kept['targetLastAction']);

		$this->assertCount(1, $this->contractLogs, 'The purge is written to the contract log.');
		$log = $this->contractLogs[0];
		$this->assertSame(self::SYNC_ID, $log['synchronizationId']);
		$this->assertSame('purged', $log['targetResult']);
		$this->assertSame('doc-pub-b', $log['source']['originId']);
		$this->assertSame('fullRun', $log['source']['trigger']);
		$this->assertSame('pub-b', $log['target']['id']);
	}//end testAVanishedRecordIsPurgedPermanentlyAndRecorded()

	/**
	 * A full run reports the purge on its result, which is what the run log stores.
	 *
	 * @return void
	 */
	public function testAFullRunReportsThePurgedCount(): void {
		$result = $this->service(['pub-a', 'pub-b'])->synchronize(
			synchronization: [
				'id' => self::SYNC_ID,
				'uuid' => self::SYNC_ID,
				'sourceId' => 'source-uuid-woo',
				'sourceType' => 'api',
				'targetType' => 'register/schema',
				'targetId' => '1/2',
				'sourceConfig' => ['endpoint' => '/documenten', 'resultsPosition' => 'items', 'disappearancePolicy' => 'purge'],
			]
		);

		$this->assertSame(['pub-a', 'pub-b'], array_column($this->deletes, 'uuid'));
		$this->assertSame(2, $result['result']['objects']['purged'], 'The run counts two purged objects.');
		$this->assertSame(0, $result['result']['objects']['deleted']);
		$this->assertSame([], $result['result']['objects']['purgeRefusals']);
	}//end testAFullRunReportsThePurgedCount()

	/**
	 * An incomplete fetch purges nothing and says why.
	 *
	 * @return void
	 */
	public function testAnIncompleteFetchPurgesNothing(): void {
		$result = $this->cleanUp($this->service(['pub-a', 'pub-b']), ['pub-a'], complete: false);

		$this->assertSame([], $this->deletes);
		$this->assertSame([], $this->contractLogs);
		$this->assertSame('fetch_incomplete', $result['guard']['reason']);
	}//end testAnIncompleteFetchPurgesNothing()

	/**
	 * Incremental mode never purges.
	 *
	 * @return void
	 */
	public function testIncrementalModePurgesNothing(): void {
		$result = $this->cleanUp($this->service(['pub-a', 'pub-b']), ['pub-a'], syncMode: 'incremental');

		$this->assertSame([], $this->deletes);
		$this->assertSame('incremental_mode', $result['guard']['reason']);
	}//end testIncrementalModePurgesNothing()

	/**
	 * The deletion ratio guard holds a purge back too.
	 *
	 * @return void
	 */
	public function testThePurgeKeepsTheDeletionRatioGuard(): void {
		$result = $this->cleanUp($this->service(['pub-a', 'pub-b', 'pub-c']), ['pub-a']);

		$this->assertSame([], $this->deletes, 'Two of three gone is past the ratio: nothing is purged.');
		$this->assertTrue($result['guard']['guarded']);
	}//end testThePurgeKeepsTheDeletionRatioGuard()

	/**
	 * A purge OpenRegister refuses leaves the object, is not soft deleted, and is listed.
	 *
	 * @return void
	 */
	public function testARefusedPurgeLeavesTheObjectAndIsListed(): void {
		$result = $this->cleanUp($this->service(['pub-a', 'pub-b'], refuseUuid: 'pub-b'), ['pub-a']);

		$this->assertSame([], $this->deletes, 'No delete went through, soft or permanent.');
		$this->assertSame(0, $result['guard']['purgedCount']);
		$this->assertCount(1, $result['guard']['purgeRefusals'], 'The run lists the refusal.');
		$refusal = $result['guard']['purgeRefusals'][0];
		$this->assertSame('doc-pub-b', $refusal['originId']);
		$this->assertSame('pub-b', $refusal['targetId']);
		$this->assertStringContainsString('dependent object(s) block deletion', $refusal['reason'], 'OpenRegister\'s reason is kept.');

		foreach ($this->contracts as $contract) {
			$this->assertNotSame('purge', ($contract['targetLastAction'] ?? null), 'The contract does not claim a purge.');
		}

		$this->assertCount(1, $this->contractLogs, 'The refusal is on the contract log.');
		$this->assertSame('purge_refused', $this->contractLogs[0]['targetResult']);
	}//end testARefusedPurgeLeavesTheObjectAndIsListed()
}//end class
