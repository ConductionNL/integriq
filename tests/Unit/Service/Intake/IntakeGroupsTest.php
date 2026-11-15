<?php

/**
 * Unit tests for IntakeGroups.
 *
 * Pins how the intake reader groups are created, joined, left and described:
 * an existing group is reused, a refused create or membership change is
 * logged and answered as a failure, and a missing account or group changes
 * nothing.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Intake;

use OCA\Integriq\Service\Intake\IntakeGroups;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for IntakeGroups.
 */
#[CoversClass(IntakeGroups::class)]
class IntakeGroupsTest extends TestCase {

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The user manager.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The service under test.
	 *
	 * @var IntakeGroups
	 */
	private IntakeGroups $intake;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->groups = $this->createMock(IGroupManager::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->intake = new IntakeGroups($this->groups, $this->users, $this->logger);
	}//end setUp()

	/**
	 * A user known to the user manager.
	 *
	 * @return IUser&MockObject
	 */
	private function knownUser(): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$this->users->method('get')->with('anna')->willReturn($user);
		return $user;
	}//end knownUser()

	/**
	 * An existing group is reused without a create.
	 *
	 * @return void
	 */
	public function testEnsureReusesAnExistingGroup(): void {
		$group = $this->createMock(IGroup::class);
		$this->groups->method('get')->with('intake-readers')->willReturn($group);
		$this->groups->expects($this->never())->method('createGroup');

		$this->assertSame($group, $this->intake->ensure('intake-readers'));
	}//end testEnsureReusesAnExistingGroup()

	/**
	 * A missing group is created and the creation logged.
	 *
	 * @return void
	 */
	public function testEnsureCreatesAMissingGroup(): void {
		$group = $this->createMock(IGroup::class);
		$this->groups->method('get')->willReturn(null);
		$this->groups->expects($this->once())->method('createGroup')->with('intake-readers')->willReturn($group);
		$this->logger->expects($this->once())->method('info')->with('[IntakeGroups] created group intake-readers');

		$this->assertSame($group, $this->intake->ensure('intake-readers'));
	}//end testEnsureCreatesAMissingGroup()

	/**
	 * A backend that declines the create answers null without an info line.
	 *
	 * @return void
	 */
	public function testEnsureAnswersNullWhenTheBackendDeclines(): void {
		$this->groups->method('get')->willReturn(null);
		$this->groups->method('createGroup')->willReturn(null);
		$this->logger->expects($this->never())->method('info');

		$this->assertNull($this->intake->ensure('intake-readers'));
	}//end testEnsureAnswersNullWhenTheBackendDeclines()

	/**
	 * A create that throws is logged as an error and answers null.
	 *
	 * @return void
	 */
	public function testEnsureLogsAFailedCreate(): void {
		$this->groups->method('get')->willReturn(null);
		$this->groups->method('createGroup')->willThrowException(new RuntimeException('backend read-only'));
		$this->logger->expects($this->once())->method('error')->with(
			'[IntakeGroups] could not create group intake-readers',
			['exception' => 'backend read-only']
		);

		$this->assertNull($this->intake->ensure('intake-readers'));
	}//end testEnsureLogsAFailedCreate()

	/**
	 * Membership is asked of the group manager with the uid first.
	 *
	 * @return void
	 */
	public function testIsMemberAsksTheGroupManager(): void {
		$this->groups->expects($this->exactly(2))->method('isInGroup')
			->with('anna', 'intake-readers')
			->willReturnOnConsecutiveCalls(true, false);

		$this->assertTrue($this->intake->isMember('intake-readers', 'anna'));
		$this->assertFalse($this->intake->isMember('intake-readers', 'anna'));
	}//end testIsMemberAsksTheGroupManager()

	/**
	 * A missing or empty group has no members and is described as empty.
	 *
	 * @return void
	 */
	public function testHasMembersAndDescribe(): void {
		$empty = $this->createMock(IGroup::class);
		$empty->method('count')->willReturn(0);
		$filled = $this->createMock(IGroup::class);
		$filled->method('count')->willReturn(2);
		$this->groups->method('get')->willReturnMap([['missing', null], ['empty', $empty], ['filled', $filled]]);

		$this->assertFalse($this->intake->hasMembers('missing'));
		$this->assertFalse($this->intake->hasMembers('empty'));
		$this->assertTrue($this->intake->hasMembers('filled'));
		$this->assertSame(['id' => 'missing', 'empty' => true], $this->intake->describe('missing'));
		$this->assertSame(['id' => 'filled', 'empty' => false], $this->intake->describe('filled'));
	}//end testHasMembersAndDescribe()

	/**
	 * An account is added to the group, which is created when needed.
	 *
	 * @return void
	 */
	public function testEnrolAddsTheAccount(): void {
		$user = $this->knownUser();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($user)->willReturn(false);
		$group->expects($this->once())->method('addUser')->with($user);
		$this->groups->method('get')->willReturn(null);
		$this->groups->method('createGroup')->willReturn($group);
		$this->logger->expects($this->exactly(2))->method('info');

		$this->assertTrue($this->intake->enrol('intake-readers', 'anna'));
	}//end testEnrolAddsTheAccount()

	/**
	 * A member already in the group is not added again.
	 *
	 * @return void
	 */
	public function testEnrolLeavesAnExistingMember(): void {
		$user = $this->knownUser();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(true);
		$group->expects($this->never())->method('addUser');
		$this->groups->method('get')->willReturn($group);

		$this->assertTrue($this->intake->enrol('intake-readers', 'anna'));
	}//end testEnrolLeavesAnExistingMember()

	/**
	 * An unknown account or an unavailable group is not enrolled.
	 *
	 * @return void
	 */
	public function testEnrolFailsWithoutAccountOrGroup(): void {
		$this->users->method('get')->willReturnMap([['ghost', null], ['anna', $this->createMock(IUser::class)]]);
		$group = $this->createMock(IGroup::class);
		$group->expects($this->never())->method('addUser');
		$this->groups->method('get')->willReturnMap([['intake-readers', $group], ['refused', null]]);
		$this->groups->method('createGroup')->willReturn(null);

		$this->assertFalse($this->intake->enrol('intake-readers', 'ghost'));
		$this->assertFalse($this->intake->enrol('refused', 'anna'));
	}//end testEnrolFailsWithoutAccountOrGroup()

	/**
	 * A refused add is logged and answered as a failure.
	 *
	 * @return void
	 */
	public function testEnrolLogsAFailedAdd(): void {
		$this->knownUser();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(false);
		$group->method('addUser')->willThrowException(new RuntimeException('quota'));
		$this->groups->method('get')->willReturn($group);
		$this->logger->expects($this->once())->method('error')->with(
			'[IntakeGroups] could not add anna to intake-readers',
			['exception' => 'quota']
		);
		$this->logger->expects($this->never())->method('info');

		$this->assertFalse($this->intake->enrol('intake-readers', 'anna'));
	}//end testEnrolLogsAFailedAdd()

	/**
	 * A member is removed and the removal logged.
	 *
	 * @return void
	 */
	public function testWithdrawRemovesAMember(): void {
		$user = $this->knownUser();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($user)->willReturn(true);
		$group->expects($this->once())->method('removeUser')->with($user);
		$this->groups->method('get')->with('intake-readers')->willReturn($group);
		$this->logger->expects($this->once())->method('info')->with('[IntakeGroups] removed anna from intake-readers');

		$this->intake->withdraw('intake-readers', 'anna');
	}//end testWithdrawRemovesAMember()

	/**
	 * A non-member, unknown account or missing group changes nothing, and the
	 * group is never created for a withdrawal.
	 *
	 * @return void
	 */
	public function testWithdrawChangesNothingWhenThereIsNothingToRemove(): void {
		$this->users->method('get')->willReturnMap([['ghost', null], ['anna', $this->createMock(IUser::class)]]);
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(false);
		$group->expects($this->never())->method('removeUser');
		$this->groups->method('get')->willReturnMap([['intake-readers', $group], ['missing', null]]);
		$this->groups->expects($this->never())->method('createGroup');
		$this->logger->expects($this->never())->method('info');

		$this->intake->withdraw('intake-readers', 'ghost');
		$this->intake->withdraw('missing', 'anna');
		$this->intake->withdraw('intake-readers', 'anna');
	}//end testWithdrawChangesNothingWhenThereIsNothingToRemove()

	/**
	 * A refused removal is logged as an error.
	 *
	 * @return void
	 */
	public function testWithdrawLogsAFailedRemoval(): void {
		$this->knownUser();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(true);
		$group->method('removeUser')->willThrowException(new RuntimeException('locked'));
		$this->groups->method('get')->willReturn($group);
		$this->logger->expects($this->once())->method('error')->with(
			'[IntakeGroups] could not remove anna from intake-readers',
			['exception' => 'locked']
		);

		$this->intake->withdraw('intake-readers', 'anna');
	}//end testWithdrawLogsAFailedRemoval()
}//end class
