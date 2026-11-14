<?php

/**
 * Unit tests: the built-in Dutch API design rules follow the published Logius version.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ApiDesign
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-the-dutch-api-design-rules-are-built-in-req-adrc-002
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
 * The rule ids recorded below are the 38 core rules of API Design Rules
 * 2.2.2 (Logius, published 2026-09-29), read from that version's
 * sections/designRules.md. A newer version ships as a new rule set, so this
 * list changes only with it.
 *
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
class NlApiDesignRuleSetTest extends TestCase {

	private const RECORDED_IDS = [
		'/core/naming-resources', '/core/naming-collections', '/core/interface-language', '/core/no-trailing-slash',
		'/core/path-segments-kebab-case', '/core/query-keys-camel-case', '/core/always-return-objects', '/core/hide-implementation',
		'/core/lang-code', '/core/date-time/format', '/core/date-time/timezone', '/core/date-time/date-omit-time-portion',
		'/core/http-methods', '/core/http-safety', '/core/http-response-code', '/core/stateless', '/core/nested-child',
		'/core/resource-operations', '/core/error-handling/problem-details', '/core/error-handling/invalid-input',
		'/core/error-handling/all-errors', '/core/doc-openapi', '/core/doc-openapi-contact', '/core/doc-language',
		'/core/publish-openapi', '/core/deprecation-schedule', '/core/transition-period', '/core/uri-version', '/core/changelog',
		'/core/semver', '/core/version-header', '/core/transport/tls', '/core/transport/no-sensitive-uris',
		'/core/transport/security-headers', '/core/transport/cors', '/core/modules/geospatial', '/core/modules/signing',
		'/core/modules/encryption',
	];

	/**
	 * The rules the Logius linter of that version checks on a document, plus
	 * /core/transport/tls (its servers-use-https rule); /core/publish-openapi is
	 * schema validity, which the import checks, so it stays manual here.
	 */
	private const CHECKED_IDS = [
		'/core/no-trailing-slash', '/core/path-segments-kebab-case', '/core/query-keys-camel-case', '/core/always-return-objects',
		'/core/http-methods', '/core/error-handling/problem-details', '/core/error-handling/invalid-input', '/core/doc-openapi',
		'/core/doc-openapi-contact', '/core/uri-version', '/core/semver', '/core/version-header', '/core/transport/tls',
	];

	private ApiDesignRuleService $service;

	protected function setUp(): void {
		$this->service = new ApiDesignRuleService(selector: new JsonPathSelector());
	}//end setUp()

	/**
	 * The built-in Dutch rule set.
	 *
	 * @return array<string, mixed>
	 */
	private function ruleSet(): array {
		$ruleSets = $this->service->builtInRuleSets();
		$this->assertSame('nl-api-design-rules', $ruleSets[0]['name'] ?? null, 'the Dutch rules come first');
		return $ruleSets[0];
	}//end ruleSet()

	/**
	 * A fixture document.
	 *
	 * @param string $name good or bad.
	 *
	 * @return array<string, mixed>
	 */
	private static function fixture(string $name): array {
		return json_decode((string) file_get_contents(__DIR__.'/fixtures/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);
	}//end fixture()

	public function testEveryCoreRuleOfThePublishedVersionIsPresent(): void {
		$ruleSet = $this->ruleSet();

		$this->assertSame('2.2.2', $ruleSet['version']);
		$this->assertSame('built-in', $ruleSet['source']);
		$this->assertSame(self::RECORDED_IDS, array_column($ruleSet['rules'], 'id'));
	}//end testEveryCoreRuleOfThePublishedVersionIsPresent()

	public function testEachRuleLinksToItsTextAtLogius(): void {
		foreach ($this->ruleSet()['rules'] as $rule) {
			$this->assertSame('https://gitdocumentatie.logius.nl/publicatie/api/adr/2.2.2/#'.$rule['id'], $rule['url']);
			$this->assertNotSame('', $rule['title']);
		}
	}//end testEachRuleLinksToItsTextAtLogius()

	public function testTheRestIsListedAsManual(): void {
		$rules   = $this->ruleSet()['rules'];
		$checked = array_column(array_filter($rules, static fn (array $rule): bool => ($rule['manual'] ?? false) !== true), 'id');
		$manual  = array_filter($rules, static fn (array $rule): bool => ($rule['manual'] ?? false) === true);

		$this->assertSame(self::CHECKED_IDS, array_values($checked));
		$this->assertCount(25, $manual);
		foreach ($manual as $rule) {
			$this->assertArrayNotHasKey('given', $rule);
			$this->assertSame('info', $rule['severity']);
		}
	}//end testTheRestIsListedAsManual()

	public function testTheRuleSetPassesItsOwnValidation(): void {
		$this->assertSame([], $this->service->problemsOf(ruleSet: $this->ruleSet()));
	}//end testTheRuleSetPassesItsOwnValidation()

	public function testAKnownGoodDocumentHasNoFindings(): void {
		$result = $this->service->check(document: self::fixture(name: 'good'), ruleSets: [$this->ruleSet()]);

		$this->assertSame([], $result['findings']);
		$this->assertCount(25, $result['manual']);
	}//end testAKnownGoodDocumentHasNoFindings()

	public function testAKnownBadDocumentFailsEachCheckedRule(): void {
		$findings = $this->service->check(document: self::fixture(name: 'bad'), ruleSets: [$this->ruleSet()])['findings'];

		$byRule = [];
		foreach ($findings as $finding) {
			$byRule[$finding['ruleId']][] = $finding['path'];
		}

		$this->assertSame(
			[
				'/core/no-trailing-slash' => ['/paths/~1besluiten~1'],
				'/core/path-segments-kebab-case' => ['/paths/~1BesluitTypen'],
				'/core/query-keys-camel-case' => ['/paths/~1besluiten~1/get/parameters/0/name'],
				'/core/always-return-objects' => ['/paths/~1besluiten~1/get/responses/200/content/application~1json/schema/type'],
				'/core/http-methods' => ['/paths/~1besluiten~1/head'],
				'/core/error-handling/problem-details' => ['/paths/~1besluiten~1/get/responses/404/content'],
				'/core/error-handling/invalid-input' => ['/paths/~1BesluitTypen/post/responses', '/paths/~1besluiten~1/get/responses'],
				'/core/doc-openapi' => ['/openapi'],
				'/core/doc-openapi-contact' => ['/info'],
				'/core/uri-version' => ['/servers/0/url'],
				'/core/semver' => ['/info/version'],
				'/core/version-header' => ['/paths/~1besluiten~1/get/responses/200'],
				'/core/transport/tls' => ['/servers/0/url'],
			],
			$byRule
		);
		$this->assertSame(['error'], array_values(array_unique(array_column($findings, 'severity'))));
		$this->assertCount(14, $this->service->blockingFindings(findings: $findings));
	}//end testAKnownBadDocumentFailsEachCheckedRule()
}//end class
