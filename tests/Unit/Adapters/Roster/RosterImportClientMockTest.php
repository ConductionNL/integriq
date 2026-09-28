<?php

/**
 * Tests for the dormant roster-import mock client.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-the-mapper-turns-a-vendor-lesson-into-a-planninq-session-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Roster;

use OCA\Integriq\Adapters\Roster\RosterImportClient;
use OCA\Integriq\Adapters\Roster\RosterImportClientMock;
use PHPUnit\Framework\TestCase;

/**
 * Lock the canned vendor-shaped batches against the representative fixture at
 * tests/fixtures/roster/fixture-roster-batch.json.
 */
class RosterImportClientMockTest extends TestCase {
	/**
	 * The fixture batches keyed by source.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function loadFixture(): array {
		$decoded = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/roster/fixture-roster-batch.json'), true);
		$this->assertIsArray($decoded);
		return $decoded['sources'];
	}//end loadFixture()

	/**
	 * The mock is the abstract client's mock flavour.
	 *
	 * @return void
	 */
	public function testMockExtendsAbstractClient(): void {
		$mock = new RosterImportClientMock();

		$this->assertInstanceOf(RosterImportClient::class, $mock);
		$this->assertSame('mock', $mock->flavour());
	}//end testMockExtendsAbstractClient()

	/**
	 * Every source's batch matches the fixture exactly.
	 *
	 * @return void
	 */
	public function testEachSourceMatchesTheFixture(): void {
		$mock = new RosterImportClientMock();

		foreach ($this->loadFixture() as $systemId => $batch) {
			$this->assertSame($batch, $mock->fetchLessons($systemId), $systemId);
		}

		$this->assertCount(4, $this->loadFixture());
	}//end testEachSourceMatchesTheFixture()

	/**
	 * The sources speak different vendor dialects, so a preset is needed per source.
	 *
	 * @return void
	 */
	public function testSourcesUseTheirOwnFieldNames(): void {
		$mock = new RosterImportClientMock();

		$this->assertArrayHasKey('appointmentInstance', $mock->fetchLessons('roster-zermelo')[0]);
		$this->assertArrayHasKey('klasseId', $mock->fetchLessons('roster-untis-oneroster')[0]);
		$this->assertArrayHasKey('groupCode', $mock->fetchLessons('roster-xedule')[0]);
		$this->assertArrayHasKey('resourceGroup', $mock->fetchLessons('roster-timeedit')[0]);
	}//end testSourcesUseTheirOwnFieldNames()

	/**
	 * An unknown source returns no lessons.
	 *
	 * @return void
	 */
	public function testUnknownSourceReturnsNothing(): void {
		$this->assertSame([], (new RosterImportClientMock())->fetchLessons('roster-unknown'));
	}//end testUnknownSourceReturnsNothing()
}//end class
