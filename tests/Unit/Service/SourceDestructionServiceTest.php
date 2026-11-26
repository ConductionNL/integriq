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
use OCA\Integriq\Service\ActingUser;
use OCA\Integriq\Service\SourceDestructionService;
use OCA\Integriq\Service\SynchronizationContractLogService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Integriq\Tests\Support\LegacyObjectArgs;

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
	 * The access flags of every findAll(): `rbac` and `multitenancy` per call.
	 *
	 * @var list<array{schema: string|null, rbac: bool, multitenancy: bool}>
	 */
	private array $lookupAccess = [];

	/**
	 * Every identity the service made active, in order (null restores none).
	 *
	 * @var list<string|null>
	 */
	private array $actingUsers = [];

	/**
	 * The service under test on one synchronization with three synchronized documents.
	 *
	 * By default the notice arrives with a signed-in user, as an authenticated
	 * call does. `$sessionUser` null models the public route, where the
	 * service acts as the synchronization's `$owner`.
	 *
	 * @param array       $sourceConfig The synchronization's sourceConfig.
	 * @param string|null $sessionUser  The uid of the session user, or null for none.
	 * @param string      $owner        The synchronization's owner.
	 * @param list<string> $knownUsers   The uids the user manager can resolve.
	 *
	 * @return SourceDestructionService
	 */
	private function service(
		array $sourceConfig,
		?string $sessionUser = 'admin',
		string $owner = '',
		array $knownUsers = [],
	): SourceDestructionService {
		$synchronization = [
			'sourceId' => self::SOURCE_ID,
			'sourceType' => 'api',
			'targetType' => 'register/schema',
			'targetId' => '1/2',
			'sourceConfig' => $sourceConfig,
		];
		$syncEntity = ObjectServiceMockBuilder::objectEntity($this, $synchronization, self::SYNC_ID);
		if ($owner !== '') {
			$syncEntity->setOwner($owner);
		}
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
			function (array $config, bool $rbac = true, bool $multitenancy = true) use ($contracts, $syncEntity, $otherSync): array {
				$this->lookups[] = ($config['filters'] ?? []);
				$this->lookupAccess[] = ['schema' => ($config['filters']['schema'] ?? null), 'rbac' => $rbac, 'multitenancy' => $multitenancy];
				if (($config['filters']['schema'] ?? null) === 'synchronization') {
					return ['results' => [$syncEntity, $otherSync]];
				}

				return ['results' => $contracts];
			}
		);
		$orObjectService->method('find')->willReturnCallback(fn (...$args) => ($objects[(string)$args[0]] ?? null));
		$orObjectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				$args = LegacyObjectArgs::positional(method: 'saveObject', args: $args);
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
				$args = LegacyObjectArgs::positional(method: 'deleteObject', args: $args);
				// By position: 0 is the uuid, 7 is `permanent` (openregister 98a3469c0f).
				$this->deletes[] = ['uuid' => $args[0], 'permanent' => ($args[7] ?? false)];
				return true;
			}
		);

		$contractLogService = $this->createMock(SynchronizationContractLogService::class);
		// The builder createFromArray() only builds an entry; an entry counts as written once it is
		// stored, so the log is captured at insert()/update(), not at the builder.
		$contractLogService->method('createFromArray')->willReturnArgument(0);
		$store = function (array $log): array {
			$this->contractLogs[] = $log;
			return $log;
		};
		$contractLogService->method('insert')->willReturnCallback($store);
		$contractLogService->method('update')->willReturnCallback($store);

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

		$user = function (string $uid): IUser {
			$mock = $this->createMock(originalClassName: IUser::class);
			$mock->method('getUID')->willReturn($uid);
			return $mock;
		};
		$current = null;
		if ($sessionUser !== null) {
			$current = $user($sessionUser);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($current);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $active): void {
				$this->actingUsers[] = $active?->getUID();
			}
		);
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnCallback(
			static function (string $uid) use ($knownUsers, $user): ?IUser {
				if (in_array($uid, $knownUsers, true) === true) {
					return $user($uid);
				}

				return null;
			}
		);

		return new SourceDestructionService(
			objectService: $orObjectService,
			engine: $engine,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			actingUser: new ActingUser(userManager: $users, userSession: $session)
		);
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

	/**
	 * A notice on the public route, with no user, acts as the synchronization's owner and then restores no user.
	 *
	 * OpenRegister refuses a sessionless delete; the owner is the person who set
	 * the synchronization up, so the notice acts with their rights and no more.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function testASessionlessNoticeActsAsTheSynchronizationsOwner(): void {
		$service = $this->service(sourceConfig: ['onSourceDestroyed' => 'purge'], sessionUser: null, owner: 'beheerder', knownUsers: ['beheerder']);

		$outcome = $service->handleDestroyed(synchronizationId: self::SYNC_ID, originId: 'doc-2', reference: 'vernietigingsverklaring-7');

		$this->assertSame(expected: 'purged', actual: $outcome['outcome']);
		$this->assertSame(expected: [['uuid' => 'pub-2', 'permanent' => true]], actual: $this->deletes);
		$this->assertSame(expected: ['beheerder', null], actual: $this->actingUsers, message: 'acts as the owner, then restores the prior (no) user');
		$this->assertCount(expectedCount: 1, haystack: $this->contractLogs, message: 'the purge is written to the contract log');
		// OpenRegister refuses an entry whose `expires` is not a string.
		$this->assertIsString(actual: $this->contractLogs[0]['expires']);
	}//end testASessionlessNoticeActsAsTheSynchronizationsOwner()

	/**
	 * A sessionless ZGW notice also acts as the owner of each synchronization it reaches.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function testASessionlessZgwNoticeActsAsTheOwnerToo(): void {
		$service = $this->service(sourceConfig: ['onSourceDestroyed' => 'purge'], sessionUser: null, owner: 'beheerder', knownUsers: ['beheerder']);

		$outcomes = $service->handleZgwDestroyed(sourceId: self::SOURCE_ID, resourceUrl: self::DRC . 'doc-1');

		$this->assertSame(expected: 'purged', actual: $outcomes[0]['outcome']);
		$this->assertSame(expected: ['beheerder', null], actual: $this->actingUsers);
	}//end testASessionlessZgwNoticeActsAsTheOwnerToo()

	/**
	 * A sessionless notice for a synchronization without an owner that still exists touches nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function testASessionlessNoticeWithoutAnOwnerTouchesNothing(): void {
		foreach (['' => [], 'vertrokken' => []] as $owner => $known) {
			$this->deletes = [];
			$this->actingUsers = [];
			$service = $this->service(sourceConfig: ['onSourceDestroyed' => 'purge'], sessionUser: null, owner: (string)$owner, knownUsers: $known);

			$outcome = $service->handleDestroyed(synchronizationId: self::SYNC_ID, originId: 'doc-2', reference: null);

			$this->assertSame(expected: 'no_owner', actual: $outcome['outcome'], message: 'owner "' . $owner . '"');
			$this->assertSame(expected: [], actual: $this->deletes);
			$this->assertSame(expected: [], actual: $this->actingUsers, message: 'no identity is switched');
		}
	}//end testASessionlessNoticeWithoutAnOwnerTouchesNothing()

	/**
	 * A signed-in caller keeps its own identity: nothing is switched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function testASignedInCallerKeepsItsOwnIdentity(): void {
		$service = $this->service(sourceConfig: ['onSourceDestroyed' => 'purge'], owner: 'beheerder', knownUsers: ['beheerder']);

		$outcome = $service->handleDestroyed(synchronizationId: self::SYNC_ID, originId: 'doc-2', reference: null);
		$this->assertSame(expected: 'purged', actual: $outcome['outcome']);
		$this->assertSame(expected: [], actual: $this->actingUsers);
	}//end testASignedInCallerKeepsItsOwnIdentity()

	/**
	 * A notice reads the contracts and the source's synchronizations unscoped, so a call without a user finds them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function testANoticeReadsContractsAndSynchronizationsUnscoped(): void {
		$service = $this->service(sourceConfig: ['onSourceDestroyed' => 'purge']);

		$service->handleZgwDestroyed(sourceId: self::SOURCE_ID, resourceUrl: self::DRC . 'doc-1');

		$this->assertNotSame(expected: [], actual: $this->lookupAccess);
		foreach ($this->lookupAccess as $access) {
			$this->assertFalse(condition: $access['rbac'], message: 'the ' . $access['schema'] . ' lookup skips RBAC');
			$this->assertFalse(condition: $access['multitenancy'], message: 'the ' . $access['schema'] . ' lookup skips multitenancy');
		}
	}//end testANoticeReadsContractsAndSynchronizationsUnscoped()
}//end class
