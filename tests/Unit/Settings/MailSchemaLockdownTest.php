<?php

/**
 * The ten schemas from the #1983 review (blocker 3) must not be world-readable.
 *
 * The original defect was not a wrong value — it was an ABSENT declaration, which
 * OpenRegister reads as "anyone may read". Nothing detected the omission, which is
 * how ten schemas shipped open while every gate stayed green. This test is that
 * detector.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

class MailSchemaLockdownTest extends TestCase {

	/**
	 * Every schema the review found open, including `sender_identity`, which is
	 * closed by its own fragment because it also held a secret.
	 *
	 * @var array<int,string>
	 */
	private const LOCKED_SCHEMAS = [
		'digitalPostMessage',
		'intake_message',
		'intake_routing_rule',
		'mail_message',
		'mapping_version',
		'outbound_message',
		'recipient_key',
		'recipient_opt_out',
		'sender_identity',
		'verdict',
	];

	/**
	 * Every action that must be declared, so none falls through to the default.
	 *
	 * @var array<int,string>
	 */
	private const ACTIONS = ['create', 'read', 'update', 'delete'];

	/**
	 * One schema as it is at runtime: base register merged with its register.d
	 * fragments, the same way InitializeRegister merges them.
	 *
	 * @param string $name The schema slug.
	 *
	 * @return array<string, mixed> The effective schema.
	 */
	private function effectiveSchema(string $name): array {
		$root = dirname(__DIR__, 3);

		$base = json_decode((string)file_get_contents($root . '/lib/Settings/integriq_register.json'), true);
		$schema = ($base['components']['schemas'][$name] ?? []);

		foreach (glob($root . '/lib/Settings/register.d/*.json') as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			$overlay = ($fragment['components']['schemas'][$name] ?? null);
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
	}//end effectiveSchema()

	/**
	 * Each schema declares an authorization block at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010
	 */
	public function testEverySchemaDeclaresAnAuthorizationBlock(): void {
		foreach (self::LOCKED_SCHEMAS as $name) {
			$schema = $this->effectiveSchema(name: $name);

			$this->assertArrayHasKey(
				'authorization',
				$schema,
				$name . ' has no authorization block, which OpenRegister reads as "anyone may read".'
			);
		}
	}//end testEverySchemaDeclaresAnAuthorizationBlock()

	/**
	 * THE ONE THAT MATTERS: the block is non-empty.
	 *
	 * `"authorization": {}` satisfies the test above and closes NOTHING —
	 * `empty($authorization)` takes exactly the same default-OPEN branch in
	 * PermissionHandler as no block at all. Asserting a block exists would pass
	 * against the bug it is supposed to catch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010
	 */
	public function testNoSchemaUsesAnEmptyBlockWhichWouldCloseNothing(): void {
		foreach (self::LOCKED_SCHEMAS as $name) {
			$authorization = ($this->effectiveSchema(name: $name)['authorization'] ?? []);

			$this->assertNotSame(
				[],
				$authorization,
				$name . ' declares an EMPTY BLOCK. That is default-OPEN, not closed — '
				. 'the rule lists must be present and empty instead.'
			);
		}
	}//end testNoSchemaUsesAnEmptyBlockWhichWouldCloseNothing()

	/**
	 * Every action is declared, and every rule list is empty — "grant to nobody",
	 * leaving only the admin and object-owner bypasses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010
	 */
	public function testEveryActionIsDeclaredAndGrantedToNobody(): void {
		foreach (self::LOCKED_SCHEMAS as $name) {
			$authorization = ($this->effectiveSchema(name: $name)['authorization'] ?? []);

			foreach (self::ACTIONS as $action) {
				$this->assertArrayHasKey(
					$action,
					$authorization,
					$name . ' does not declare "' . $action . '".'
				);
				$this->assertSame(
					[],
					$authorization[$action],
					$name . '.' . $action . ' must be an empty list, which reads as "grant to nobody".'
				);
			}
		}
	}//end testEveryActionIsDeclaredAndGrantedToNobody()

	/**
	 * The declaration arrives by fragment: the base register is untouched, so a
	 * register regeneration cannot quietly drop it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010
	 */
	public function testTheLockdownLivesInAFragmentNotTheBaseRegister(): void {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root . '/lib/Settings/integriq_register.json'), true);

		foreach (self::LOCKED_SCHEMAS as $name) {
			$this->assertArrayNotHasKey(
				'authorization',
				($base['components']['schemas'][$name] ?? []),
				$name . ' declares authorization in the base register; it belongs in a register.d fragment.'
			);
		}
	}//end testTheLockdownLivesInAFragmentNotTheBaseRegister()

	/**
	 * Each fragment says WHY, per the convention catalog-item-schema.json sets.
	 *
	 * A deliberate authorization choice that carries no reasoning is
	 * indistinguishable from an oversight to the next reviewer — which is exactly
	 * how these ten schemas were read the first time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010
	 */
	public function testEachLockdownFragmentExplainsItself(): void {
		$root = dirname(__DIR__, 3);

		foreach (['99-mail-schemas-lockdown.json', '99-sender-identity-lockdown.json'] as $file) {
			$path = ($root . '/lib/Settings/register.d/' . $file);
			$this->assertFileExists($path);

			$fragment = json_decode((string)file_get_contents($path), true);
			$comment = (string)($fragment['_comment'] ?? '');

			$this->assertNotSame('', $comment, $file . ' carries no _comment.');
			$this->assertStringContainsString(
				'1983',
				$comment,
				$file . ' should name the finding it closes.'
			);
		}
	}//end testEachLockdownFragmentExplainsItself()
}//end class
