<?php

/**
 * The register fragment and the seed of the database source.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseStatement;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The source schema carries `database` and `statements`, its type description
 * says what dispatches `database`, and the seeded example is dormant and
 * declares a statement the adapter accepts.
 */
#[CoversNothing]
class DatabaseSourceDeclarationTest extends TestCase {

	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/sources-database-adapter.json';

	private const SEED = __DIR__ . '/../../../lib/Settings/integriq_seed_data.json';

	/**
	 * Decode one JSON file.
	 *
	 * @param string $path The file.
	 *
	 * @return array<string,mixed>
	 */
	private static function json(string $path): array {
		$decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);

		return $decoded;
	}

	/**
	 * The fragment adds the connection, the statements and the corrected type description.
	 *
	 * @return void
	 */
	public function testTheFragmentDeclaresTheConnectionAndTheStatements(): void {
		$source = self::json(self::FRAGMENT)['components']['schemas']['source'];

		self::assertSame('1.9.0', $source['version']);
		self::assertSame(['pdo_pgsql', 'pdo_mysql', 'pdo_sqlsrv'], $source['properties']['database']['properties']['driver']['enum']);
		self::assertArrayNotHasKey('password', $source['properties']['database']['properties'], 'the password is a credential reference, never a field');
		self::assertSame(['name', 'kind', 'sql'], $source['properties']['statements']['items']['required']);
		self::assertSame(['read', 'write'], $source['properties']['statements']['items']['properties']['kind']['enum']);

		$type = $source['properties']['type'];
		self::assertStringContainsString("'database' (a relational database read and written through the statements the source declares, dispatched by DatabaseAdapter, not via CallService)", $type['description']);
		self::assertStringNotContainsString("'file'", $type['description']);
		self::assertNotContains('file', $type['examples']);
		self::assertContains('database', $type['examples']);
	}//end testTheFragmentDeclaresTheConnectionAndTheStatements()

	/**
	 * The seeded example is dormant, has no credential, and its statement passes the adapter's checks.
	 *
	 * @return void
	 */
	public function testTheSeededExampleIsDormantAndValid(): void {
		$sources = array_values(array_filter(
			self::json(self::SEED)['source'],
			static fn (array $source): bool => ($source['@self']['slug'] ?? '') === 'example-postgres'
		));
		self::assertCount(1, $sources);
		$source = $sources[0];

		self::assertSame('database', $source['type']);
		self::assertFalse($source['isEnabled']);
		self::assertSame('db.example.nl', $source['database']['host']);
		self::assertArrayNotHasKey('configuration', $source, 'no credential is seeded');

		$statement = DatabaseStatement::fromDeclaration(declaration: $source['statements'][0]);
		self::assertSame('zaken-sinds', $statement->name);
		self::assertSame('SELECT id, zaaknummer, omschrijving FROM zaken WHERE id > :after ORDER BY id LIMIT :limit', $statement->sql);
		self::assertSame('id', $statement->pageKey);
		self::assertTrue($statement->isRead());
	}//end testTheSeededExampleIsDormantAndValid()
}//end class
