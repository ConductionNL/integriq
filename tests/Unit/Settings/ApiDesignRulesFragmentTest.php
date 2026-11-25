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
 * @spec openspec/changes/archive/2026-11-25-gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
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
	 * The rows of one schema among the objects OpenRegister imports, by slug.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string,array<string,mixed>> The rows, keyed by their slug.
	 */
	private static function seeded(string $schema): array {
		$rows = [];
		foreach (self::merged()['components']['objects'] as $row) {
			if (($row['@self']['schema'] ?? null) === $schema) {
				$rows[$row['@self']['slug']] = $row;
			}
		}

		return $rows;
	}//end seeded()

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
		$seed = self::seeded(schema: 'api_design_ruleset');
		$this->assertSame(['gemeente-voorbeeld'], array_column($seed, 'name'));
		$this->assertArrayNotHasKey(key: 'x-openregister-seed', array: self::merged()['components']['schemas']['api_design_ruleset']);

		$rules = new ApiDesignRuleService(selector: new JsonPathSelector());
		$this->assertSame([], $rules->problemsOf(ruleSet: $seed['gemeente-voorbeeld']));

		$document = ['openapi' => '3.0.3', 'paths' => ['/besluiten' => ['get' => ['summary' => 'Besluiten'], 'post' => []]]];
		$findings = $rules->check(document: $document, ruleSets: array_values($seed))['findings'];
		$this->assertSame(['/paths/~1besluiten/post'], array_column($findings, 'path'));
		$this->assertSame('warning', $findings[0]['severity']);
	}//end testTheSeededCustomRuleSetWorks()

	/**
	 * The seeded besluiten-api is not active, and carries one open error and one waived warning.
	 *
	 * An open error holds back publication, so the product starts deprecated:
	 * seeding it active would show a published product the guard should have refused.
	 *
	 * @return void
	 */
	public function testTheSeededProductCarriesOneErrorAndOneWaivedWarning(): void {
		$product = self::seeded(schema: 'api_product')['api-product-besluiten-api-v1'];
		$this->assertSame(expected: 'besluiten-api', actual: $product['productSlug']);
		$this->assertSame(expected: 'deprecated', actual: $product['status']);
		$this->assertSame(expected: '2027-01-01T00:00:00+00:00', actual: $product['sunsetDate']);

		$findings = self::seeded(schema: 'api_design_finding');
		$this->assertCount(expectedCount: 2, haystack: $findings);
		foreach ($findings as $finding) {
			$this->assertSame(expected: '@ref:api_product:api-product-besluiten-api-v1', actual: $finding['product']);
			$this->assertSame(expected: $product['version'], actual: $finding['productVersion']);
		}

		$rules = new ApiDesignRuleService(selector: new JsonPathSelector());
		$blocking = $rules->blockingFindings(findings: array_values($findings));
		$this->assertSame(expected: ['/core/no-trailing-slash'], actual: array_column($blocking, 'ruleId'));

		$waived = $findings['besluiten-api-v1-operation-summary'];
		$this->assertSame(expected: ['warning', 'waived'], actual: [$waived['severity'], $waived['status']]);
		$this->assertSame(expected: 'partner requires this path until 2027', actual: $waived['waiveReason']);
		$this->assertNotSame(expected: '', actual: $waived['waivedBy']);
		$this->assertNotFalse(condition: \DateTimeImmutable::createFromFormat(format: \DATE_ATOM, datetime: $waived['waivedAt']));
	}//end testTheSeededProductCarriesOneErrorAndOneWaivedWarning()

	/**
	 * Each seeded finding is what a check of a besluiten description with a trailing slash produces.
	 *
	 * @return void
	 */
	public function testTheSeededFindingsAreWhatACheckProduces(): void {
		$rules = new ApiDesignRuleService(selector: new JsonPathSelector());
		$ruleSets = array_merge($rules->builtInRuleSets(), array_values(self::seeded(schema: 'api_design_ruleset')));
		$document = ['openapi' => '3.0.3', 'paths' => ['/besluiten/' => ['get' => ['summary' => 'Besluiten'], 'post' => []]]];

		$produced = [];
		foreach ($rules->check(document: $document, ruleSets: $ruleSets)['findings'] as $finding) {
			$produced[$finding['ruleId'] . ' ' . $finding['path']] = $finding;
		}

		foreach (self::seeded(schema: 'api_design_finding') as $slug => $seeded) {
			$key = $seeded['ruleId'] . ' ' . $seeded['path'];
			$this->assertArrayHasKey(key: $key, array: $produced, message: $slug);
			$this->assertSame(expected: $produced[$key]['severity'], actual: $seeded['severity'], message: $slug);
			$this->assertSame(expected: $produced[$key]['url'], actual: $seeded['url'], message: $slug);
		}
	}//end testTheSeededFindingsAreWhatACheckProduces()
}//end class
