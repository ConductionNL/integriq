<?php

/**
 * Unit tests for DeveloperPortalAccess: who a developer is and which
 * consumers are an application they own.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-developer-portal-and-subscriptions/design.md#d2-an-application-is-a-consumer-the-developer-owns
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\DeveloperPortalAccess;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\DeveloperPortalAccess
 */
final class DeveloperPortalAccessTest extends TestCase {

	/**
	 * Only a consumer made in the portal by this developer is theirs.
	 *
	 * @return void
	 */
	public function testADeveloperOwnsOnlyTheApplicationsTheyMadeInThePortal(): void {
		$access = new DeveloperPortalAccess(
			appConfig: $this->createMock(IAppConfig::class),
			groupManager: $this->createMock(IGroupManager::class)
		);
		$developer = $this->createMock(IUser::class);
		$developer->method('getUID')->willReturn('developer-a');

		$this->assertTrue($access->owns(user: $developer, consumer: self::consumer(['ownerKind' => 'developer', 'userId' => 'developer-a'])));
		$this->assertFalse($access->owns(user: $developer, consumer: self::consumer(['ownerKind' => 'developer', 'userId' => 'developer-b'])));
		$this->assertFalse($access->owns(user: $developer, consumer: self::consumer(['userId' => 'developer-a'])), 'a consumer an administrator made');
		$this->assertFalse($access->owns(user: $developer, consumer: self::consumer(['ownerKind' => 'admin', 'userId' => 'developer-a'])));
		$this->assertFalse($access->owns(user: $developer, consumer: null));
	}//end testADeveloperOwnsOnlyTheApplicationsTheyMadeInThePortal()

	/**
	 * A consumer object.
	 *
	 * @param array<string, mixed> $data The consumer's data.
	 *
	 * @return ObjectEntity The consumer.
	 */
	private static function consumer(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);

		return $entity;
	}//end consumer()
}//end class
