<?php

/**
 * Unit tests for PrivilegedAccountGuard — which accounts SCIM may never write.
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
 * @spec openspec/changes/harden-scim-consumer-authorization/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Directory;

use OCA\Integriq\Directory\PrivilegedAccountGuard;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Settings\IManager as ISettingsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PrivilegedAccountGuard.
 */
class PrivilegedAccountGuardTest extends TestCase {

	/**
	 * Build the guard around the given collaborators.
	 *
	 * @param IGroupManager   $groupManager   The group model.
	 * @param ISubAdmin       $subAdmin       The sub-admin lookup.
	 * @param ISettingsManager $settingsManager The delegated admin settings lookup.
	 *
	 * @return PrivilegedAccountGuard The guard.
	 */
	private function guard(IGroupManager $groupManager, ISubAdmin $subAdmin, ISettingsManager $settingsManager): PrivilegedAccountGuard {
		return new PrivilegedAccountGuard(
			groupManager: $groupManager,
			subAdmin: $subAdmin,
			settingsManager: $settingsManager,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end guard()

	/**
	 * An ordinary account is not privileged.
	 *
	 * @return void
	 */
	public function testAnOrdinaryAccountIsNotPrivileged(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);
		$subAdmin = $this->createMock(ISubAdmin::class);
		$subAdmin->method('isSubAdmin')->willReturn(false);
		$settingsManager = $this->createMock(ISettingsManager::class);
		$settingsManager->method('getAllAllowedAdminSettings')->willReturn([]);

		$this->assertFalse($this->guard($groupManager, $subAdmin, $settingsManager)->isPrivileged(user: $this->createMock(IUser::class)));
	}//end testAnOrdinaryAccountIsNotPrivileged()

	/**
	 * A member of the admin group is privileged.
	 *
	 * @return void
	 */
	public function testAnAdminGroupMemberIsPrivileged(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(true);

		$guard = $this->guard($groupManager, $this->createMock(ISubAdmin::class), $this->createMock(ISettingsManager::class));

		$this->assertTrue($guard->isPrivileged(user: $this->createMock(IUser::class)));
	}//end testAnAdminGroupMemberIsPrivileged()

	/**
	 * When the sub-admin lookup fails the account is treated as privileged.
	 *
	 * @return void
	 */
	public function testALookupFailureFailsClosed(): void {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(false);
		$subAdmin = $this->createMock(ISubAdmin::class);
		$subAdmin->method('isSubAdmin')->willThrowException(new \RuntimeException('lookup failed'));

		$guard = $this->guard($groupManager, $subAdmin, $this->createMock(ISettingsManager::class));

		$this->assertTrue($guard->isPrivileged(user: $this->createMock(IUser::class)));
	}//end testALookupFailureFailsClosed()
}//end class
