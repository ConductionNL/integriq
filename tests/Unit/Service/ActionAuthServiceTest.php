<?php

/**
 * Unit tests for ActionAuthService (action-level authorization matrix).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/action-authorization/spec.md#requirement-enforcement-is-centralised-in-actionauthservice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Admins always pass; others pass only through a non-admin group in the matrix.
 */
#[CoversClass(ActionAuthService::class)]
class ActionAuthServiceTest extends TestCase {

	/**
	 * A service whose stored matrix is the given JSON.
	 *
	 * @param string $json The stored matrix.
	 * @param bool $isAdmin Whether the user is an admin.
	 * @param array<int,string> $groups The user's groups.
	 *
	 * @return ActionAuthService
	 */
	private function service(string $json, bool $isAdmin = false, array $groups = []): ActionAuthService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('integriq', 'actions', '{}')->willReturn($json);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('alice')->willReturn($isAdmin);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		return new ActionAuthService(appConfig: $config, groupManager: $groupManager);
	}//end service()

	/**
	 * The user under test.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		return $user;
	}//end user()

	/**
	 * An admin passes even when the action is not in the matrix.
	 *
	 * @return void
	 */
	public function testAnAdminAlwaysPasses(): void {
		$this->assertTrue($this->service(json: '{}', isAdmin: true)->can($this->user(), 'source.test'));
	}//end testAnAdminAlwaysPasses()

	/**
	 * An action missing from the matrix is admin-only.
	 *
	 * @return void
	 */
	public function testAnUnlistedActionIsAdminOnly(): void {
		$service = $this->service(json: '{}', groups: ['admin', 'staff']);

		$this->assertSame(['admin'], $service->getAllowedGroups('source.test'));
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage("Action 'source.test' requires admin rights");
		$service->requireAction($this->user(), 'source.test');
	}//end testAnUnlistedActionIsAdminOnly()

	/**
	 * An empty entry allows nobody but admins.
	 *
	 * @return void
	 */
	public function testAnEmptyEntryAllowsNobody(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage("Action 'source.test' requires admin rights");
		$this->service(json: '{"source.test":[]}', groups: ['staff'])->requireAction($this->user(), 'source.test');
	}//end testAnEmptyEntryAllowsNobody()

	/**
	 * A member of a listed group passes.
	 *
	 * @return void
	 */
	public function testAMemberOfAListedGroupPasses(): void {
		$service = $this->service(json: '{"source.test":["admin","staff"]}', groups: ['staff']);

		$service->requireAction($this->user(), 'source.test');
		$this->assertTrue($service->can($this->user(), 'source.test'));
	}//end testAMemberOfAListedGroupPasses()

	/**
	 * Membership of "admin" as a plain group does not count; only isAdmin does.
	 *
	 * @return void
	 */
	public function testAnAdminGroupIdWithoutAdminRightsIsRefused(): void {
		$service = $this->service(json: '{"source.test":["admin","staff"]}', groups: ['admin', 'other']);

		$this->assertFalse($service->can($this->user(), 'source.test'));
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage("Action 'source.test' not allowed for your groups");
		$service->requireAction($this->user(), 'source.test');
	}//end testAnAdminGroupIdWithoutAdminRightsIsRefused()

	/**
	 * Malformed or non-object config reads as an empty matrix.
	 *
	 * @return void
	 */
	public function testMalformedConfigReadsAsEmpty(): void {
		$this->assertSame([], $this->service(json: '{not json')->getMatrix());
		$this->assertSame([], $this->service(json: '"text"')->getMatrix());
		$this->assertSame([], $this->service(json: '{not json')->getActions());
	}//end testMalformedConfigReadsAsEmpty()

	/**
	 * Reading drops non-list entries, non-string and empty groups, and duplicates.
	 *
	 * @return void
	 */
	public function testTheMatrixIsNormalisedOnRead(): void {
		$service = $this->service(json: '{"a.x":["staff","",3,"staff","ops"],"b.y":"staff","0":["staff"]}');

		$this->assertSame(['a.x' => ['staff', 'ops']], $service->getMatrix());
		$this->assertSame(['a.x'], $service->getActions());
	}//end testTheMatrixIsNormalisedOnRead()

	/**
	 * Writing normalises the groups and stores JSON under the app's key.
	 *
	 * @return void
	 */
	public function testTheMatrixIsNormalisedOnWrite(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('setValueString')
			->with('integriq', 'actions', '{"a.x":["staff","ops"],"b.y":[]}');

		$service = new ActionAuthService(appConfig: $config, groupManager: $this->createMock(IGroupManager::class));
		$service->setMatrix(['a.x' => ['staff', '', 'staff', 7, 'ops'], 'b.y' => []]);
	}//end testTheMatrixIsNormalisedOnWrite()
}//end class
