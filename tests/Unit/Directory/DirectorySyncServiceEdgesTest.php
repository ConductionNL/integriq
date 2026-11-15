<?php

/**
 * Unit tests for DirectorySyncService's edge paths: a managed group that
 * Nextcloud cannot hand back, a removal that cannot be written, and a leaver's
 * open work asked only once per account however many groups they leave.
 *
 * The directory is a connection in mock mode, so the real reader, mapping and
 * membership writer run; only Nextcloud's group and user model is mocked.
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

use OCA\Integriq\Directory\DirectoryEntry;
use OCA\Integriq\Directory\DirectorySnapshot;
use OCA\Integriq\Directory\DirectorySource;
use OCA\Integriq\Directory\DirectorySyncRun;
use OCA\Integriq\Directory\DirectorySyncService;
use OCA\Integriq\Directory\GroupMappingResolver;
use OCA\Integriq\Directory\OpenWorkReporter;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge paths of a directory run.
 */
#[CoversClass(DirectorySyncService::class)]
#[UsesClass(DirectoryEntry::class)]
#[UsesClass(DirectorySnapshot::class)]
#[UsesClass(DirectorySource::class)]
#[UsesClass(DirectorySyncRun::class)]
#[UsesClass(GroupMappingResolver::class)]
#[UsesClass(OpenWorkReporter::class)]
#[UsesClass(SynchronizationRunLog::class)]
class DirectorySyncServiceEdgesTest extends TestCase {

	private IGroupManager&MockObject $groupManager;

	private IUserManager&MockObject $userManager;

	private LoggerInterface&MockObject $logger;

	private IEventDispatcher&MockObject $openWorkDispatcher;

	/**
	 * The Nextcloud groups by id; a missing id makes `get()` answer null.
	 *
	 * @var array<string,IGroup&MockObject>
	 */
	private array $groups = [];

	/**
	 * Build the collaborators every test shares.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->openWorkDispatcher = $this->createMock(IEventDispatcher::class);
		$this->groups = [];

		$this->groupManager->method('groupExists')->willReturn(true);
		$this->groupManager->method('get')->willReturnCallback(fn (string $gid): ?IGroup => ($this->groups[$gid] ?? null));
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user(uid: $uid));
	}//end setUp()

	/**
	 * A Nextcloud group holding the given members.
	 *
	 * @param string            $gid     The group id.
	 * @param array<int,string> $members The account ids in it today.
	 *
	 * @return IGroup&MockObject The group.
	 */
	private function group(string $gid, array $members): IGroup&MockObject {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$group->method('getUsers')->willReturn(array_map(fn (string $uid): IUser => $this->user(uid: $uid), $members));
		$this->groups[$gid] = $group;

		return $group;
	}//end group()

	/**
	 * A Nextcloud account stub.
	 *
	 * @param string $uid The account id.
	 *
	 * @return IUser&MockObject The account.
	 */
	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * The mapping both tests use: two directory groups onto two Nextcloud groups.
	 *
	 * @return array<string,mixed> The configuration.
	 */
	private static function configuration(array $users): array {
		return [
			'mock' => true,
			'fixture' => ['complete' => true, 'users' => $users],
			'mapping' => [
				'createMissingGroups' => false,
				'rules' => [
					['directoryGroup' => 'OU=Vergunningen', 'group' => 'behandelaars'],
					['directoryGroup' => 'OU=Toezicht', 'group' => 'toezichthouders'],
				],
			],
			'openWork' => ['consumers' => ['dossiq']],
		];
	}//end configuration()

	/**
	 * A directory connection in mock mode.
	 *
	 * @param array<int,array<string,mixed>> $users The fixture users.
	 *
	 * @return ObjectEntity The connection.
	 */
	private static function connection(array $users): ObjectEntity {
		$connection = new ObjectEntity();
		$connection->setUuid('conn-1');
		$connection->setObject(
			[
				'name' => 'Gemeente directory',
				'type' => 'directory',
				'isEnabled' => true,
				'configuration' => self::configuration(users: $users),
			]
		);

		return $connection;
	}//end connection()

	/**
	 * The service under test.
	 *
	 * @return DirectorySyncService The service.
	 */
	private function service(): DirectorySyncService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf(str_replace(['%1$s', '%2$s', '%3$s'], '%s', $text), $parameters)
		);

		$logService = $this->createMock(SynchronizationLogService::class);
		$logService->method('createFromArray')->willReturnCallback(
			static function (array $object): SynchronizationRunLog {
				$object['uuid'] = 'run-1';

				return (new SynchronizationRunLog())->hydrate(object: $object);
			}
		);
		$logService->method('update')->willReturnArgument(0);

		return new DirectorySyncService(
			directorySource: new DirectorySource(
				orObjectService: $this->createMock(OrObjectService::class),
				callService: $this->createMock(CallService::class),
				l10n: $l10n,
				logger: $this->createMock(LoggerInterface::class)
			),
			mappingResolver: new GroupMappingResolver(groupManager: $this->groupManager, l10n: $l10n),
			openWorkReporter: new OpenWorkReporter(
				eventDispatcher: $this->openWorkDispatcher,
				logger: $this->createMock(LoggerInterface::class)
			),
			logService: $logService,
			groupManager: $this->groupManager,
			userManager: $this->userManager,
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			l10n: $l10n,
			logger: $this->logger
		);
	}//end service()

	/**
	 * A managed group Nextcloud cannot hand back counts as empty: every mapped member is an addition, nobody a removal.
	 *
	 * @return void
	 */
	public function testAGroupNextcloudCannotReturnIsPlannedAsEmpty(): void {
		$this->group(gid: 'behandelaars', members: ['anja', 'oud']);

		$snapshot = new DirectorySnapshot(
			entries: [
				new DirectoryEntry(userId: 'anja', directoryGroups: ['OU=Vergunningen']),
				new DirectoryEntry(userId: 'bram', directoryGroups: ['OU=Toezicht']),
				new DirectoryEntry(userId: 'cees', directoryGroups: ['OU=Toezicht']),
			]
		);

		$plan = $this->service()->plan(snapshot: $snapshot, configuration: self::configuration(users: []));

		$this->assertSame(['behandelaars', 'toezichthouders'], $plan['managedGroups']);
		$this->assertSame(
			[
				['userId' => 'bram', 'group' => 'toezichthouders'],
				['userId' => 'cees', 'group' => 'toezichthouders'],
			],
			$plan['additions']
		);
		$this->assertSame([['userId' => 'oud', 'group' => 'behandelaars']], $plan['removals']);
		$this->assertSame(2, $plan['currentTotal']);
		$this->assertSame([], $plan['failures']);
	}//end testAGroupNextcloudCannotReturnIsPlannedAsEmpty()

	/**
	 * A removal that throws is a named failure; the other removal still lands and is counted.
	 *
	 * @return void
	 */
	public function testARemovalThatCannotBeWrittenIsAFailureNotAnAbort(): void {
		$this->group(gid: 'toezichthouders', members: []);
		$behandelaars = $this->group(gid: 'behandelaars', members: ['anja', 'kapot', 'weg']);
		$removed = [];
		$behandelaars->method('removeUser')->willReturnCallback(
			static function (IUser $user) use (&$removed): void {
				if ($user->getUID() === 'kapot') {
					throw new RuntimeException('LDAP is read-only');
				}

				$removed[] = $user->getUID();
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[DirectorySyncService] membership write failed for kapot in behandelaars: LDAP is read-only');

		$record = $this->service()->run(
			connection: self::connection(users: [['userName' => 'anja', 'groups' => ['OU=Vergunningen']]]),
			dryRun: false,
			confirmRemovals: true
		);

		$this->assertSame(['weg'], $removed);
		$this->assertSame(0, $record['membershipsAdded']);
		$this->assertSame(1, $record['membershipsRemoved']);
		$this->assertSame(
			[['userId' => 'kapot', 'group' => 'behandelaars', 'reason' => 'LDAP is read-only']],
			$record['failures']
		);
	}//end testARemovalThatCannotBeWrittenIsAFailureNotAnAbort()

	/**
	 * Open work is asked once per leaver, and not for someone who moves to another managed group.
	 *
	 * @return void
	 */
	public function testOpenWorkIsAskedOncePerLeaverAndNotForAMover(): void {
		$this->group(gid: 'behandelaars', members: ['anja', 'dana', 'erik']);
		$this->group(gid: 'toezichthouders', members: ['anja', 'dana']);
		$this->openWorkDispatcher->expects($this->once())->method('dispatchTyped');

		$record = $this->service()->run(
			connection: self::connection(
				users: [
					['userName' => 'anja', 'groups' => ['OU=Vergunningen', 'OU=Toezicht']],
					['userName' => 'erik', 'groups' => ['OU=Toezicht']],
				]
			),
			dryRun: true,
			confirmRemovals: true
		);

		$this->assertSame(
			[
				['userId' => 'dana', 'group' => 'behandelaars'],
				['userId' => 'erik', 'group' => 'behandelaars'],
				['userId' => 'dana', 'group' => 'toezichthouders'],
			],
			$record['removals']
		);
		$this->assertSame([['userId' => 'erik', 'group' => 'toezichthouders']], $record['additions']);
		$this->assertSame(['dana'], array_keys($record['openWork']));
		$this->assertSame(OpenWorkReporter::UNKNOWN, $record['openWork']['dana']['dossiq']);
	}//end testOpenWorkIsAskedOncePerLeaverAndNotForAMover()
}//end class
