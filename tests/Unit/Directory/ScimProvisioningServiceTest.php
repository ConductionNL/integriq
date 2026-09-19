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

use OCA\Integriq\Directory\OpenWorkReporter;
use OCA\Integriq\Directory\ScimProvisioningService;
use OCP\EventDispatcher\IEventDispatcher;
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
	 * @return ScimProvisioningService The service.
	 */
	private function service(): ScimProvisioningService {
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
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

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

		$report = $this->service()->deactivateUser(userId: 'dana', expectedConsumers: ['dossiq']);

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

		$resource = $this->service()->upsertUser(resource: ['userName' => 'dana', 'active' => false]);

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

		$resource = $this->service()->upsertUser(resource: ['userName' => 'eva']);

		$this->assertSame('eva', $resource['userName']);
		$this->assertStringNotContainsString('a-throwaway-password', (string)json_encode($resource));

	}//end testAnUnknownAccountIsCreatedWithoutSurfacingAPassword()
}//end class
