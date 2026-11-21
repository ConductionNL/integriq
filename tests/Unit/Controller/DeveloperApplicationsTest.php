<?php

/**
 * Unit tests for DeveloperApplications::owns(): which consumers are an
 * application the developer owns.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
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

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\DeveloperApplications;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Controller\DeveloperApplications
 */
final class DeveloperApplicationsTest extends TestCase {

	/**
	 * Only a consumer made in the portal by this developer is theirs.
	 *
	 * @return void
	 */
	public function testADeveloperOwnsOnlyTheApplicationsTheyMadeInThePortal(): void {
		$developer = $this->createMock(originalClassName: IUser::class);
		$developer->method('getUID')->willReturn('developer-a');

		$mine     = self::consumer(data: ['ownerKind' => 'developer', 'userId' => 'developer-a']);
		$theirs   = self::consumer(data: ['ownerKind' => 'developer', 'userId' => 'developer-b']);
		$adminOne = self::consumer(data: ['userId' => 'developer-a']);
		$adminTwo = self::consumer(data: ['ownerKind' => 'admin', 'userId' => 'developer-a']);

		$this->assertTrue(condition: DeveloperApplications::owns(user: $developer, consumer: $mine));
		$this->assertFalse(condition: DeveloperApplications::owns(user: $developer, consumer: $theirs));
		$this->assertFalse(condition: DeveloperApplications::owns(user: $developer, consumer: $adminOne), message: 'a consumer an administrator made');
		$this->assertFalse(condition: DeveloperApplications::owns(user: $developer, consumer: $adminTwo));
		$this->assertFalse(condition: DeveloperApplications::owns(user: $developer, consumer: null));
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
