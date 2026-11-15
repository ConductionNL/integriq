<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\OrganisationBoundary;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * The organisation an object belongs to, whether two organisations may meet,
 * and the `@self` block that files a record under an object's organisation.
 *
 * @covers \OCA\Integriq\Service\Helper\OrganisationBoundary
 */
final class OrganisationBoundaryTest extends TestCase {

	public function testAnEntityAnswersWithItsOrganisationColumn(): void {
		$entity = new ObjectEntity();
		$entity->setOrganisation('org-a');

		$this->assertSame('org-a', $this->boundary()->of($entity));
	}

	public function testASerialisedObjectAnswersWithItsSelfOrganisation(): void {
		$this->assertSame('org-a', $this->boundary()->of(['@self' => ['organisation' => 'org-a']]));
	}

	public function testAnExpandedOrganisationAnswersWithItsUuidElseItsId(): void {
		$boundary = $this->boundary();

		$this->assertSame('org-uuid', $boundary->of(['@self' => ['organisation' => ['uuid' => 'org-uuid', 'id' => 7]]]));
		$this->assertSame('7', $boundary->of(['@self' => ['organisation' => ['id' => 7]]]));
		$this->assertSame('', $boundary->of(['@self' => ['organisation' => ['name' => 'Org']]]));
	}

	public function testAnObjectWithoutAnOrganisationAnswersEmpty(): void {
		$boundary = $this->boundary();

		$this->assertSame('', $boundary->of(null));
		$this->assertSame('', $boundary->of([]));
		$this->assertSame('', $boundary->of(['@self' => []]));
		$this->assertSame('', $boundary->of(new ObjectEntity()));
	}

	public function testTwoOrganisationsMeetUnlessBothAreSetAndDiffer(): void {
		$boundary = $this->boundary();

		$this->assertTrue($boundary->same('org-a', 'org-a'));
		$this->assertTrue($boundary->same('', 'org-a'));
		$this->assertTrue($boundary->same('org-a', ''));
		$this->assertTrue($boundary->same('', ''));
		$this->assertFalse($boundary->same('org-a', 'org-b'));
	}

	public function testTheSelfBlockNamesTheOrganisationOrIsEmpty(): void {
		$boundary = $this->boundary();

		$this->assertSame(['@self' => ['organisation' => 'org-a']], $boundary->self(['@self' => ['organisation' => 'org-a']]));
		$this->assertSame([], $boundary->self(null));
	}

	/**
	 * A class that uses the trait and exposes its private methods.
	 *
	 * @return object
	 */
	private function boundary(): object {
		return new class {
			use OrganisationBoundary;

			public function of(ObjectEntity|array|null $object): string {
				return $this->organisationOf(object: $object);
			}

			public function same(string $first, string $second): bool {
				return $this->sameOrganisation(first: $first, second: $second);
			}

			public function self(ObjectEntity|array|null $object): array {
				return $this->organisationSelf(object: $object);
			}
		};
	}
}
