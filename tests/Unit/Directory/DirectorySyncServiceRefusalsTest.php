<?php

/**
 * Unit tests for the paths of a directory run that do not end in a clean write.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Directory;

use OCA\Integriq\Directory\DirectorySource;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A refused run, running every enabled connection, memberships that cannot be
 * written and a run record that cannot be stored.
 *
 * @covers \OCA\Integriq\Directory\DirectorySyncService
 * @uses   \OCA\Integriq\Directory\DirectorySource
 * @uses   \OCA\Integriq\Directory\DirectorySnapshot
 * @uses   \OCA\Integriq\Directory\DirectoryEntry
 * @uses   \OCA\Integriq\Directory\DirectorySyncRun
 * @uses   \OCA\Integriq\Directory\GroupMappingResolver
 * @uses   \OCA\Integriq\Directory\OpenWorkReporter
 * @uses   \OCA\Integriq\Exception\DirectorySyncRefusalException
 * @uses   \OCA\Integriq\Service\SynchronizationRunLog
 */
class DirectorySyncServiceRefusalsTest extends TestCase {

	private IGroupManager&MockObject $groupManager;

	private IGroup&MockObject $group;

	private IUserManager&MockObject $userManager;

	private OrObjectService&MockObject $objects;

	private SynchronizationLogService&MockObject $logService;

	private LoggerInterface&MockObject $logger;

	/**
	 * Log objects handed to createFromArray.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logged = [];

	protected function setUp(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->group        = $this->createMock(IGroup::class);
		$this->userManager  = $this->createMock(IUserManager::class);
		$this->objects      = $this->createMock(OrObjectService::class);
		$this->logService   = $this->createMock(SynchronizationLogService::class);
		$this->logger       = $this->createMock(LoggerInterface::class);
		$this->logService->method('createFromArray')->willReturnCallback(
			function (array $object): SynchronizationRunLog {
				$this->logged[] = $object;
				$object['uuid'] = 'run-'.count($this->logged);
				return (new SynchronizationRunLog())->hydrate(object: $object);
			}
		);
	}//end setUp()

	/**
	 * The service under test.
	 */
	private function service(): DirectorySyncService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf(str_replace(['%1$s', '%2$s'], '%s', $text), $parameters)
		);

		return new DirectorySyncService(
			directorySource: new DirectorySource(
				orObjectService: $this->objects,
				callService: $this->createMock(CallService::class),
				l10n: $l10n,
				logger: $this->createMock(LoggerInterface::class)
			),
			mappingResolver: new GroupMappingResolver(groupManager: $this->groupManager, l10n: $l10n),
			openWorkReporter: new OpenWorkReporter(eventDispatcher: $this->createMock(IEventDispatcher::class), logger: $this->createMock(LoggerInterface::class)),
			logService: $this->logService,
			groupManager: $this->groupManager,
			userManager: $this->userManager,
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			l10n: $l10n,
			logger: $this->logger
		);
	}//end service()

	/**
	 * A mock-mode directory connection mapping OU=Vergunningen to behandelaars.
	 *
	 * @param string $uuid    The uuid.
	 * @param array  $users   The fixture users.
	 * @param bool   $enabled Whether the connection is enabled.
	 */
	private static function connection(string $uuid, array $users = [], bool $enabled = true): ObjectEntity {
		$connection = new ObjectEntity();
		$connection->setUuid($uuid);
		$connection->setObject(
			[
				'name' => 'Directory '.$uuid,
				'type' => 'directory',
				'isEnabled' => $enabled,
				'configuration' => [
					'mock' => true,
					'fixture' => ['complete' => true, 'users' => $users],
					'mapping' => ['createMissingGroups' => false, 'rules' => [['directoryGroup' => 'OU=Vergunningen', 'group' => 'behandelaars']]],
				],
			]
		);
		return $connection;
	}//end connection()

	/**
	 * The existing behandelaars group with these members.
	 *
	 * @param array<int, string> $members The member uids.
	 */
	private function existingGroup(array $members = []): void {
		$this->group->method('getGID')->willReturn('behandelaars');
		$this->group->method('getUsers')->willReturn(array_map(fn (string $uid): IUser => $this->user(uid: $uid), $members));
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->groupManager->method('get')->willReturn($this->group);
		$this->logService->method('update')->willReturnArgument(0);
	}//end existingGroup()

	/**
	 * A Nextcloud account.
	 *
	 * @param string $uid The uid.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	public function testAMissingTargetGroupRefusesTheRunAndChangesNothing(): void {
		$this->groupManager->method('groupExists')->willReturn(false);
		$this->groupManager->expects($this->never())->method('createGroup');
		$this->groupManager->expects($this->never())->method('get');
		$this->logService->method('update')->willReturnArgument(0);

		$record = $this->service()->run(connection: self::connection(uuid: 'conn-1', users: [['userName' => 'bram', 'groups' => ['OU=Vergunningen']]]), dryRun: true);

		$message = 'The mapping names the Nextcloud group "behandelaars", which does not exist. Creating missing groups is off for this connection. Nothing was changed.';
		$this->assertSame('refused', $record['status']);
		$this->assertSame(['message' => $message, 'reason' => 'unknown-target-group', 'group' => 'behandelaars'], $record['refusal']);
		$this->assertTrue($record['dryRun']);
		$this->assertSame([0, 0, [], [], [], []], [$record['usersRead'], $record['membershipsAdded'], $record['additions'], $record['removals'], $record['failures'], $record['groupsCreated']]);
		$this->assertSame(['conn-1', 'Directory conn-1', 'directory', 'run-1'], [$record['connection'], $record['connectionName'], $record['kind'], $record['runId']]);
		$this->assertSame($message, $this->logged[0]['message']);
		$this->assertSame('conn-1', $this->logged[0]['synchronizationId']);
		$this->assertTrue($this->logged[0]['test']);
		$this->assertIsInt($this->logged[0]['executionTime']);
	}//end testAMissingTargetGroupRefusesTheRunAndChangesNothing()

	public function testRunAllRunsEveryEnabledDirectoryConnection(): void {
		$this->existingGroup();
		$this->objects->method('findAll')->willReturn(
			[self::connection(uuid: 'conn-on'), self::connection(uuid: 'conn-off', enabled: false), self::connection(uuid: 'conn-also-on')]
		);
		$this->group->expects($this->never())->method('addUser');

		$records = $this->service()->runAll(dryRun: true);

		$this->assertSame(['conn-on', 'conn-also-on'], array_column($records, 'connection'));
		$this->assertSame([true, true], array_column($records, 'dryRun'));
		$this->assertSame(['Success', 'Success'], array_column($this->logged, 'message'));
	}//end testRunAllRunsEveryEnabledDirectoryConnection()

	public function testAMembershipThatCannotBeWrittenIsAFailureNotAnAbort(): void {
		$this->existingGroup(members: ['oud']);
		$this->userManager->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'spook' ? null : $this->user(uid: $uid)));
		$this->group->method('addUser')->willReturnCallback(
			static function (IUser $user): void {
				if ($user->getUID() === 'kapot') {
					throw new RuntimeException('LDAP is read-only');
				}
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[DirectorySyncService] membership write failed for kapot in behandelaars: LDAP is read-only');

		$record = $this->service()->run(
			connection: self::connection(
				uuid: 'conn-1',
				users: [
					['userName' => 'oud', 'groups' => ['OU=Vergunningen']],
					['userName' => 'kapot', 'groups' => ['OU=Vergunningen']],
					['userName' => 'spook', 'groups' => ['OU=Vergunningen']],
					['userName' => 'nieuw', 'groups' => ['OU=Vergunningen']],
				]
			)
		);

		$this->assertSame(1, $record['membershipsAdded']);
		$this->assertSame(
			[
				['userId' => 'kapot', 'group' => 'behandelaars', 'reason' => 'LDAP is read-only'],
				['userId' => 'spook', 'group' => 'behandelaars', 'reason' => 'The Nextcloud account or group named on this membership does not exist.'],
			],
			$record['failures']
		);
	}//end testAMembershipThatCannotBeWrittenIsAFailureNotAnAbort()

	public function testARunRecordThatCannotBeStoredIsStillAnswered(): void {
		$this->group->method('getGID')->willReturn('behandelaars');
		$this->group->method('getUsers')->willReturn([]);
		$this->groupManager->method('groupExists')->willReturn(true);
		$this->groupManager->method('get')->willReturn($this->group);
		$this->logService->method('update')->willThrowException(new RuntimeException('table locked'));
		$this->logger->expects($this->once())->method('error')->with('[DirectorySyncService] could not persist the run record: table locked');

		$record = $this->service()->run(connection: self::connection(uuid: 'conn-1'));

		$this->assertSame('success', $record['status']);
		$this->assertSame('run-1', $record['runId']);
	}//end testARunRecordThatCannotBeStoredIsStillAnswered()
}//end class
