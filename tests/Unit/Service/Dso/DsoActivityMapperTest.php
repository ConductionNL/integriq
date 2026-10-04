<?php

/**
 * Unit tests for DsoActivityMapper.
 *
 * Moved with the methods from DSOAdapterServiceTest when DSOAdapterService
 * was retired (change dso-attachments-on-the-request, task 3.1).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-activiteiten-to-zaaktype-mapping-req-dso-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Dso;

use OCA\Integriq\Service\Dso\DsoActivityMapper;
use PHPUnit\Framework\TestCase;

/**
 * Tests the activiteiten mapping, the default mapping table and the samenloop decision.
 *
 * @spec openspec/specs/dso-omgevingsloket/spec.md#requirement-samenloop-handling-req-dso-011
 */
class DsoActivityMapperTest extends TestCase {

	/**
	 * The mapper under test.
	 *
	 * @var DsoActivityMapper
	 */
	private DsoActivityMapper $mapper;

	/**
	 * Set up the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new DsoActivityMapper();

	}//end setUp()

	/**
	 * Test activiteiten mapping with a known code.
	 *
	 * @return void
	 */
	public function testMapActiviteitenToZaaktypenMapsKnownCode(): void {
		$activiteiten = [
			['code' => 'bouwen-01', 'omschrijving' => 'Bouwen'],
		];

		$mappingTable = [
			'bouwen-01' => [
				'zaaktypeIdentificatie' => 'ZAAKTYPE-BOUWEN-2024',
				'samenloopStrategie' => 'deelzaken',
			],
		];

		$result = $this->mapper->mapActiviteitenToZaaktypen(
			activiteiten: $activiteiten,
			mappingTable: $mappingTable
		);

		$this->assertCount(1, $result['mapped']);
		$this->assertCount(0, $result['unmapped']);
		$this->assertSame('ZAAKTYPE-BOUWEN-2024', $result['mapped'][0]['zaaktypeIdentificatie']);

	}//end testMapActiviteitenToZaaktypenMapsKnownCode()

	/**
	 * Test activiteiten mapping with an unknown code.
	 *
	 * @return void
	 */
	public function testMapActiviteitenToZaaktypenReturnsUnmappedForUnknownCode(): void {
		$activiteiten = [
			['code' => 'onbekend-activiteit-2025'],
		];

		$result = $this->mapper->mapActiviteitenToZaaktypen(
			activiteiten: $activiteiten,
			mappingTable: []
		);

		$this->assertCount(0, $result['mapped']);
		$this->assertCount(1, $result['unmapped']);
		$this->assertFalse($result['unmapped'][0]['mapped']);

	}//end testMapActiviteitenToZaaktypenReturnsUnmappedForUnknownCode()

	/**
	 * Test default mappings contain 25+ entries.
	 *
	 * @return void
	 */
	public function testGetDefaultMappingsReturns25PlusMappings(): void {
		$mappings = $this->mapper->getDefaultMappings();

		$this->assertGreaterThanOrEqual(25, count($mappings));

		// Each entry must have required fields.
		foreach ($mappings as $mapping) {
			$this->assertArrayHasKey('dsoActiviteitCode', $mapping);
			$this->assertArrayHasKey('zaaktypeIdentificatie', $mapping);
			$this->assertArrayHasKey('samenloopStrategie', $mapping);
			$this->assertArrayHasKey('isActief', $mapping);
		}

	}//end testGetDefaultMappingsReturns25PlusMappings()

	/**
	 * Test samenloop strategy returns deelzaken by default.
	 *
	 * @return void
	 */
	public function testDetermineSamenloopStrategyDefaultsToDeelzaken(): void {
		$result = $this->mapper->determineSamenloopStrategy(mappedActiviteiten: []);

		$this->assertSame('deelzaken', $result);

	}//end testDetermineSamenloopStrategyDefaultsToDeelzaken()

	/**
	 * Test samenloop strategy returns gecombineerd when all activiteiten use that strategy.
	 *
	 * @return void
	 */
	public function testDetermineSamenloopStrategyReturnsGecombineerdWhenAllMatch(): void {
		$activiteiten = [
			['samenloopStrategie' => 'gecombineerd'],
			['samenloopStrategie' => 'gecombineerd'],
		];

		$result = $this->mapper->determineSamenloopStrategy(
			mappedActiviteiten: $activiteiten
		);

		$this->assertSame('gecombineerd', $result);

	}//end testDetermineSamenloopStrategyReturnsGecombineerdWhenAllMatch()

	/**
	 * Test samenloop strategy returns deelzaken when any activiteit is deelzaken.
	 *
	 * @return void
	 */
	public function testDetermineSamenloopStrategyReturnsDeelzakenWhenMixed(): void {
		$activiteiten = [
			['samenloopStrategie' => 'gecombineerd'],
			['samenloopStrategie' => 'deelzaken'],
		];

		$result = $this->mapper->determineSamenloopStrategy(
			mappedActiviteiten: $activiteiten
		);

		$this->assertSame('deelzaken', $result);

	}//end testDetermineSamenloopStrategyReturnsDeelzakenWhenMixed()
}//end class
