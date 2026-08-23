<?php

/**
 * Unit tests for StUFXMLBuilder.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/stuf-adapter/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\StUFXMLBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the StUF XML builder service.
 *
 * Verifies that npsLa01, adrLa01, zakLa01, Bv03 and Fo01/Fo03 XML messages
 * are correctly formed with the required StUF namespaces and stuurgegevens.
 */
class StUFXMLBuilderTest extends TestCase {

	/**
	 * The builder under test.
	 *
	 * @var StUFXMLBuilder
	 */
	private StUFXMLBuilder $builder;

	/**
	 * Default stuurgegevens for tests.
	 *
	 * @var array<string,string>
	 */
	private array $stuurgegevens;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->builder = new StUFXMLBuilder();

		$this->stuurgegevens = [
			'zenderOrganisatie' => '001122334',
			'zenderApplicatie' => 'OpenConnector',
			'ontvangerOrganisatie' => '998877665',
			'ontvangerApplicatie' => 'LegacyApp',
		];

	}//end setUp()

	/**
	 * Test that buildNpsLa01 produces valid StUF-BG XML with correct namespaces.
	 *
	 * @return void
	 */
	public function testBuildNpsLa01ContainsCorrectNamespaces(): void {
		$persons = [
			[
				'inp.bsn' => '999993653',
				'geslachtsnaam' => 'Moulin',
				'voornamen' => 'Suzanne',
				'geboortedatum' => '19900515',
			],
		];

		$xml = $this->builder->buildNpsLa01(
			persons: $persons,
			stuurgegevens: $this->stuurgegevens
		);

		$this->assertStringContainsString(
			needle: 'http://www.egem.nl/StUF/StUF0301',
			haystack: $xml,
			message: 'npsLa01 must declare the StUF namespace.'
		);
		$this->assertStringContainsString(
			needle: 'http://www.egem.nl/StUF/sector/bg/0310',
			haystack: $xml,
			message: 'npsLa01 must declare the BG namespace.'
		);
		$this->assertStringContainsString(
			needle: 'npsLa01',
			haystack: $xml,
			message: 'npsLa01 element must be present.'
		);

	}//end testBuildNpsLa01ContainsCorrectNamespaces()

	/**
	 * Test that buildNpsLa01 includes the person data fields.
	 *
	 * @return void
	 */
	public function testBuildNpsLa01ContainsPersonData(): void {
		$persons = [
			[
				'inp.bsn' => '999993653',
				'geslachtsnaam' => 'Moulin',
				'voornamen' => 'Suzanne',
			],
		];

		$xml = $this->builder->buildNpsLa01(
			persons: $persons,
			stuurgegevens: $this->stuurgegevens
		);

		$this->assertStringContainsString(needle: '999993653', haystack: $xml);
		$this->assertStringContainsString(needle: 'Moulin', haystack: $xml);
		$this->assertStringContainsString(needle: 'Suzanne', haystack: $xml);

	}//end testBuildNpsLa01ContainsPersonData()

	/**
	 * Test that buildNpsLa01 returns an empty antwoord for zero persons.
	 *
	 * @return void
	 */
	public function testBuildNpsLa01WithNoPersons(): void {
		$xml = $this->builder->buildNpsLa01(
			persons: [],
			stuurgegevens: $this->stuurgegevens
		);

		$this->assertStringContainsString(needle: '<StUF:aantalVoorkomens>0</StUF:aantalVoorkomens>', haystack: $xml);

	}//end testBuildNpsLa01WithNoPersons()

	/**
	 * Test that buildFo01 produces a valid SOAP fault with Fo01 error body.
	 *
	 * @return void
	 */
	public function testBuildFo01ProducesValidFault(): void {
		$xml = $this->builder->buildFo01(
			code: 'StUF046',
			omschrijving: 'Test error message.',
			stuurgegevens: $this->stuurgegevens
		);

		$this->assertStringContainsString(needle: 'SOAP-ENV:Fault', haystack: $xml);
		$this->assertStringContainsString(needle: 'Fo01Bericht', haystack: $xml);
		$this->assertStringContainsString(needle: 'StUF046', haystack: $xml);
		$this->assertStringContainsString(needle: 'Test error message.', haystack: $xml);

	}//end testBuildFo01ProducesValidFault()

	/**
	 * Test that buildAdrLa01 includes address data.
	 *
	 * @return void
	 */
	public function testBuildAdrLa01ContainsAddressData(): void {
		$addresses = [
			[
				'gor.straatnaam' => 'Hoofdstraat',
				'aoa.huisnummer' => '10',
				'aoa.postcode' => '1234AB',
				'wpl.woonplaatsNaam' => 'Utrecht',
			],
		];

		$xml = $this->builder->buildAdrLa01(
			addresses: $addresses,
			stuurgegevens: $this->stuurgegevens
		);

		$this->assertStringContainsString(needle: 'adrLa01', haystack: $xml);
		$this->assertStringContainsString(needle: 'Hoofdstraat', haystack: $xml);
		$this->assertStringContainsString(needle: '1234AB', haystack: $xml);

	}//end testBuildAdrLa01ContainsAddressData()

	/**
	 * Test that buildZakLa01 includes zaak data.
	 *
	 * @return void
	 */
	public function testBuildZakLa01ContainsZaakData(): void {
		$cases = [
			[
				'zaakidentificatie' => 'ZAAK-2024-001',
				'omschrijving' => 'Test zaak.',
				'zaaktype' => 'omgevingsvergunning',
			],
		];

		$xml = $this->builder->buildZakLa01(
			cases: $cases,
			stuurgegevens: $this->stuurgegevens
		);

		$this->assertStringContainsString(needle: 'zakLa01', haystack: $xml);
		$this->assertStringContainsString(needle: 'ZAAK-2024-001', haystack: $xml);
		$this->assertStringContainsString(needle: 'Test zaak.', haystack: $xml);

	}//end testBuildZakLa01ContainsZaakData()

	/**
	 * Test that buildBv03 includes the zaakidentificatie.
	 *
	 * @return void
	 */
	public function testBuildBv03ContainsZaakIdentificatie(): void {
		$xml = $this->builder->buildBv03(
			caseIdentification: 'ZAAK-2024-001',
			stuurgegevens: $this->stuurgegevens
		);

		$this->assertStringContainsString(needle: 'Bv03Bericht', haystack: $xml);
		$this->assertStringContainsString(needle: 'ZAAK-2024-001', haystack: $xml);

	}//end testBuildBv03ContainsZaakIdentificatie()

	/**
	 * Test that buildFo03 produces a valid SOAP fault with Fo03 error body.
	 *
	 * @return void
	 */
	public function testBuildFo03ProducesValidFault(): void {
		$xml = $this->builder->buildFo03(
			code: 'StUF050',
			omschrijving: 'Zaaktype is required.',
			stuurgegevens: $this->stuurgegevens
		);

		$this->assertStringContainsString(needle: 'SOAP-ENV:Fault', haystack: $xml);
		$this->assertStringContainsString(needle: 'Fo03Bericht', haystack: $xml);
		$this->assertStringContainsString(needle: 'StUF050', haystack: $xml);

	}//end testBuildFo03ProducesValidFault()

	/**
	 * Test that stuurgegevens are populated in npsLa01 responses.
	 *
	 * @return void
	 */
	public function testStuurgegevensArePopulated(): void {
		$xml = $this->builder->buildNpsLa01(
			persons: [],
			stuurgegevens: $this->stuurgegevens
		);

		$this->assertStringContainsString(needle: '001122334', haystack: $xml);
		$this->assertStringContainsString(needle: '998877665', haystack: $xml);
		$this->assertStringContainsString(needle: 'stuurgegevens', haystack: $xml);

	}//end testStuurgegevensArePopulated()

	/**
	 * Test that buildNpsLv01 produces a valid outbound query.
	 *
	 * @return void
	 */
	public function testBuildNpsLv01ProducesOutboundQuery(): void {
		$xml = $this->builder->buildNpsLv01(
			criteria: ['inp.bsn' => '999993653'],
			stuurgegevens: $this->stuurgegevens,
			maximumCount: 50
		);

		$this->assertStringContainsString(needle: 'npsLv01', haystack: $xml);
		$this->assertStringContainsString(needle: '999993653', haystack: $xml);
		$this->assertStringContainsString(needle: '50', haystack: $xml);

	}//end testBuildNpsLv01ProducesOutboundQuery()

	/**
	 * Test that scope filtering excludes unrequested fields.
	 *
	 * @return void
	 */
	public function testNpsLa01WithScopeFiltersFields(): void {
		$persons = [
			[
				'inp.bsn' => '999993653',
				'geslachtsnaam' => 'Moulin',
				'geboortedatum' => '19900515',
			],
		];

		$xml = $this->builder->buildNpsLa01(
			persons: $persons,
			stuurgegevens: $this->stuurgegevens,
			scope: ['inp.bsn', 'geslachtsnaam']
		);

		$this->assertStringContainsString(needle: '999993653', haystack: $xml);
		$this->assertStringContainsString(needle: 'Moulin', haystack: $xml);
		$this->assertStringNotContainsString(needle: '19900515', haystack: $xml);

	}//end testNpsLa01WithScopeFiltersFields()
}//end class
