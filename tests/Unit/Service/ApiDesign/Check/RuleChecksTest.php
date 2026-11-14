<?php

/**
 * Unit tests: each check of the rule format passes and fails what it should.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ApiDesign\Check
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

namespace OCA\Integriq\Tests\Unit\Service\ApiDesign\Check;
use OCA\Integriq\Service\ApiDesign\Check\CasingCheck;
use OCA\Integriq\Service\ApiDesign\Check\EnumCheck;
use OCA\Integriq\Service\ApiDesign\Check\FalsyCheck;
use OCA\Integriq\Service\ApiDesign\Check\LengthCheck;
use OCA\Integriq\Service\ApiDesign\Check\PatternCheck;
use OCA\Integriq\Service\ApiDesign\Check\SchemaCheck;
use OCA\Integriq\Service\ApiDesign\Check\TruthyCheck;
use PHPUnit\Framework\TestCase;

/**
 * One test per check type, each with a passing and a failing value.
 *
 * @covers \OCA\Integriq\Service\ApiDesign\Check\TruthyCheck
 * @covers \OCA\Integriq\Service\ApiDesign\Check\FalsyCheck
 * @covers \OCA\Integriq\Service\ApiDesign\Check\PatternCheck
 * @covers \OCA\Integriq\Service\ApiDesign\Check\EnumCheck
 * @covers \OCA\Integriq\Service\ApiDesign\Check\CasingCheck
 * @covers \OCA\Integriq\Service\ApiDesign\Check\SchemaCheck
 * @covers \OCA\Integriq\Service\ApiDesign\Check\LengthCheck
 */
class RuleChecksTest extends TestCase {

	public function testTruthyNeedsAPresentNonEmptyValue(): void {
		$check = new TruthyCheck();

		$this->assertSame('truthy', $check->name());
		$this->assertNull($check->check(value: ['name' => 'x'], present: true, options: []));
		$this->assertSame('is missing or empty', $check->check(value: null, present: false, options: []));
		$this->assertSame('is missing or empty', $check->check(value: '', present: true, options: []));
		$this->assertSame('is missing or empty', $check->check(value: [], present: true, options: []));
	}//end testTruthyNeedsAPresentNonEmptyValue()

	public function testFalsyRefusesAPresentValue(): void {
		$check = new FalsyCheck();

		$this->assertNull($check->check(value: null, present: false, options: []));
		$this->assertNull($check->check(value: false, present: true, options: []));
		$this->assertSame('is present', $check->check(value: 'x', present: true, options: []));
	}//end testFalsyRefusesAPresentValue()

	public function testPatternMatchAndNotMatch(): void {
		$check = new PatternCheck();

		$this->assertNull($check->check(value: '/zaken', present: true, options: ['notMatch' => '.+\/$']));
		$this->assertSame('"/zaken/" matches .+\/$', $check->check(value: '/zaken/', present: true, options: ['notMatch' => '.+\/$']));
		$this->assertSame('"2.0" does not match ^3', $check->check(value: '2.0', present: true, options: ['match' => '^3']));
		$this->assertNull($check->check(value: 'ABC', present: true, options: ['match' => '/^abc$/i']));
		$this->assertNull($check->check(value: null, present: false, options: ['match' => '^3']), 'an absent field is not checked');
		$this->assertSame('is not a text value', $check->check(value: ['x'], present: true, options: ['match' => 'x']));
	}//end testPatternMatchAndNotMatch()

	public function testPatternRefusesUnusableOptions(): void {
		$check = new PatternCheck();

		$this->assertSame('pattern needs match or notMatch', $check->invalidOptions(options: []));
		$this->assertSame('pattern match is not a valid regular expression', $check->invalidOptions(options: ['match' => '(']));
		$this->assertNull($check->invalidOptions(options: ['notMatch' => '^http://']));
	}//end testPatternRefusesUnusableOptions()

	public function testEnumAllowsOnlyTheListedValues(): void {
		$check   = new EnumCheck();
		$options = ['values' => ['get', 'post']];

		$this->assertNull($check->check(value: 'get', present: true, options: $options));
		$this->assertSame('"head" is not one of get, post', $check->check(value: 'head', present: true, options: $options));
		$this->assertSame('enum needs a non-empty values list', $check->invalidOptions(options: ['values' => []]));
	}//end testEnumAllowsOnlyTheListedValues()

	public function testCasingByType(): void {
		$check = new CasingCheck();

		$this->assertNull($check->check(value: 'zaakType', present: true, options: ['type' => 'camel']));
		$this->assertSame('"zaak_type" is not camel case', $check->check(value: 'zaak_type', present: true, options: ['type' => 'camel']));
		$this->assertNull($check->check(value: 'zaak-typen', present: true, options: ['type' => 'kebab']));
		$this->assertSame('"ZaakTypen" is not kebab case', $check->check(value: 'ZaakTypen', present: true, options: ['type' => 'kebab']));
		$this->assertNull($check->check(value: 'API_VERSION', present: true, options: ['type' => 'macro']));
		$this->assertStringStartsWith('casing type must be one of', (string) $check->invalidOptions(options: ['type' => 'title']));
	}//end testCasingByType()

	public function testSchemaReadsTypeRequiredPropertiesAndAnyOf(): void {
		$check   = new SchemaCheck();
		$contact = ['schema' => ['type' => 'object', 'required' => ['email', 'name']]];
		$either  = ['schema' => ['anyOf' => [['required' => ['application/problem+json']], ['required' => ['application/problem+xml']]]]];

		$this->assertNull($check->check(value: ['email' => 'a', 'name' => 'b'], present: true, options: $contact));
		$this->assertSame('lacks name', $check->check(value: ['email' => 'a'], present: true, options: $contact));
		$this->assertSame('is missing', $check->check(value: null, present: false, options: $contact));
		$this->assertSame('is not of type object', $check->check(value: 'x', present: true, options: $contact));
		$this->assertNull($check->check(value: ['application/problem+xml' => []], present: true, options: $either));
		$this->assertSame(
			'lacks application/problem+json and lacks application/problem+xml',
			$check->check(value: ['application/json' => []], present: true, options: $either)
		);
		$this->assertSame(
			'is too short at /name',
			$check->check(value: ['name' => ''], present: true, options: ['schema' => ['properties' => ['name' => ['minLength' => 1]]]])
		);
	}//end testSchemaReadsTypeRequiredPropertiesAndAnyOf()

	public function testSchemaRefusesAKeywordItDoesNotRead(): void {
		$check = new SchemaCheck();

		$this->assertSame('schema keyword "oneOf" is not supported', $check->invalidOptions(options: ['schema' => ['anyOf' => [['oneOf' => []]]]]));
		$this->assertSame('schema needs a schema object', $check->invalidOptions(options: []));
		$this->assertNull($check->invalidOptions(options: ['schema' => ['type' => 'object', 'required' => ['400']]]));
	}//end testSchemaRefusesAKeywordItDoesNotRead()

	public function testLengthOfTextListsAndObjects(): void {
		$check = new LengthCheck();

		$this->assertNull($check->check(value: 'Besluiten', present: true, options: ['min' => 1, 'max' => 20]));
		$this->assertSame('is shorter than 1', $check->check(value: '', present: true, options: ['min' => 1]));
		$this->assertSame('is longer than 2', $check->check(value: [1, 2, 3], present: true, options: ['max' => 2]));
		$this->assertSame('has no length', $check->check(value: true, present: true, options: ['min' => 1]));
		$this->assertSame('length needs min or max', $check->invalidOptions(options: []));
	}//end testLengthOfTextListsAndObjects()
}//end class
