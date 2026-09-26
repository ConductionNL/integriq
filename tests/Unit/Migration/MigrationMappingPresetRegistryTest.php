<?php

/**
 * Unit tests for the migration mapping preset registry.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Migration;

use OCA\Integriq\Migration\ColumnMapping;
use OCA\Integriq\Migration\MigrationMappingPresetRegistry;
use OCA\Integriq\Migration\UnknownMigrationMappingPresetException;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests against the seeded `lib/migration-mapping-presets.seed.json`.
 */
class MigrationMappingPresetRegistryTest extends TestCase {
	/**
	 * @return void
	 */
	public function testDescribeAllReturnsExactlyFourPresets(): void {
		$registry = new MigrationMappingPresetRegistry();

		$this->assertCount(4, $registry->describeAll());
		$this->assertSame(
			['parnassys-export', 'esis-export', 'magister-export', 'somtoday-export'],
			$registry->ids()
		);
	}//end testDescribeAllReturnsExactlyFourPresets()

	/**
	 * @return void
	 */
	public function testGetReturnsUsableColumnMapping(): void {
		$registry = new MigrationMappingPresetRegistry();

		$mapping = $registry->get('parnassys-export');

		$this->assertInstanceOf(ColumnMapping::class, $mapping);
		$this->assertSame('pupil', $mapping->getKind());
		$this->assertNotSame('', $mapping->getIdentifierColumn());
	}//end testGetReturnsUsableColumnMapping()

	/**
	 * @return void
	 */
	public function testGetThrowsForUnknownPresetId(): void {
		$registry = new MigrationMappingPresetRegistry();

		$this->expectException(UnknownMigrationMappingPresetException::class);
		$registry->get('does-not-exist');
	}//end testGetThrowsForUnknownPresetId()

	/**
	 * @return void
	 */
	public function testDescribeAllSerialisesMappingAsArray(): void {
		$registry = new MigrationMappingPresetRegistry();

		$descriptions = $registry->describeAll();
		$somtoday = null;
		foreach ($descriptions as $description) {
			if ($description['id'] === 'somtoday-export') {
				$somtoday = $description;
			}
		}

		$this->assertIsArray($somtoday);
		$this->assertSame('Somtoday', $somtoday['sourceSystem']);
		$this->assertIsArray($somtoday['mapping']);
		$this->assertSame('pupil', $somtoday['mapping']['kind']);
	}//end testDescribeAllSerialisesMappingAsArray()
}//end class
