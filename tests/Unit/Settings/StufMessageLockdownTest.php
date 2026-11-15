<?php

/**
 * StUF message lockdown regression test (integriq#2643 review, iq-f1).
 *
 * A retrying ZDS kennisgeving keeps its envelope (zaak, betrokkenen with BSN)
 * on its `stuf_message` row, and the schema shipped with NO `authorization`
 * block, so OpenRegister's generic object API handed every row to any
 * signed-in account. Every verb is admin-only now; integriq's own code reads
 * and writes the rows with `_rbac: false`.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class StufMessageLockdownTest extends TestCase {
	/**
	 * The effective `stuf_message` schema, base register merged with its register.d fragments.
	 *
	 * @return array<string, mixed>
	 */
	private function effectiveSchema(): array {
		$root = dirname(__DIR__, 3);

		$base = json_decode((string)file_get_contents($root . '/lib/Settings/integriq_register.json'), true);
		$schema = $base['components']['schemas']['stuf_message'];

		foreach (glob($root . '/lib/Settings/register.d/*.json') as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			$overlay = ($fragment['components']['schemas']['stuf_message'] ?? null);
			if (is_array($overlay) === false) {
				continue;
			}

			foreach ($overlay as $key => $value) {
				if (in_array($key, ['properties', 'authorization'], true) === true && isset($schema[$key]) === true) {
					$schema[$key] = array_merge($schema[$key], $value);
					continue;
				}

				$schema[$key] = $value;
			}
		}

		return $schema;
	}//end effectiveSchema()

	/**
	 * Every verb is admin-only: no signed-in account reads, creates, updates or deletes a message row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-credentials-stay-encrypted
	 */
	public function testEveryVerbOnTheStufMessageSchemaIsAdminOnly(): void {
		$schema = $this->effectiveSchema();
		$this->assertArrayHasKey('authorization', $schema, 'stuf_message MUST declare authorization; without it every account reads every row.');

		foreach (['create', 'read', 'update', 'delete'] as $verb) {
			$this->assertSame(['admin'], ($schema['authorization'][$verb] ?? null), "The `$verb` verb on stuf_message must be admin-only");
		}

		$serialised = (string)json_encode($schema['authorization']);
		$this->assertStringNotContainsString('"public"', $serialised);
		$this->assertStringNotContainsString('"authenticated"', $serialised);
	}//end testEveryVerbOnTheStufMessageSchemaIsAdminOnly()
}//end class
