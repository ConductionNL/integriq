<?php

/**
 * Unit tests for the roster-import *ClientMock dormant implementation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Roster
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Roster;

use OCA\Integriq\Adapters\Roster\RosterImportClient;
use OCA\Integriq\Adapters\Roster\RosterImportClientMock;
use PHPUnit\Framework\TestCase;

/**
 * Lock the canned lesson batch for the dormant roster-import client,
 * against the recorded/representative fixture at
 * tests/fixtures/roster/fixture-roster-batch.json.
 */
class RosterImportClientMockTest extends TestCase {
	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function loadFixture(): array {
		$path = __DIR__ . '/../../../fixtures/roster/fixture-roster-batch.json';
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded);
		return $decoded['lessons'];
	}//end loadFixture()

	/**
	 * @return void
	 */
	public function testMockExtendsAbstractClient(): void {
		$mock = new RosterImportClientMock();

		$this->assertInstanceOf(RosterImportClient::class, $mock);
		$this->assertSame('mock', $mock->flavour());
	}//end testMockExtendsAbstractClient()

	/**
	 * @return void
	 */
	public function testFetchLessonsReturnsTwoRecords(): void {
		$mock = new RosterImportClientMock();

		$this->assertCount(2, $mock->fetchLessons('roster-zermelo'));
	}//end testFetchLessonsReturnsTwoRecords()

	/**
	 * @return void
	 */
	public function testFetchLessonsMatchesRecordedFixtureShape(): void {
		$mock = new RosterImportClientMock();

		$this->assertSame($this->loadFixture(), $mock->fetchLessons('roster-zermelo'));
	}//end testFetchLessonsMatchesRecordedFixtureShape()

	/**
	 * @return void
	 */
	public function testFetchLessonsCarriesLessonFieldNames(): void {
		$mock = new RosterImportClientMock();

		foreach ($mock->fetchLessons('roster-xedule') as $lesson) {
			$this->assertArrayHasKey('subject', $lesson);
			$this->assertArrayHasKey('startsAt', $lesson);
			$this->assertArrayHasKey('endsAt', $lesson);
			$this->assertArrayHasKey('room', $lesson);
			$this->assertArrayHasKey('teacherReference', $lesson);
			$this->assertArrayHasKey('groupReference', $lesson);
		}
	}//end testFetchLessonsCarriesLessonFieldNames()

	/**
	 * @return void
	 */
	public function testFetchLessonsIsDeterministicRegardlessOfSystem(): void {
		$mock = new RosterImportClientMock();

		$this->assertSame($mock->fetchLessons('roster-untis-oneroster'), $mock->fetchLessons('roster-timeedit'));
	}//end testFetchLessonsIsDeterministicRegardlessOfSystem()
}//end class
