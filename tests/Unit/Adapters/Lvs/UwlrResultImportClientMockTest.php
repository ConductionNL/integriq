<?php

/**
 * Unit tests for the LVS UWLR *ClientMock dormant implementation.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Lvs
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Lvs;

use OCA\Integriq\Adapters\Lvs\UwlrResultImportClient;
use OCA\Integriq\Adapters\Lvs\UwlrResultImportClientMock;
use PHPUnit\Framework\TestCase;

/**
 * Lock the canned UWLR result batch for the dormant LVS import client,
 * against the recorded/representative fixture at
 * tests/fixtures/lvs/fixture-uwlr-result-batch.json.
 */
class UwlrResultImportClientMockTest extends TestCase {
	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function loadFixture(): array {
		$path = __DIR__ . '/../../../fixtures/lvs/fixture-uwlr-result-batch.json';
		$decoded = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($decoded);
		return $decoded['results'];
	}//end loadFixture()

	/**
	 * @return void
	 */
	public function testMockExtendsAbstractClient(): void {
		$mock = new UwlrResultImportClientMock();

		$this->assertInstanceOf(UwlrResultImportClient::class, $mock);
		$this->assertSame('mock', $mock->flavour());
	}//end testMockExtendsAbstractClient()

	/**
	 * @return void
	 */
	public function testFetchResultsReturnsThreeRecords(): void {
		$mock = new UwlrResultImportClientMock();

		$batch = $mock->fetchResults('lvs-boom');

		$this->assertCount(3, $batch);
	}//end testFetchResultsReturnsThreeRecords()

	/**
	 * @return void
	 */
	public function testFetchResultsMatchesRecordedFixtureShape(): void {
		$mock = new UwlrResultImportClientMock();
		$batch = $mock->fetchResults('lvs-boom');
		$fixture = $this->loadFixture();

		$this->assertSame($fixture, $batch);
	}//end testFetchResultsMatchesRecordedFixtureShape()

	/**
	 * @return void
	 */
	public function testFetchResultsCarriesUwlrFieldNames(): void {
		$mock = new UwlrResultImportClientMock();
		$batch = $mock->fetchResults('lvs-cito-dult');

		foreach ($batch as $record) {
			$this->assertArrayHasKey('leerlingReference', $record);
			$this->assertArrayHasKey('toetscode', $record);
			$this->assertArrayHasKey('referentieniveau', $record);
			$this->assertArrayHasKey('vaardigheidsscore', $record);
			$this->assertArrayHasKey('afnamedatum', $record);
			$this->assertArrayHasKey('groep', $record);
		}
	}//end testFetchResultsCarriesUwlrFieldNames()

	/**
	 * @return void
	 */
	public function testFetchResultsIsDeterministicRegardlessOfSupplier(): void {
		$mock = new UwlrResultImportClientMock();

		$this->assertSame($mock->fetchResults('lvs-iep'), $mock->fetchResults('lvs-dia'));
	}//end testFetchResultsIsDeterministicRegardlessOfSupplier()
}//end class
