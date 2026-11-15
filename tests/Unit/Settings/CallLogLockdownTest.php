<?php

/**
 * Call-log lockdown regression test (Q-integriq-1, decision 137).
 *
 * The `call_log` schema shipped with NO `authorization` block, so OpenRegister's
 * generic object API handed every call record to any signed-in account. A failed
 * call now keeps the request it sent (`replayRequest`, secrets redacted, personal
 * data not), and CallLogController hides that field from readers without the replay
 * permission, but the generic API bypasses the controller. So every verb on the
 * schema is admin-only: admins read the call log pages, and integriq's own code
 * reads and writes through `_rbac: false` behind its call-log permissions.
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

class CallLogLockdownTest extends TestCase {
	/**
	 * The effective `call_log` schema, base register merged with its register.d fragments.
	 *
	 * @return array<string, mixed>
	 */
	private function effectiveCallLogSchema(): array {
		$root = dirname(__DIR__, 3);

		$base = json_decode((string)file_get_contents($root . '/lib/Settings/integriq_register.json'), true);
		$schema = $base['components']['schemas']['call_log'];

		foreach (glob($root . '/lib/Settings/register.d/*.json') as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			$overlay = ($fragment['components']['schemas']['call_log'] ?? null);
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
	}//end effectiveCallLogSchema()

	/**
	 * No authorization block means any signed-in account reads every call record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-call-records-are-readable-only-by-admins-and-through-integriqs-own-endpoints-req-ocd-012
	 */
	public function testCallLogSchemaIsNotWorldReadable(): void {
		$this->assertArrayHasKey(
			'authorization',
			$this->effectiveCallLogSchema(),
			'call_log MUST declare authorization; without it OpenRegister lets every account read every call.'
		);
	}//end testCallLogSchemaIsNotWorldReadable()

	/**
	 * Every verb is admin-only: a non-admin generic-API read is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-call-records-are-readable-only-by-admins-and-through-integriqs-own-endpoints-req-ocd-012
	 */
	public function testEveryVerbOnTheCallLogSchemaIsAdminOnly(): void {
		$authorization = $this->effectiveCallLogSchema()['authorization'];

		foreach (['create', 'read', 'update', 'delete'] as $verb) {
			$this->assertArrayHasKey($verb, $authorization, "The `$verb` verb must be constrained");
			$this->assertSame(['admin'], $authorization[$verb], "The `$verb` verb on call_log must be admin-only");
		}
	}//end testEveryVerbOnTheCallLogSchemaIsAdminOnly()

	/**
	 * `public` and `authenticated` would reopen the hole.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-call-records-are-readable-only-by-admins-and-through-integriqs-own-endpoints-req-ocd-012
	 */
	public function testTheCallLogSchemaGrantsNeitherPublicNorAuthenticated(): void {
		$serialised = (string)json_encode($this->effectiveCallLogSchema()['authorization']);

		$this->assertStringNotContainsString('"public"', $serialised);
		$this->assertStringNotContainsString('"authenticated"', $serialised);
	}//end testTheCallLogSchemaGrantsNeitherPublicNorAuthenticated()
}//end class
