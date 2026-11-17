<?php

/**
 * Microsecond timestamps fit the column OpenRegister creates for them.
 *
 * OpenRegister makes a PostgreSQL `integer` (32 bit) column for an integer
 * property without a `maximum`, and only a `maximum` above 2147483647 gives a
 * `bigint`. A microsecond epoch is about 1.8e15, so an unbounded one makes
 * every insert fail with "value out of range for type integer".
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution-trace-object-per-execution-req-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every integer property named `*Us` declares a maximum that gives a bigint.
 *
 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution-trace-object-per-execution-req-004
 */
class MicrosecondColumnsFitTest extends TestCase {

	/**
	 * The largest value OpenRegister still stores in a 32-bit integer column.
	 *
	 * @var integer
	 */
	private const INT32_MAX = 2147483647;

	/**
	 * Each microsecond property in the register and its fragments is bounded above 32 bits.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution-trace-object-per-execution-req-004
	 */
	public function testMicrosecondPropertiesGetABigintColumn(): void {
		$settings = __DIR__ . '/../../../lib/Settings';
		$files = array_merge([$settings . '/integriq_register.json'], (glob($settings . '/register.d/*.json') ?: []));

		$checked = [];
		foreach ($files as $file) {
			$descriptor = json_decode((string)file_get_contents($file), true);
			$schemas = ($descriptor['components']['schemas'] ?? []);
			foreach ($schemas as $schemaName => $schema) {
				foreach (($schema['properties'] ?? []) as $name => $property) {
					if (is_array($property) === false || ($property['type'] ?? null) !== 'integer' || str_ends_with((string)$name, 'Us') === false) {
						continue;
					}

					$label = basename($file) . ' ' . $schemaName . '.' . $name;
					$checked[] = $label;
					$this->assertGreaterThan(self::INT32_MAX, ($property['maximum'] ?? 0), $label . ' needs a maximum above 32 bits');
				}
			}
		}

		$this->assertContains('execution-trace-observability.json execution_trace.startedAtUs', $checked);
		$this->assertContains('execution-trace-observability.json execution_trace.finishedAtUs', $checked);
	}//end testMicrosecondPropertiesGetABigintColumn()

}//end class
