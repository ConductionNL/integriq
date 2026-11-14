<?php

/**
 * Unit tests for DirectoryEntry — how one directory row becomes an entry:
 * group names from plain strings and from SCIM `{value, display}` pairs,
 * the active flag, the display name and the account id fallbacks.
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

use OCA\Integriq\Directory\DirectoryEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for one directory entry.
 */
#[CoversClass(DirectoryEntry::class)]
class DirectoryEntryTest extends TestCase {

	/**
	 * Plain and SCIM-shaped group memberships both become group names, display first.
	 *
	 * @return void
	 */
	public function testGroupsAreReadFromStringsAndScimPairs(): void {
		$entry = DirectoryEntry::fromArray(
			[
				'userName' => 'anja',
				'groups' => [
					'OU=Vergunningen',
					['value' => 'g-1', 'display' => 'OU=Toezicht'],
					['value' => 'g-2'],
					['display' => ''],
					['value' => 42],
					'',
					7,
					'OU=Vergunningen',
				],
			]
		);

		$this->assertSame(['OU=Vergunningen', 'OU=Toezicht', 'g-2'], $entry->getDirectoryGroups());
	}//end testGroupsAreReadFromStringsAndScimPairs()

	/**
	 * A row without groups yields an entry without groups.
	 *
	 * @return void
	 */
	public function testARowWithoutGroupsHasNone(): void {
		$this->assertSame([], DirectoryEntry::fromArray(['userName' => 'anja'])->getDirectoryGroups());
	}//end testARowWithoutGroupsHasNone()

	/**
	 * A row without an active flag is active; an explicit false or 0 is not.
	 *
	 * @return void
	 */
	public function testTheActiveFlagDefaultsToTrue(): void {
		$this->assertTrue(DirectoryEntry::fromArray(['userName' => 'anja'])->isActive());
		$this->assertFalse(DirectoryEntry::fromArray(['userName' => 'anja', 'active' => false])->isActive());
		$this->assertFalse(DirectoryEntry::fromArray(['userName' => 'anja', 'active' => 0])->isActive());
		$this->assertTrue(DirectoryEntry::fromArray(['userName' => 'anja', 'active' => 1])->isActive());
	}//end testTheActiveFlagDefaultsToTrue()

	/**
	 * The account id is userName, else userId, else id, else empty.
	 *
	 * @return void
	 */
	public function testTheAccountIdFallsBackInOrder(): void {
		$this->assertSame('un', DirectoryEntry::fromArray(['userName' => 'un', 'userId' => 'ui', 'id' => 'i'])->getUserId());
		$this->assertSame('ui', DirectoryEntry::fromArray(['userId' => 'ui', 'id' => 'i'])->getUserId());
		$this->assertSame('5', DirectoryEntry::fromArray(['id' => 5])->getUserId());
		$this->assertSame('', DirectoryEntry::fromArray([])->getUserId());
	}//end testTheAccountIdFallsBackInOrder()

	/**
	 * The display name is kept as a string when present and null when absent.
	 *
	 * @return void
	 */
	public function testTheDisplayNameIsOptional(): void {
		$this->assertSame('Anja de Vries', DirectoryEntry::fromArray(['userName' => 'anja', 'displayName' => 'Anja de Vries'])->getDisplayName());
		$this->assertSame('12', DirectoryEntry::fromArray(['userName' => 'anja', 'displayName' => 12])->getDisplayName());
		$this->assertNull(DirectoryEntry::fromArray(['userName' => 'anja'])->getDisplayName());
		$this->assertNull(DirectoryEntry::fromArray(['userName' => 'anja', 'displayName' => null])->getDisplayName());
	}//end testTheDisplayNameIsOptional()

	/**
	 * Attributes are carried over as given, defaulting to an empty list.
	 *
	 * @return void
	 */
	public function testAttributesAreCarriedOver(): void {
		$this->assertSame(['mail' => 'a@example.org'], DirectoryEntry::fromArray(['userName' => 'anja', 'attributes' => ['mail' => 'a@example.org']])->getAttributes());
		$this->assertSame([], DirectoryEntry::fromArray(['userName' => 'anja'])->getAttributes());
	}//end testAttributesAreCarriedOver()

	/**
	 * The serialised entry names the account, display name, groups and active flag, not the attributes.
	 *
	 * @return void
	 */
	public function testTheEntrySerialisesForTheRunReport(): void {
		$entry = new DirectoryEntry(
			userId: 'anja',
			directoryGroups: ['OU=Toezicht'],
			active: false,
			attributes: ['mail' => 'a@example.org'],
			displayName: 'Anja'
		);

		$this->assertSame(
			['userId' => 'anja', 'displayName' => 'Anja', 'groups' => ['OU=Toezicht'], 'active' => false],
			$entry->jsonSerialize()
		);
		$this->assertSame('{"userId":"anja","displayName":"Anja","groups":["OU=Toezicht"],"active":false}', json_encode($entry));
	}//end testTheEntrySerialisesForTheRunReport()
}//end class
