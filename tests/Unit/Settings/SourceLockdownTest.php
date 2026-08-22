<?php

/**
 * Source-schema lockdown regression test (ocon#147).
 *
 * The `source` schema shipped with NO `authorization` block, so it fell back to
 * OpenRegister's default — readable by ANY authenticated user. Because OpenRegister has no
 * field-level redaction (openregister#380), that meant
 * `GET /apps/openregister/api/objects/openconnector/source` handed every Source's
 * `apikey`, `secret`, `password` and `jwt` to any account on the instance, in cleartext.
 *
 * Verified on 2026-07-13 by reading them as a brand-new user in zero groups. On a
 * municipality install, that table holds the BRP/HaalCentraal keys, the ZGW credentials and
 * the SMS-gateway tokens.
 *
 * `SourcesController`'s redaction was real — but it only ever guarded its own endpoint. The
 * generic OpenRegister object API reads the same rows and bypasses it entirely, which is
 * exactly why "we redact in the controller" is not a defence.
 *
 * These tests pin the schema-level control, so the hole cannot be reopened by an edit to
 * the register that quietly drops the authorization block.
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

class SourceLockdownTest extends TestCase {
	/**
	 * The effective `source` schema, base register merged with its register.d fragments.
	 *
	 * @return array<string, mixed>
	 */
	private function effectiveSourceSchema(): array {
		$root = dirname(__DIR__, 3);

		$base = json_decode((string)file_get_contents($root . '/lib/Settings/openconnector_register.json'), true);
		$schema = $base['components']['schemas']['source'];

		foreach (glob($root . '/lib/Settings/register.d/*.json') as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			$overlay = ($fragment['components']['schemas']['source'] ?? null);
			if (is_array($overlay) === false) {
				continue;
			}

			foreach ($overlay as $key => $value) {
				if ($key === 'properties' && isset($schema['properties']) === true) {
					$schema['properties'] = array_merge($schema['properties'], $value);
					continue;
				}

				$schema[$key] = $value;
			}
		}

		return $schema;
	}//end effectiveSourceSchema()

	/**
	 * The whole bug: no `authorization` block at all meant "anyone may read".
	 *
	 * @return void
	 */
	public function testSourceSchemaIsNotWorldReadable(): void {
		$schema = $this->effectiveSourceSchema();

		$this->assertArrayHasKey(
			'authorization',
			$schema,
			'The source schema MUST declare authorization. With none, OpenRegister defaults to '
			. 'readable-by-any-authenticated-user, which is how every Source credential leaked (ocon#147).'
		);
	}//end testSourceSchemaIsNotWorldReadable()

	/**
	 * Sources are admin-owned configuration: an operator configures them, and the ENGINE —
	 * not the end user — consumes them. Every verb is admin-only.
	 *
	 * @return void
	 */
	public function testEveryVerbOnTheSourceSchemaIsAdminOnly(): void {
		$authorization = $this->effectiveSourceSchema()['authorization'];

		foreach (['create', 'read', 'update', 'delete'] as $verb) {
			$this->assertArrayHasKey($verb, $authorization, "The `$verb` verb must be constrained");
			$this->assertSame(
				['admin'],
				$authorization[$verb],
				"The `$verb` verb on the source schema must be admin-only — a Source carries credentials"
			);
		}
	}//end testEveryVerbOnTheSourceSchemaIsAdminOnly()

	/**
	 * `public` and `authenticated` are the two grants that would reopen the hole. Neither
	 * may ever appear on this schema.
	 *
	 * @return void
	 */
	public function testTheSourceSchemaGrantsNeitherPublicNorAuthenticated(): void {
		$authorization = $this->effectiveSourceSchema()['authorization'];

		$serialised = json_encode($authorization);

		$this->assertStringNotContainsString(
			'"public"',
			(string)$serialised,
			'A `public` grant on the source schema would expose every credential to anonymous callers'
		);
		$this->assertStringNotContainsString(
			'"authenticated"',
			(string)$serialised,
			'An `authenticated` grant on the source schema is exactly the bug ocon#147 fixed — it '
			. 'means any account on the instance can read every Source credential'
		);
	}//end testTheSourceSchemaGrantsNeitherPublicNorAuthenticated()
}//end class
