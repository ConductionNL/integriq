<?php

/**
 * Unit tests for ScimProvisioningService — a deactivation disables the account
 * and never deletes it, and no password is kept.
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
use OCA\Integriq\Directory\GroupMappingResolver;
use OCA\Integriq\Directory\OpenWorkReporter;
use OCA\Integriq\Directory\ScimProvisioningService;
use OCA\Integriq\Exception\DirectorySyncRefusalException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SCIM provisioning.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
 */
class ScimProvisioningServiceTest extends TestCase {

	/**
	 * @var IUserManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userManager;

	/**
	 * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $groupManager;

	/**
	 * Build the collaborators every test shares.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @param array<int,string> $managedGroups The groups the configured directory
	 *                                         connections declare they manage. An
	 *                                         empty array stands for an instance
	 *                                         with no connection configured at all.
	 *
	 * @return ScimProvisioningService The service.
	 */
	private function service(array $managedGroups = ['vergunningen']): ScimProvisioningService {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('a-throwaway-password');

		return new ScimProvisioningService(
			userManager: $this->userManager,
			groupManager: $this->groupManager,
			secureRandom: $random,
			openWorkReporter: new OpenWorkReporter(
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: $this->createMock(LoggerInterface::class)
			),
			logger: $this->createMock(LoggerInterface::class),
			directorySource: $this->directorySource(managedGroups: $managedGroups),
			mappingResolver: $this->mappingResolver(managedGroups: $managedGroups)
		);
	}//end service()

	/**
	 * A DirectorySource answering with one connection, or with none.
	 *
	 * @param array<int,string> $managedGroups Empty means no connection exists.
	 *
	 * @return DirectorySource|\PHPUnit\Framework\MockObject\MockObject The double.
	 */
	private function directorySource(array $managedGroups) {
		$connection = $this->createMock(ObjectEntity::class);
		$connection->method('getObject')->willReturn(['configuration' => []]);

		$source = $this->createMock(DirectorySource::class);
		$connections = [$connection];
		if ($managedGroups === []) {
			$connections = [];
		}

		$source->method('findConnectionsForPolicy')->willReturn($connections);

		return $source;

	}//end directorySource()

	/**
	 * A GroupMappingResolver declaring the given managed groups.
	 *
	 * @param array<int,string> $managedGroups What the connection declares.
	 *
	 * @return GroupMappingResolver|\PHPUnit\Framework\MockObject\MockObject The double.
	 */
	private function mappingResolver(array $managedGroups) {
		$resolver = $this->createMock(GroupMappingResolver::class);
		$resolver->method('managedGroups')->willReturn($managedGroups);

		return $resolver;

	}//end mappingResolver()

	/**
	 * A deactivation disables the account, leaves it present, and deletes
	 * nothing.
	 *
	 * @return void
	 */
	public function testADeactivationDisablesAndNeverDeletes(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('dana');
		$user->expects($this->once())->method('setEnabled')->with(false);
		$user->expects($this->never())->method('delete');

		$this->userManager->method('get')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$report = $this->service()->deactivateUser(userId: 'dana', consumerLabel: 'consumer-1', expectedConsumers: ['dossiq']);

		$this->assertSame(OpenWorkReporter::UNKNOWN, $report['dossiq']);

	}//end testADeactivationDisablesAndNeverDeletes()

	/**
	 * An upsert that carries `active: false` disables rather than deletes.
	 *
	 * @return void
	 */
	public function testAnUpsertWithActiveFalseDisables(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('dana');
		$user->method('isEnabled')->willReturn(false);
		$user->expects($this->once())->method('setEnabled')->with(false);
		$user->expects($this->never())->method('delete');

		$this->userManager->method('get')->willReturn($user);
		$this->userManager->expects($this->never())->method('createUser');
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$resource = $this->service()->upsertUser(resource: ['userName' => 'dana', 'active' => false], consumerLabel: 'consumer-1');

		$this->assertSame('dana', $resource['userName']);
		$this->assertFalse($resource['active']);

	}//end testAnUpsertWithActiveFalseDisables()

	/**
	 * An unknown account is created, and the throwaway password is not returned.
	 *
	 * @return void
	 */
	public function testAnUnknownAccountIsCreatedWithoutSurfacingAPassword(): void {
		$created = $this->createMock(IUser::class);
		$created->method('getUID')->willReturn('eva');
		$created->method('isEnabled')->willReturn(true);

		$this->userManager->method('get')->willReturn(null);
		$this->userManager->expects($this->once())->method('createUser')->willReturn($created);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$resource = $this->service()->upsertUser(resource: ['userName' => 'eva'], consumerLabel: 'consumer-1');

		$this->assertSame('eva', $resource['userName']);
		$this->assertStringNotContainsString('a-throwaway-password', (string)json_encode($resource));

	}//end testAnUnknownAccountIsCreatedWithoutSurfacingAPassword()

	/**
	 * The admin group is refused for every caller, before anything is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008
	 */
	public function testAConsumerCannotMakeItselfAnAdministrator(): void {
		// The refusal must precede the lookup, so the group is never even resolved.
		$this->groupManager->expects($this->never())->method('get');

		$this->expectException(DirectorySyncRefusalException::class);

		$this->service()->setGroupMembers(
			groupId: 'admin',
			members: [['value' => 'mallory']],
			consumerLabel: 'consumer-1'
		);

	}//end testAConsumerCannotMakeItselfAnAdministrator()

	/**
	 * A reconciling write with an empty member list cannot empty the admin group.
	 *
	 * This is the regression that matters most: setGroupMembers() REMOVES anyone
	 * absent from the incoming list, so a refusal placed after the removal loop
	 * would pass a test that only checked the add path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008
	 */
	public function testAConsumerCannotEmptyTheAdministratorGroup(): void {
		$this->groupManager->expects($this->never())->method('get');

		$this->expectException(DirectorySyncRefusalException::class);

		$this->service()->setGroupMembers(groupId: 'admin', members: [], consumerLabel: 'consumer-1');

	}//end testAConsumerCannotEmptyTheAdministratorGroup()

	/**
	 * The admin refusal outranks the allow-list: declaring it managed changes
	 * nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-must-not-write-the-administrator-group-req-ds-008
	 */
	public function testAdminIsRefusedEvenWhenDeclaredManaged(): void {
		$this->groupManager->expects($this->never())->method('get');

		$this->expectException(DirectorySyncRefusalException::class);

		$this->service(managedGroups: ['admin'])->setGroupMembers(
			groupId: 'admin',
			members: [['value' => 'mallory']],
			consumerLabel: 'consumer-1'
		);

	}//end testAdminIsRefusedEvenWhenDeclaredManaged()

	/**
	 * A consumer cannot disable an administrator through the user route.
	 *
	 * `assertWritableGroup()` guarded the GROUP routes, and the review found the
	 * user routes reach the same privilege by a different door: the resolved
	 * consumer was written to a log line and never consulted, so a valid
	 * consumer key could deactivate `admin` (integriq#2104 review 5264751700,
	 * blocker 3). Asserting `setEnabled` is never reached rather than only that
	 * the exception is thrown, so the guard's ORDERING is under test.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-are-attributed-to-a-consumer-req-ds-007
	 */
	public function testAConsumerCannotDeactivateAnAdministrator(): void {
		$user = $this->createMock(IUser::class);
		$user->expects($this->never())->method('setEnabled');
		$user->expects($this->never())->method('delete');

		$this->userManager->method('get')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->expectException(DirectorySyncRefusalException::class);

		$this->service()->deactivateUser(userId: 'root', consumerLabel: 'consumer-1');

	}//end testAConsumerCannotDeactivateAnAdministrator()

	/**
	 * A consumer cannot rewrite an administrator's e-mail address.
	 *
	 * The e-mail address is the password-reset destination, so an unguarded
	 * `upsertUser()` was an account-takeover path and not merely a data edit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-are-attributed-to-a-consumer-req-ds-007
	 */
	public function testAConsumerCannotRewriteAnAdministratorsEmail(): void {
		$user = $this->createMock(IUser::class);
		$user->expects($this->never())->method('setEMailAddress');
		$user->expects($this->never())->method('setDisplayName');

		$this->userManager->method('get')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturn(true);

		$this->expectException(DirectorySyncRefusalException::class);

		$this->service()->upsertUser(
			resource: ['userName' => 'root', 'emails' => [['value' => 'mallory@example.org']]],
			consumerLabel: 'consumer-1'
		);

	}//end testAConsumerCannotRewriteAnAdministratorsEmail()

	/**
	 * A caller cannot walk the whole account estate with one `count`.
	 *
	 * SCIM lets the caller name a page size and it was passed to
	 * `IUserManager::search()` unbounded, so one request could return every
	 * display name and e-mail address on the instance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-are-attributed-to-a-consumer-req-ds-007
	 */
	public function testAnUnboundedCountIsCapped(): void {
		$this->userManager->expects($this->once())
			->method('search')
			->with('', 200)
			->willReturn([]);

		$this->service()->listUsers(limit: 100000);

	}//end testAnUnboundedCountIsCapped()

	/**
	 * A page size below the cap is still honoured.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-are-attributed-to-a-consumer-req-ds-007
	 */
	public function testASmallerPageSizeIsHonoured(): void {
		$this->userManager->expects($this->once())
			->method('search')
			->with('', 25)
			->willReturn([]);

		$this->service()->listUsers(limit: 25);

	}//end testASmallerPageSizeIsHonoured()

	/**
	 * A group no connection declares is refused, and the refusal names it so an
	 * operator can extend the mapping.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-only-the-groups-a-connection-declares-it-manages-req-ds-009
	 */
	public function testAnUnmanagedGroupIsRefusedAndNamed(): void {
		$this->groupManager->expects($this->never())->method('get');

		try {
			$this->service(managedGroups: ['vergunningen'])->setGroupMembers(
				groupId: 'finance',
				members: [['value' => 'dana']],
				consumerLabel: 'consumer-1'
			);
			$this->fail('An unmanaged group must be refused.');
		} catch (DirectorySyncRefusalException $refusal) {
			$context = $refusal->getContext();
			$this->assertSame('finance', $context['group']);
			$this->assertSame('consumer-1', $context['consumer']);
			$this->assertStringContainsString('finance', (string)$context['detail']);
		}

	}//end testAnUnmanagedGroupIsRefusedAndNamed()

	/**
	 * An instance with no directory connection refuses every write rather than
	 * treating an empty union as "no rules".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-only-the-groups-a-connection-declares-it-manages-req-ds-009
	 */
	public function testNoConfiguredConnectionRefusesEveryWrite(): void {
		$this->groupManager->expects($this->never())->method('get');

		$this->expectException(DirectorySyncRefusalException::class);

		$this->service(managedGroups: [])->setGroupMembers(
			groupId: 'vergunningen',
			members: [['value' => 'dana']],
			consumerLabel: 'consumer-1'
		);

	}//end testNoConfiguredConnectionRefusesEveryWrite()

	/**
	 * A declared managed group still reconciles exactly as it did before.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md#requirement-scim-writes-only-the-groups-a-connection-declares-it-manages-req-ds-009
	 */
	public function testAManagedGroupIsWrittenNormally(): void {
		$dana = $this->createMock(IUser::class);
		$dana->method('getUID')->willReturn('dana');

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([]);
		$group->expects($this->once())->method('addUser')->with($dana);
		$group->expects($this->never())->method('removeUser');

		$this->groupManager->method('get')->with('vergunningen')->willReturn($group);
		$this->userManager->method('get')->willReturn($dana);

		$written = $this->service(managedGroups: ['vergunningen'])->setGroupMembers(
			groupId: 'vergunningen',
			members: [['value' => 'dana']],
			consumerLabel: 'consumer-1'
		);

		$this->assertTrue($written);

	}//end testAManagedGroupIsWrittenNormally()
}//end class
