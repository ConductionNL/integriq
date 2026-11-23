<?php

/**
 * Every property in the register and its fragments declares one type.
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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister refuses a schema with a union type (`"type": ["string", "null"]`)
 * at any depth, and the import then leaves that schema out of the register.
 * A nullable value is `"nullable": true`; a value of more than one type omits
 * `type` (allowed below the top level).
 */
#[CoversNothing]
class RegisterDeclaresNoUnionTypesTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * The register file and every fragment under register.d.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function registerFiles(): array {
		$files = array_merge(
			[self::SETTINGS . '/integriq_register.json'],
			(glob(self::SETTINGS . '/register.d/*.json') ?: [])
		);

		$cases = [];
		foreach ($files as $file) {
			$cases[basename($file)] = [$file];
		}

		return $cases;
	}//end registerFiles()

	/**
	 * Collect the paths of properties whose `type` is a list.
	 *
	 * @param array<mixed> $node The property definition.
	 * @param string       $path Its path.
	 *
	 * @return list<string>
	 */
	private static function unionTypes(array $node, string $path): array {
		$found = [];
		if (isset($node['type']) === true && is_array($node['type']) === true) {
			$found[] = $path;
		}

		if (isset($node['items']) === true && is_array($node['items']) === true) {
			$found = array_merge($found, self::unionTypes($node['items'], $path . '/items'));
		}

		if (isset($node['properties']) === true && is_array($node['properties']) === true) {
			foreach ($node['properties'] as $name => $property) {
				if (is_array($property) === true) {
					$found = array_merge($found, self::unionTypes($property, $path . '/' . $name));
				}
			}
		}

		return $found;
	}//end unionTypes()

	/**
	 * No schema in the file declares a union type.
	 *
	 * @param string $file The register file or fragment.
	 *
	 * @return void
	 *
	 * @dataProvider registerFiles
	 */
	#[DataProvider('registerFiles')]
	public function testNoPropertyDeclaresAUnionType(string $file): void {
		$decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);

		$found = [];
		foreach (($decoded['components']['schemas'] ?? []) as $slug => $schema) {
			if (is_array($schema) === true) {
				$found = array_merge($found, self::unionTypes($schema, (string) $slug));
			}
		}

		self::assertSame([], $found, 'OpenRegister rejects these schemas on import');
	}//end testNoPropertyDeclaresAUnionType()
}//end class
