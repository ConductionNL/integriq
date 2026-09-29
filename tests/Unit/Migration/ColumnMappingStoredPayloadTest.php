<?php

/**
 * The column mapping the migration page stores is one the register accepts
 * and the file source reads back (migration-source-adapters, REQ-MSA-002).
 *
 * The page writes tests/fixtures/migration/column-mapping-stored-payload.json
 * exactly (tests/vitest/migrationColumnMapping.spec.js compares its output with
 * that file). Here the same file is validated against the merged
 * `column_mapping` schema the way OpenRegister validates a save, and read back
 * through ColumnMapping, the shape FileMigrationSource reads a delivery with.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Migration;

use OCA\Integriq\Migration\ColumnMapping;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the stored column mapping payload.
 */
class ColumnMappingStoredPayloadTest extends TestCase {

	/**
	 * The payload the page writes.
	 *
	 * @return array The payload.
	 */
	private function payload(): array {
		$path = dirname(__DIR__, 2) . '/fixtures/migration/column-mapping-stored-payload.json';
		return json_decode((string)file_get_contents($path), true);
	}//end payload()

	/**
	 * The register stores what the page writes.
	 *
	 * @return void
	 */
	public function testTheRegisterAcceptsTheMappingThePageStores(): void {
		$this->assertSame([], RegisterSchemaValidator::errors('column_mapping', $this->payload()));
	}//end testTheRegisterAcceptsTheMappingThePageStores()

	/**
	 * A mapping with no columns, or a column mapped onto something that is
	 * not a field name, is refused by the register.
	 *
	 * @return void
	 */
	public function testTheRegisterRefusesAMappingWithoutColumns(): void {
		$payload = $this->payload();
		unset($payload['columns']);
		$this->assertNotSame([], RegisterSchemaValidator::errors('column_mapping', $payload));

		$payload = $this->payload();
		$payload['columns']['plaats'] = ['city'];
		$this->assertNotSame([], RegisterSchemaValidator::errors('column_mapping', $payload));
	}//end testTheRegisterRefusesAMappingWithoutColumns()

	/**
	 * The stored object reads back as the mapping a second delivery is read
	 * through, with its version and identifier column.
	 *
	 * @return void
	 */
	public function testTheStoredMappingReadsBackForTheNextDelivery(): void {
		$mapping = ColumnMapping::fromArray($this->payload());

		$this->assertSame(3, $mapping->getVersion());
		$this->assertSame('zaaknummer', $mapping->getIdentifierColumn());
		$this->assertSame(
			['reference' => 'Z-3', 'requesterName' => 'Bakker', 'city' => 'Veenendaal'],
			$mapping->apply(['zaaknummer' => 'Z-3', 'naam' => 'Bakker', 'plaats' => 'Veenendaal'])
		);
	}//end testTheStoredMappingReadsBackForTheNextDelivery()
}//end class
