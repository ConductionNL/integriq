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
	public function testUnknownPresetExceptionCarriesThePresetIdAndKnownIds(): void {
		$registry = new MigrationMappingPresetRegistry();

		try {
			$registry->get('does-not-exist');
			$this->fail('Expected UnknownMigrationMappingPresetException was not thrown.');
		} catch (UnknownMigrationMappingPresetException $exception) {
			$this->assertSame('does-not-exist', $exception->getPresetId());
			$this->assertStringContainsString('does-not-exist', $exception->getMessage());
			$this->assertStringContainsString('parnassys-export', $exception->getMessage());
		}
	}//end testUnknownPresetExceptionCarriesThePresetIdAndKnownIds()

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

	/**
	 * The constructor MUST skip a non-array row, a row with no `mapping`
	 * object and a row with no id, rather than crash or silently seed a
	 * broken preset — only the one fully well-formed row survives.
	 *
	 * @return void
	 */
	public function testConstructorSkipsEveryMalformedRowAndKeepsTheValidOne(): void {
		$fixture = __DIR__ . '/../../fixtures/migration-mapping-presets/fixture-malformed-presets.json';
		$registry = new MigrationMappingPresetRegistry($fixture);

		$this->assertSame(['valid-preset'], $registry->ids());
		$this->assertNotContains('no-mapping-vendor', $registry->ids());
	}//end testConstructorSkipsEveryMalformedRowAndKeepsTheValidOne()

	/**
	 * @return void
	 */
	public function testConstructorAcceptsAnExplicitSeedPathOverride(): void {
		$fixture = __DIR__ . '/../../fixtures/migration-mapping-presets/fixture-malformed-presets.json';
		$registry = new MigrationMappingPresetRegistry($fixture);

		$mapping = $registry->get('valid-preset');

		$this->assertInstanceOf(ColumnMapping::class, $mapping);
		$this->assertSame('pupil', $mapping->getKind());
	}//end testConstructorAcceptsAnExplicitSeedPathOverride()
}//end class
