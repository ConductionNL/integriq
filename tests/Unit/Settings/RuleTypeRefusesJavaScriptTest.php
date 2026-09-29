<?php

/**
 * The register refuses a rule of type `javascript`
 * (gateway-endpoint-transform-and-plugins, REQ-GTP-003).
 *
 * Validates payloads against the merged `rule` schema the way OpenRegister
 * does, so a save through the objects API meets the same refusal.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/gateway-endpoint-transform-and-plugins/specs/rule-pipeline/spec.md#requirement-a-javascript-rule-is-refused-req-gtp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the rule schema's type vocabulary.
 */
class RuleTypeRefusesJavaScriptTest extends TestCase {

	/**
	 * A rule as the editor saves it.
	 *
	 * @param string $type The rule type.
	 *
	 * @return array The rule.
	 */
	private function rule(string $type): array {
		return [
			'name' => 'Regel',
			'action' => 'post',
			'timing' => 'before',
			'order' => 100,
			'type' => $type,
			'configuration' => [],
		];
	}//end rule()

	/**
	 * A javascript rule is refused by the register.
	 *
	 * @return void
	 */
	public function testAJavaScriptRuleCannotBeStored(): void {
		$this->assertNotSame([], RegisterSchemaValidator::errors('rule', $this->rule('javascript')));
	}//end testAJavaScriptRuleCannotBeStored()

	/**
	 * Every type one of the two pipelines dispatches is still accepted.
	 *
	 * @return void
	 */
	public function testEveryDispatchedTypeIsStillAccepted(): void {
		$types = [
			'error', 'mapping', 'synchronization', 'authentication', 'download', 'upload', 'locking',
			'fetch_file', 'write_file', 'fileparts_create', 'filepart_upload', 'save_object',
			'extend_input', 'extend_external_input', 'webhook_signature', 'approval', 'flow',
			'audit_trail', 'override', 'custom', 'composite_fanout', 'referentienummer',
			'avg_bsn_policy', 'selfurl_hal',
		];
		foreach ($types as $type) {
			$this->assertSame([], RegisterSchemaValidator::errors('rule', $this->rule($type)), $type);
		}
	}//end testEveryDispatchedTypeIsStillAccepted()

	/**
	 * The example rules the app ships still import.
	 *
	 * @return void
	 */
	public function testTheShippedExampleRulesStillValidate(): void {
		$root = dirname(__DIR__, 3) . '/lib/Settings/';
		$checked = 0;
		foreach (['integriq_mock_register.json', 'integriq_seed_data.json'] as $file) {
			$data = json_decode((string)file_get_contents($root . $file), true);
			foreach ($this->rulesIn($data) as $rule) {
				unset($rule['@self']);
				$this->assertSame([], RegisterSchemaValidator::errors('rule', $rule), $file . ': ' . ($rule['name'] ?? '?'));
				$checked++;
			}
		}

		$this->assertGreaterThan(0, $checked);
	}//end testTheShippedExampleRulesStillValidate()

	/**
	 * Every object in a seed file whose `@self.schema` is `rule`.
	 *
	 * @param mixed $node The decoded file or a part of it.
	 *
	 * @return array<int, array> The rules.
	 */
	private function rulesIn(mixed $node): array {
		if (is_array($node) === false) {
			return [];
		}

		if (($node['@self']['schema'] ?? null) === 'rule') {
			return [$node];
		}

		$found = [];
		foreach ($node as $child) {
			$found = array_merge($found, $this->rulesIn($child));
		}

		return $found;
	}//end rulesIn()
}//end class
