<?php

/**
 * A ratchet on schema-level authorization coverage.
 *
 * Of the 67 schemas in the effective register, 16 declare a non-empty
 * `authorization` block and 51 do not (integriq#2104 review 5264751700). In
 * OpenRegister an absent block is not deny: `PermissionHandler` treats it as
 * default-OPEN for reads, because `read` is absent from
 * DEFAULT_CLOSED_WRITE_ACTIONS. So each of those 51 is readable by every
 * authenticated account on the instance.
 *
 * Closing them is not this test's job and cannot be done blind — who should read
 * `iwmo_ijw_message` or `openformulieren_submission` is a functional decision,
 * tracked from ConductionNL/integriq#2105. What this test does is stop the
 * number going the wrong way: a NEW schema that ships without a block fails
 * here, and a schema that LOSES its block fails here. Both are one-line changes
 * to the lists below, which is the point — closing a schema, or knowingly
 * shipping an open one, becomes a deliberate edit with a reviewer rather than an
 * omission nobody sees.
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

class SchemaAuthorizationRatchetTest extends TestCase {

	/**
	 * Schemas that DO declare a non-empty `authorization` block.
	 *
	 * Removing one from this list is removing an access control. Do not edit it
	 * to make a failing build green.
	 *
	 * @var array<int,string>
	 */
	private const CLOSED = [
		'app_connection',
		'consumer',
		'digitalPostMessage',
		'intake_message',
		'intake_routing_rule',
		'lti_platform',
		'lti_tool',
		'mail_message',
		'mapping_version',
		'outbound_message',
		'recipient_key',
		'recipient_opt_out',
		'rule',
		'sender_identity',
		'source',
		'verdict',
	];

	/**
	 * The schemas whose block denies by EMPTY RULE LISTS, not by existing.
	 *
	 * This is the distinction the lockdown fragments are built on and the one
	 * `CLOSED` alone cannot see: `"authorization": {}` is an empty BLOCK and
	 * closes nothing — it takes the same default-OPEN branch as no block at all —
	 * while a non-empty block whose rule lists are empty reads as "grant to
	 * nobody". A schema here could have `"read": []` changed to
	 * `"read": [{"groups":["everyone"]}]` and stay in `CLOSED`, so presence was
	 * one assertion short of guarding what the PR documents (integriq#2104 review
	 * 5266971176).
	 *
	 * The six closed schemas NOT listed here — app_connection, consumer,
	 * lti_platform, lti_tool, rule, source — grant deliberately and are covered
	 * by `CLOSED` only.
	 *
	 * @var array<int,string>
	 */
	private const DENY_ALL = [
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
	 * Schemas known to ship WITHOUT an authorization block, i.e. readable by
	 * every authenticated account.
	 *
	 * This list may only ever get SHORTER. Adding to it is declaring a new
	 * open schema and needs a reason in the pull request.
	 *
	 * @var array<int,string>
	 */
	private const KNOWN_OPEN = [
		'api_product',
		'api_product_subscription',
		'approval_request',
		'bankfeed_batch',
		'bankfeed_connection',
		'call_log',
		'cardfeed_account',
		'cardfeed_batch',
		'catalog_item',
		'documentGenerationJob',
		'dso_message',
		'dso_verzoek',
		'endpoint',
		'environment',
		'eol_cycle',
		'eol_product',
		'eudi_credential_offer',
		'eudi_issuance_session',
		'eudi_status_list',
		'event',
		'event_message',
		'event_subscription',
		'execution_trace',
		'flow',
		'flow_run',
		'flow_run_log',
		'fsc_call',
		'fsc_service',
		'iwmo_ijw_message',
		'job',
		'job_log',
		'kiss_klantcontact',
		'lti_deployment',
		'lti_identity_link',
		'mapping',
		'notificaties_abonnement',
		'openformulieren_form_mapping',
		'openformulieren_submission',
		'payment_intent',
		'peppol_transmission',
		'promotion_audit',
		'ris_sync_record',
		'sms_message',
		'stuf_message',
		'sync_item_dead_letter',
		'synchronization',
		'synchronization_contract',
		'synchronization_contract_log',
		'synchronization_log',
		'synchronization_run',
		'zgw_version_translation_log',
	];

	/**
	 * The effective register: base descriptor deep-merged with register.d.
	 *
	 * @return array<string,mixed> The schemas.
	 */
	private function schemas(): array {
		$root = dirname(__DIR__, 3);
		$descriptor = json_decode((string)file_get_contents($root . '/lib/Settings/integriq_register.json'), true);

		$fragments = glob($root . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			if (is_array($fragment) === true) {
				$descriptor = $this->deepMerge($descriptor, $fragment);
			}
		}

		return $descriptor['components']['schemas'];

	}//end schemas()

	/**
	 * Recursive deep merge — mirrors InitializeRegister::deepMergeConfig().
	 *
	 * @param array<mixed> $base The base.
	 * @param array<mixed> $overlay The overlay.
	 *
	 * @return array<mixed> The merged result.
	 */
	private function deepMerge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true && isset($base[$key]) === true && is_array($base[$key]) === true) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
				$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
				if ($baseIsList === true && $overlayIsList === true) {
					$base[$key] = array_merge($base[$key], $value);
				} else {
					$base[$key] = $this->deepMerge($base[$key], $value);
				}
			} else {
				$base[$key] = $value;
			}
		}

		return $base;

	}//end deepMerge()

	/**
	 * Split the effective register into closed and open schema names.
	 *
	 * @return array{closed: array<int,string>, open: array<int,string>} The split.
	 */
	private function split(): array {
		$closed = [];
		$open = [];
		foreach ($this->schemas() as $name => $schema) {
			$block = [];
			if (is_array($schema) === true) {
				$block = (array)($schema['authorization'] ?? []);
			}

			if ($block === []) {
				$open[] = (string)$name;
				continue;
			}

			$closed[] = (string)$name;
		}

		sort($closed);
		sort($open);

		return ['closed' => $closed, 'open' => $open];

	}//end split()

	/**
	 * No schema silently loses its authorization block.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010
	 */
	public function testNoSchemaLosesItsAuthorizationBlock(): void {
		$closed = $this->split()['closed'];

		$lost = array_values(array_diff(self::CLOSED, $closed));

		$this->assertSame(
			[],
			$lost,
			'These schemas lost their authorization block, which makes them readable by every '
			. 'authenticated account: ' . implode(', ', $lost)
		);

	}//end testNoSchemaLosesItsAuthorizationBlock()

	/**
	 * No NEW schema ships without an authorization block.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010
	 */
	public function testNoNewSchemaShipsWorldReadable(): void {
		$open = $this->split()['open'];

		$unacknowledged = array_values(array_diff($open, self::KNOWN_OPEN));

		$this->assertSame(
			[],
			$unacknowledged,
			'These schemas ship with no authorization block, so every authenticated account can '
			. 'read every object of them. Give them a block, or add them to KNOWN_OPEN with a '
			. 'reason in the pull request: ' . implode(', ', $unacknowledged)
		);

	}//end testNoNewSchemaShipsWorldReadable()

	/**
	 * The acknowledged-open list does not rot.
	 *
	 * A name left behind after its schema is closed or removed would quietly
	 * license the next schema that reuses it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010
	 */
	public function testTheAcknowledgedOpenListIsNotStale(): void {
		$open = $this->split()['open'];

		$stale = array_values(array_diff(self::KNOWN_OPEN, $open));

		$this->assertSame(
			[],
			$stale,
			'These are listed as known-open but are not: remove them from KNOWN_OPEN. '
			. implode(', ', $stale)
		);

	}//end testTheAcknowledgedOpenListIsNotStale()

	/**
	 * The locked-down schemas still deny by EMPTY rule lists.
	 *
	 * Guards the direction `CLOSED` cannot: a block that stays present while its
	 * lists start granting. Every declared action must be an empty array.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lock-down-mail-schema-reads/specs/mail-intake/spec.md#requirement-a-message-is-not-readable-by-everyone-who-can-log-in-req-mail-010
	 */
	public function testTheLockedDownSchemasStillDenyToEveryone(): void {
		$schemas = $this->schemas();
		$granting = [];

		foreach (self::DENY_ALL as $name) {
			$block = (array)(($schemas[$name]['authorization'] ?? []));
			$this->assertNotSame([], $block, "`$name` lost its authorization block entirely.");

			foreach ($block as $action => $rules) {
				if ($rules === []) {
					continue;
				}

				$granting[] = $name . '.' . $action;
			}
		}

		$this->assertSame(
			[],
			$granting,
			'These locked-down rule lists are no longer empty, so they now GRANT rather than deny: '
			. implode(', ', $granting)
		);

	}//end testTheLockedDownSchemasStillDenyToEveryone()
}//end class
