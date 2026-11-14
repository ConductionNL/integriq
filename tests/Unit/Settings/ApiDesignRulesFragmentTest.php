<?php

/**
 * Tests for the api-design-rules register fragment.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Repair\InitializeRegister;
use OCA\Integriq\Service\ApiDesign\ApiDesignRuleService;
use OCA\Integriq\Service\ApiDesign\JsonPathSelector;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The rule set and finding schemas, merged, and the seeded custom rule set.
 *
 * @coversNothing
 */
class ApiDesignRulesFragmentTest extends TestCase {

	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * The register with every fragment merged, in the repair step's order.
	 *
	 * @return array<string,mixed> The merged register.
	 */
	private static function merged(): array {
		$register = json_decode((string)file_get_contents(self::SETTINGS . '/integriq_register.json'), true);
		$files = glob(self::SETTINGS . '/register.d/*.json');
		sort($files);
		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		foreach ($files as $file) {
			$register = $merge->invoke(null, $register, json_decode((string)file_get_contents($file), true));
		}

		return $register;
	}//end merged()

	/**
	 * Both schemas are in the register, and a finding is open or waived with a reason.
	 *
	 * @return void
	 */
	public function testBothSchemasAreInTheRegister(): void {
		$merged = self::merged();
		$schemas = $merged['components']['registers']['integriq']['schemas'];

		$this->assertContains('api_design_ruleset', $schemas);
		$this->assertContains('api_design_finding', $schemas);
		$this->assertContains('api_product', $schemas);

		$finding = $merged['components']['schemas']['api_design_finding'];
		$this->assertSame(['open', 'waived'], $finding['properties']['status']['enum']);
		$this->assertSame(['error', 'warning', 'info'], $finding['properties']['severity']['enum']);
		$this->assertArrayHasKey('waiveReason', $finding['properties']);
		$this->assertSame(['product', 'ruleId', 'severity', 'status'], $finding['required']);
	}//end testBothSchemasAreInTheRegister()

	/**
	 * The seeded custom rule set is usable and flags an operation without a summary.
	 *
	 * @return void
	 */
	public function testTheSeededCustomRuleSetWorks(): void {
		$seed = self::merged()['components']['schemas']['api_design_ruleset']['x-openregister-seed'];
		$this->assertSame(['gemeente-voorbeeld'], array_column($seed, 'name'));

		$rules = new ApiDesignRuleService(selector: new JsonPathSelector());
		$this->assertSame([], $rules->problemsOf(ruleSet: $seed[0]));

		$document = ['openapi' => '3.0.3', 'paths' => ['/besluiten' => ['get' => ['summary' => 'Besluiten'], 'post' => []]]];
		$findings = $rules->check(document: $document, ruleSets: $seed)['findings'];
		$this->assertSame(['/paths/~1besluiten/post'], array_column($findings, 'path'));
		$this->assertSame('warning', $findings[0]['severity']);
	}//end testTheSeededCustomRuleSetWorks()
}//end class
