<?php

/**
 * Unit tests: the rule engine turns failed rules into findings and refuses broken rule sets.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ApiDesign
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ApiDesign;
use OCA\Integriq\Service\ApiDesign\ApiDesignRuleService;
use OCA\Integriq\Service\ApiDesign\JsonPathSelector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\ApiDesign\ApiDesignRuleService
 * @uses   \OCA\Integriq\Service\ApiDesign\JsonPathSelector
 * @uses   \OCA\Integriq\Service\ApiDesign\Check\TruthyCheck
 * @uses   \OCA\Integriq\Service\ApiDesign\Check\FalsyCheck
 * @uses   \OCA\Integriq\Service\ApiDesign\Check\PatternCheck
 * @uses   \OCA\Integriq\Service\ApiDesign\Check\EnumCheck
 * @uses   \OCA\Integriq\Service\ApiDesign\Check\CasingCheck
 * @uses   \OCA\Integriq\Service\ApiDesign\Check\SchemaCheck
 * @uses   \OCA\Integriq\Service\ApiDesign\Check\LengthCheck
 */
class ApiDesignRuleServiceTest extends TestCase {

	private const TRAILING_SLASH = [
		'id' => '/core/no-trailing-slash',
		'title' => 'Leave off trailing slashes from URIs',
		'severity' => 'error',
		'url' => 'https://gitdocumentatie.logius.nl/publicatie/api/adr/2.2.2/#/core/no-trailing-slash',
		'given' => '$.paths.*',
		'then' => ['field' => '@key', 'function' => 'pattern', 'functionOptions' => ['notMatch' => '.+\/$']],
		'message' => 'Leave off trailing slashes from URIs: {{property}}',
	];

	private ApiDesignRuleService $service;

	protected function setUp(): void {
		$this->service = new ApiDesignRuleService(selector: new JsonPathSelector());
	}//end setUp()

	/**
	 * A rule set around the given rules.
	 *
	 * @param list<array<string, mixed>> $rules The rules.
	 *
	 * @return array<string, mixed>
	 */
	private static function ruleSet(array $rules, string $name='eigen-regels'): array {
		return ['name' => $name, 'version' => '1.0.0', 'source' => 'custom', 'enabled' => true, 'rules' => $rules];
	}//end ruleSet()

	public function testATrailingSlashPathIsOneFindingPointingAtThatPath(): void {
		$document = ['paths' => ['/besluiten/' => ['get' => []], '/zaken' => ['get' => []]]];

		$result = $this->service->check(document: $document, ruleSets: [self::ruleSet(rules: [self::TRAILING_SLASH])]);

		$this->assertSame(
			[[
				'ruleset' => 'eigen-regels',
				'ruleId' => '/core/no-trailing-slash',
				'severity' => 'error',
				'path' => '/paths/~1besluiten~1',
				'message' => 'Leave off trailing slashes from URIs: /besluiten/ ("/besluiten/" matches .+\/$)',
				'url' => 'https://gitdocumentatie.logius.nl/publicatie/api/adr/2.2.2/#/core/no-trailing-slash',
				'status' => 'open',
			]],
			$result['findings']
		);
		$this->assertSame([], $result['manual']);
	}//end testATrailingSlashPathIsOneFindingPointingAtThatPath()

	public function testADisabledRuleSetIsNotRun(): void {
		$ruleSet            = self::ruleSet(rules: [self::TRAILING_SLASH]);
		$ruleSet['enabled'] = false;

		$result = $this->service->check(document: ['paths' => ['/a/' => []]], ruleSets: [$ruleSet]);

		$this->assertSame([], $result['findings']);
	}//end testADisabledRuleSetIsNotRun()

	public function testAManualRuleIsListedApartAndNeverAFinding(): void {
		$manual = ['id' => '/core/stateless', 'title' => 'Do not maintain session state on the server', 'severity' => 'info', 'url' => 'https://x/#stateless', 'manual' => true];

		$result = $this->service->check(document: [], ruleSets: [self::ruleSet(rules: [$manual])]);

		$this->assertSame([], $result['findings']);
		$this->assertSame([['ruleset' => 'eigen-regels', 'ruleId' => '/core/stateless', 'title' => 'Do not maintain session state on the server', 'url' => 'https://x/#stateless']], $result['manual']);
	}//end testAManualRuleIsListedApartAndNeverAFinding()

	public function testAFieldPathPointsTheFindingAtTheMember(): void {
		$rule = ['id' => 'contact', 'severity' => 'warning', 'url' => 'https://x', 'given' => '$', 'then' => ['field' => 'info.contact', 'function' => 'truthy'], 'message' => 'Contact: {{error}}'];

		$findings = $this->service->check(document: ['info' => []], ruleSets: [self::ruleSet(rules: [$rule])])['findings'];

		$this->assertSame('/info', $findings[0]['path'], 'an absent member points at its nearest present parent');
		$this->assertSame('Contact: is missing or empty', $findings[0]['message']);
		$this->assertSame('warning', $findings[0]['severity']);
	}//end testAFieldPathPointsTheFindingAtTheMember()

	public function testLocalReferencesAreFollowedBeforeChecking(): void {
		$rule     = ['id' => 'objects', 'severity' => 'error', 'url' => 'https://x', 'given' => '$.paths.*.*.responses.*.content.*.schema', 'then' => ['field' => 'type', 'function' => 'pattern', 'functionOptions' => ['match' => '^object$']]];
		$document = [
			'paths' => ['/a' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Lijst']]]]]]]],
			'components' => ['schemas' => ['Lijst' => ['type' => 'array']]],
		];

		$findings = $this->service->check(document: $document, ruleSets: [self::ruleSet(rules: [$rule])])['findings'];

		$this->assertCount(1, $findings);
		$this->assertSame('/paths/~1a/get/responses/200/content/application~1json/schema/type', $findings[0]['path']);
	}//end testLocalReferencesAreFollowedBeforeChecking()

	public function testALoopingReferenceIsLeftAsWritten(): void {
		$rule     = ['id' => 'ref', 'severity' => 'error', 'url' => 'https://x', 'given' => '$.components.schemas.*', 'then' => ['field' => 'type', 'function' => 'truthy']];
		$document = ['components' => ['schemas' => ['A' => ['$ref' => '#/components/schemas/B'], 'B' => ['$ref' => '#/components/schemas/A']]]];

		$findings = $this->service->check(document: $document, ruleSets: [self::ruleSet(rules: [$rule])])['findings'];

		$this->assertCount(2, $findings);
	}//end testALoopingReferenceIsLeftAsWritten()

	public function testABrokenSelectorIsAFindingRatherThanAPass(): void {
		$rule = ['id' => 'broken', 'severity' => 'error', 'url' => 'https://x', 'given' => '$.paths[0:1]', 'then' => ['function' => 'truthy']];

		$findings = $this->service->check(document: ['paths' => []], ruleSets: [self::ruleSet(rules: [$rule])])['findings'];

		$this->assertSame('broken', $findings[0]['ruleId']);
		$this->assertStringStartsWith('The rule cannot be evaluated: Unsupported selector step', $findings[0]['message']);
	}//end testABrokenSelectorIsAFindingRatherThanAPass()

	public function testOnlyOpenErrorsBlockPublication(): void {
		$findings = [
			['ruleId' => 'a', 'severity' => 'error', 'status' => 'open'],
			['ruleId' => 'b', 'severity' => 'error', 'status' => 'waived'],
			['ruleId' => 'c', 'severity' => 'warning', 'status' => 'open'],
			['ruleId' => 'd', 'severity' => 'info', 'status' => 'open'],
		];

		$this->assertSame([['ruleId' => 'a', 'severity' => 'error', 'status' => 'open']], $this->service->blockingFindings(findings: $findings));
	}//end testOnlyOpenErrorsBlockPublication()

	public function testAValidCustomRuleSetHasNoProblems(): void {
		$this->assertSame([], $this->service->problemsOf(ruleSet: self::ruleSet(rules: [self::TRAILING_SLASH])));
	}//end testAValidCustomRuleSetHasNoProblems()

	public function testABrokenCustomRuleSetNamesEveryProblem(): void {
		$rules = [
			['id' => 'a', 'severity' => 'fatal', 'url' => 'http://x', 'given' => '$.paths[0]', 'then' => ['function' => 'spectral']],
			['id' => 'a', 'severity' => 'error', 'url' => 'https://x', 'given' => '$', 'then' => ['function' => 'pattern', 'functionOptions' => []]],
			['id' => 'b', 'severity' => 'warning', 'url' => 'https://x'],
		];

		$problems = $this->service->problemsOf(ruleSet: ['name' => '', 'version' => '1', 'rules' => $rules]);

		$this->assertSame(
			[
				'The rule set has no name.',
				'Rule a: its severity is not error, warning or info',
				'Rule a: it has no https link to the rule text',
				'Rule a: Unsupported selector step at "[0]"',
				'Rule a: check "spectral" is not one of truthy, falsy, pattern, enum, casing, schema, length',
				'Rule a appears twice.',
				'Rule a: pattern needs match or notMatch',
				'Rule b: it selects nothing (given)',
				'Rule b: it checks nothing (then)',
			],
			$problems
		);
	}//end testABrokenCustomRuleSetNamesEveryProblem()

	public function testARuleSetWithoutRulesIsRefused(): void {
		$this->assertSame(['The rule set has no rules.'], $this->service->problemsOf(ruleSet: ['name' => 'x', 'version' => '1', 'rules' => []]));
	}//end testARuleSetWithoutRulesIsRefused()
}//end class
