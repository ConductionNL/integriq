<?php

/**
 * Unit tests for ScimProvisioningService::listUsers() without a filter: every
 * account the user search answers becomes a full SCIM user resource.
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
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-scim-provisioning-creates-changes-and-deactivates-accounts-req-ds-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Directory;

use OCA\Integriq\Directory\DirectorySource;
use OCA\Integriq\Directory\GroupMappingResolver;
use OCA\Integriq\Directory\OpenWorkReporter;
use OCA\Integriq\Directory\ScimProvisioningService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the unfiltered SCIM user list.
 */
#[CoversClass(ScimProvisioningService::class)]
#[UsesClass(OpenWorkReporter::class)]
class ScimProvisioningServiceListTest extends TestCase {

	private IUserManager&MockObject $userManager;

	private IGroupManager&MockObject $groupManager;

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
		return new ScimProvisioningService(
			userManager: $this->userManager,
			groupManager: $this->groupManager,
			secureRandom: $this->createMock(ISecureRandom::class),
			openWorkReporter: new OpenWorkReporter(
				eventDispatcher: $this->createMock(IEventDispatcher::class),
				logger: $this->createMock(LoggerInterface::class)
			),
			logger: $this->createMock(LoggerInterface::class),
			directorySource: $this->createMock(DirectorySource::class),
			mappingResolver: $this->createMock(GroupMappingResolver::class)
		);
	}//end service()

	/**
	 * A stub account.
	 *
	 * @param string      $uid         The uid.
	 * @param bool        $enabled     Whether it is enabled.
	 * @param string|null $email       Its email address.
	 * @param string      $displayName Its display name.
	 *
	 * @return IUser&MockObject The account.
	 */
	private function account(string $uid, bool $enabled, ?string $email, string $displayName): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('getDisplayName')->willReturn($displayName);

		return $user;
	}//end account()

	/**
	 * Every account the search answers is listed as a resource, with its email and groups.
	 *
	 * @return void
	 */
	public function testEveryAccountTheSearchAnswersIsListedAsAResource(): void {
		$anja = $this->account(uid: 'anja', enabled: true, email: 'anja@example.org', displayName: 'Anja');
		$bram = $this->account(uid: 'bram', enabled: false, email: '', displayName: 'Bram');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('vergunningen');
		$group->method('getDisplayName')->willReturn('Vergunningen');

		$this->userManager->expects($this->once())->method('search')->with('', 50)->willReturn([$anja, $bram]);
		$this->userManager->expects($this->never())->method('get');
		$this->groupManager->method('getUserGroups')->willReturnCallback(
			static fn (IUser $user): array => ($user->getUID() === 'anja' ? [$group] : [])
		);

		$this->assertSame(
			[
				[
					'schemas' => [ScimProvisioningService::USER_SCHEMA],
					'id' => 'anja',
					'userName' => 'anja',
					'displayName' => 'Anja',
					'active' => true,
					'emails' => [['value' => 'anja@example.org', 'primary' => true]],
					'groups' => [['value' => 'vergunningen', 'display' => 'Vergunningen']],
				],
				[
					'schemas' => [ScimProvisioningService::USER_SCHEMA],
					'id' => 'bram',
					'userName' => 'bram',
					'displayName' => 'Bram',
					'active' => false,
					'emails' => [],
					'groups' => [],
				],
			],
			$this->service()->listUsers(limit: 50)
		);
	}//end testEveryAccountTheSearchAnswersIsListedAsAResource()

	/**
	 * A search that finds nobody lists nothing.
	 *
	 * @return void
	 */
	public function testASearchThatFindsNobodyListsNothing(): void {
		$this->userManager->expects($this->once())->method('search')->with('', 100)->willReturn([]);

		$this->assertSame([], $this->service()->listUsers());
	}//end testASearchThatFindsNobodyListsNothing()
}//end class
