<?php

/**
 * Unit tests for DirectorySyncService — the run itself: a new member joins, a
 * leaver is removed, a preview changes nothing, a truncated directory is
 * guarded, and the run record carries the counts.
 *
 * The directory is a fake: the connection is in mock mode, so these tests
 * exercise the real reader, the real mapping and the real membership writer
 * rather than a stub of any of them.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Directory;

use OCA\Integriq\Directory\DirectorySource;
use OCA\Integriq\Directory\DirectorySyncService;
use OCA\Integriq\Directory\GroupMappingResolver;
use OCA\Integriq\Directory\OpenWorkReporter;
use OCA\Integriq\Event\SynchronizationDeletionGuardedEvent;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for a directory run.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
 */
class DirectorySyncServiceTest extends TestCase {

	/**
	 * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $groupManager;

	/**
	 * @var IGroup|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $group;

	/**
	 * @var IUserManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userManager;

	/**
	 * @var IEventDispatcher|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $eventDispatcher;

	/**
	 * The run logs the service wrote.
	 *
	 * @var array<int,SynchronizationRunLog>
	 */
	private array $persistedLogs = [];

	/**
	 * Build the collaborators every test shares.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->group = $this->createMock(IGroup::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->persistedLogs = [];

	}//end setUp()

	/**
	 * A translator that leaves the English sentence readable.
	 *
	 * @return IL10N|\PHPUnit\Framework\MockObject\MockObject The translator.
	 */
	private function l10n() {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				return vsprintf(str_replace(['%1$s', '%2$s', '%3$s'], '%s', $text), $parameters);
			}
		);

		return $l10n;
	}//end l10n()

	/**
	 * A directory connection in mock mode carrying the given fixture users.
	 *
	 * @param array<int,array<string,mixed>> $users The fixture users.
	 * @param boolean $complete Whether the fixture stands for a complete read.
	 *
	 * @return ObjectEntity The connection.
	 */
	private function connection(array $users, bool $complete = true): ObjectEntity {
		$connection = new ObjectEntity();
		$connection->setUuid('conn-1');
		$connection->setObject(
			[
				'name' => 'Gemeente directory',
				'type' => 'directory',
				'isEnabled' => true,
				'configuration' => [
					'mock' => true,
					'fixture' => ['complete' => $complete, 'users' => $users],
					'mapping' => [
						'createMissingGroups' => false,
						'rules' => [
							['directoryGroup' => 'OU=Vergunningen', 'group' => 'behandelaars'],
							['directoryGroup' => 'OU=Toezicht', 'group' => 'behandelaars'],
						],
					],
					'openWork' => ['consumers' => ['dossiq']],
				],
			]
		);

		return $connection;
	}//end connection()

	/**
	 * The service under test, over the mocked Nextcloud group model.
	 *
	 * @param array<int,string> $currentMembers Who is in `behandelaars` today.
	 *
	 * @return DirectorySyncService The service.
	 */
	private function service(array $currentMembers): DirectorySyncService {
		$users = [];
		foreach ($currentMembers as $uid) {
			$users[] = $this->user(uid: $uid);
		}

		$this->group->method('getGID')->willReturn('behandelaars');
		$this->group->method('getUsers')->willReturn($users);
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->groupManager->method('get')->willReturn($this->group);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid) => $this->user(uid: $uid));

		$logService = $this->createMock(SynchronizationLogService::class);
		$logService->method('createFromArray')->willReturnCallback(
			static function (array $object): SynchronizationRunLog {
				$object['uuid'] = 'run-1';

				return (new SynchronizationRunLog())->hydrate(object: $object);
			}
		);
		$logService->method('update')->willReturnCallback(
			function (SynchronizationRunLog $log): SynchronizationRunLog {
				$this->persistedLogs[] = $log;

				return $log;
			}
		);

		$l10n = $this->l10n();

		$directorySource = new DirectorySource(
			orObjectService: $this->createMock(OrObjectService::class),
			callService: $this->createMock(CallService::class),
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class)
		);

		return new DirectorySyncService(
			directorySource: $directorySource,
			mappingResolver: new GroupMappingResolver(groupManager: $this->groupManager, l10n: $l10n),
			openWorkReporter: new OpenWorkReporter(
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: $this->createMock(LoggerInterface::class)
			),
			logService: $logService,
			groupManager: $this->groupManager,
			userManager: $this->userManager,
			eventDispatcher: $this->eventDispatcher,
			l10n: $l10n,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * A Nextcloud account stub.
	 *
	 * @param string $uid The account id.
	 *
	 * @return IUser|\PHPUnit\Framework\MockObject\MockObject The account.
	 */
	private function user(string $uid) {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * A new member of a mapped directory group joins the Nextcloud group.
	 *
	 * @return void
	 */
	public function testANewMemberJoinsTheMappedGroup(): void {
		$service = $this->service(currentMembers: ['anja']);
		$this->group->expects($this->once())->method('addUser');
		$this->group->expects($this->never())->method('removeUser');

		$record = $service->run(
			connection: $this->connection(
				users: [
					['userName' => 'anja', 'groups' => ['OU=Vergunningen']],
					['userName' => 'bram', 'groups' => ['OU=Toezicht']],
				]
			)
		);

		$this->assertSame(1, $record['membershipsAdded']);
		$this->assertSame(0, $record['membershipsRemoved']);
		$this->assertSame([['userId' => 'bram', 'group' => 'behandelaars']], $record['additions']);

	}//end testANewMemberJoinsTheMappedGroup()

	/**
	 * A membership the directory dropped is removed here.
	 *
	 * This is the assertion the "leavers are never removed" mutation reddens: a
	 * sync that only adds is a sync that hides a leaver.
	 *
	 * @return void
	 */
	public function testALeaverIsRemovedFromTheMappedGroup(): void {
		// Four current members against a threshold of 10%: the removal ratio is
		// 25%, so `confirmRemovals` stands in for the administrator who has
		// already been shown the guard.
		$service = $this->service(currentMembers: ['anja', 'bram', 'cees', 'dana']);
		$this->group->expects($this->once())->method('removeUser');

		$record = $service->run(
			connection: $this->connection(
				users: [
					['userName' => 'anja', 'groups' => ['OU=Vergunningen']],
					['userName' => 'bram', 'groups' => ['OU=Toezicht']],
					['userName' => 'cees', 'groups' => ['OU=Toezicht']],
				]
			),
			dryRun: false,
			confirmRemovals: true
		);

		$this->assertSame(1, $record['membershipsRemoved']);
		$this->assertSame([['userId' => 'dana', 'group' => 'behandelaars']], $record['removals']);

	}//end testALeaverIsRemovedFromTheMappedGroup()

	/**
	 * A leaver's open work is reported, and a silent consumer reads unknown.
	 *
	 * @return void
	 */
	public function testALeaversOpenWorkIsReportedAsUnknownWhenNobodyAnswers(): void {
		$service = $this->service(currentMembers: ['anja', 'bram', 'cees', 'dana']);

		$record = $service->run(
			connection: $this->connection(
				users: [
					['userName' => 'anja', 'groups' => ['OU=Vergunningen']],
					['userName' => 'bram', 'groups' => ['OU=Toezicht']],
					['userName' => 'cees', 'groups' => ['OU=Toezicht']],
				]
			),
			dryRun: false,
			confirmRemovals: true
		);

		$this->assertArrayHasKey('dana', $record['openWork']);
		$this->assertSame(OpenWorkReporter::UNKNOWN, $record['openWork']['dana']['dossiq']);
		$this->assertNotSame(0, $record['openWork']['dana']['dossiq']);

	}//end testALeaversOpenWorkIsReportedAsUnknownWhenNobodyAnswers()

	/**
	 * A preview writes no membership and still lists both sides.
	 *
	 * @return void
	 */
	public function testAPreviewChangesNothingAndReportsBothSides(): void {
		$service = $this->service(currentMembers: ['anja', 'bram', 'cees', 'dana']);
		$this->group->expects($this->never())->method('addUser');
		$this->group->expects($this->never())->method('removeUser');

		$record = $service->run(
			connection: $this->connection(
				users: [
					['userName' => 'anja', 'groups' => ['OU=Vergunningen']],
					['userName' => 'bram', 'groups' => ['OU=Toezicht']],
					['userName' => 'cees', 'groups' => ['OU=Toezicht']],
					['userName' => 'eva', 'groups' => ['OU=Toezicht']],
				]
			),
			dryRun: true
		);

		$this->assertTrue($record['dryRun']);
		$this->assertCount(1, $record['additions']);
		$this->assertCount(1, $record['removals']);
		$this->assertSame(0, $record['membershipsAdded']);
		$this->assertSame(0, $record['membershipsRemoved']);

	}//end testAPreviewChangesNothingAndReportsBothSides()

	/**
	 * A truncated directory stops the run before writing, and names the ratio.
	 *
	 * @return void
	 */
	public function testATruncatedDirectoryIsGuardedBeforeWriting(): void {
		$service = $this->service(currentMembers: ['anja', 'bram', 'cees', 'dana']);
		$this->group->expects($this->never())->method('removeUser');

		$guarded = null;
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
			static function ($event) use (&$guarded): void {
				if ($event instanceof SynchronizationDeletionGuardedEvent) {
					$guarded = $event;
				}
			}
		);

		$record = $service->run(
			connection: $this->connection(
				users: [['userName' => 'anja', 'groups' => ['OU=Vergunningen']]],
				complete: false
			)
		);

		$this->assertSame('guarded', $record['status']);
		$this->assertSame(DirectorySyncService::GUARD_FETCH_INCOMPLETE, $record['guard']['reason']);
		$this->assertTrue($record['guard']['resumable']);
		$this->assertSame(0, $record['membershipsRemoved']);
		$this->assertInstanceOf(SynchronizationDeletionGuardedEvent::class, $guarded);

	}//end testATruncatedDirectoryIsGuardedBeforeWriting()

	/**
	 * A complete directory whose removals cross the ratio is guarded too, and
	 * the message names the ratio it hit.
	 *
	 * @return void
	 */
	public function testRemovalsOverTheRatioAreGuardedAndNamed(): void {
		$service = $this->service(currentMembers: ['anja', 'bram', 'cees', 'dana']);
		$this->group->expects($this->never())->method('removeUser');

		$record = $service->run(
			connection: $this->connection(
				users: [['userName' => 'anja', 'groups' => ['OU=Vergunningen']]]
			)
		);

		$this->assertSame(DirectorySyncService::GUARD_RATIO_EXCEEDED, $record['guard']['reason']);
		$this->assertStringContainsString('10%', $record['guard']['message']);
		$this->assertSame(3, $record['guard']['heldBack']);

	}//end testRemovalsOverTheRatioAreGuardedAndNamed()

	/**
	 * The run record carries the counts, and it is written down.
	 *
	 * @return void
	 */
	public function testTheRunRecordCarriesTheCountsAndIsWritten(): void {
		$service = $this->service(currentMembers: ['anja']);

		$record = $service->run(
			connection: $this->connection(
				users: [
					['userName' => 'anja', 'groups' => ['OU=Vergunningen']],
					['userName' => 'bram', 'groups' => ['OU=Toezicht']],
				]
			)
		);

		$this->assertSame(2, $record['usersRead']);
		$this->assertSame(2, $record['groupsRead']);
		$this->assertSame('directory', $record['kind']);
		$this->assertSame('run-1', $record['runId']);
		$this->assertCount(1, $this->persistedLogs);

		// The persisted record is the same record, minus the run id the log
		// only hands back once it has been written.
		$persisted = $this->persistedLogs[0]->getResult();
		$this->assertSame(2, $persisted['usersRead']);
		$this->assertSame(1, $persisted['membershipsAdded']);
		$this->assertSame('directory', $persisted['kind']);

	}//end testTheRunRecordCarriesTheCountsAndIsWritten()

	/**
	 * One directory row integriq cannot read does not stop the run.
	 *
	 * @return void
	 */
	public function testOneBadRecordDoesNotStopTheRun(): void {
		$service = $this->service(currentMembers: ['anja']);

		$record = $service->run(
			connection: $this->connection(
				users: [
					['groups' => ['OU=Toezicht']],
					['userName' => 'anja', 'groups' => ['OU=Vergunningen']],
					['userName' => 'bram', 'groups' => ['OU=Toezicht']],
				]
			)
		);

		$this->assertSame(1, $record['membershipsAdded']);
		$this->assertCount(1, $record['failures']);
		$this->assertStringContainsString('no account name', $record['failures'][0]['reason']);

	}//end testOneBadRecordDoesNotStopTheRun()
}//end class
