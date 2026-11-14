<?php

/**
 * Unit tests: the JSONPath selector reaches the nodes a rule names.
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

use InvalidArgumentException;
use OCA\Integriq\Service\ApiDesign\JsonPathSelector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\Service\ApiDesign\JsonPathSelector
 */
class JsonPathSelectorTest extends TestCase {

	private const DOCUMENT = [
		'servers' => [['url' => 'https://a/v1'], ['url' => 'http://b']],
		'paths' => [
			'/zaken/' => [
				'get' => ['parameters' => [['name' => 'a', 'in' => 'query'], ['name' => 'b', 'in' => 'path']], 'responses' => ['200' => ['x' => 1], '404' => ['x' => 2]]],
				'servers' => [['url' => 'http://c']],
			],
		],
	];

	private JsonPathSelector $selector;

	protected function setUp(): void {
		$this->selector = new JsonPathSelector();
	}//end setUp()

	public function testTheRootIsTheDocument(): void {
		$nodes = $this->selector->select(document: ['a' => 1], expression: '$');

		$this->assertSame([['pointer' => '', 'key' => null, 'value' => ['a' => 1]]], $nodes);
	}//end testTheRootIsTheDocument()

	public function testAWildcardYieldsEveryChildWithItsPointer(): void {
		$nodes = $this->selector->select(document: self::DOCUMENT, expression: '$.servers[*]');

		$this->assertSame(['/servers/0', '/servers/1'], array_column($nodes, 'pointer'));
		$this->assertSame('http://b', $nodes[1]['value']['url']);
	}//end testAWildcardYieldsEveryChildWithItsPointer()

	public function testAKeyWithASlashIsEscapedInThePointer(): void {
		$nodes = $this->selector->select(document: self::DOCUMENT, expression: '$.paths.*');

		$this->assertSame('/paths/~1zaken~1', $nodes[0]['pointer']);
		$this->assertSame('/zaken/', $nodes[0]['key']);
	}//end testAKeyWithASlashIsEscapedInThePointer()

	public function testATildeSelectsTheKeys(): void {
		$nodes = $this->selector->select(document: self::DOCUMENT, expression: '$.paths.*~');

		$this->assertSame('/zaken/', $nodes[0]['value']);
	}//end testATildeSelectsTheKeys()

	public function testAFilterOnAMemberValue(): void {
		$nodes = $this->selector->select(document: self::DOCUMENT, expression: "$.paths.*.*.parameters[?(@.in=='query')]");

		$this->assertSame(['a'], array_column(array_column($nodes, 'value'), 'name'));
		$this->assertSame('/paths/~1zaken~1/get/parameters/0', $nodes[0]['pointer']);
	}//end testAFilterOnAMemberValue()

	public function testAFilterOnTheKeyAndItsNegation(): void {
		$hits   = $this->selector->select(document: self::DOCUMENT, expression: '$.paths.*.*.responses[?(@property.match(/^2\d\d$/))]');
		$misses = $this->selector->select(document: self::DOCUMENT, expression: '$.paths.*.*.responses[?(!@property.match(/^2\d\d$/))]');

		$this->assertSame(['200'], array_map('strval', array_column($hits, 'key')));
		$this->assertSame(['404'], array_map('strval', array_column($misses, 'key')));
	}//end testAFilterOnTheKeyAndItsNegation()

	public function testFilterTermsJoinedByAndMustAllHold(): void {
		$nodes = $this->selector->select(document: self::DOCUMENT, expression: '$.paths.*[?(@property.match(/^get$/) && @.parameters)]');
		$none  = $this->selector->select(document: self::DOCUMENT, expression: '$.paths.*[?(@property.match(/^get$/) && @.requestBody)]');

		$this->assertCount(1, $nodes);
		$this->assertSame([], $none);
	}//end testFilterTermsJoinedByAndMustAllHold()

	public function testRecursiveDescentFindsTheKeyAtAnyDepth(): void {
		$nodes = $this->selector->select(document: self::DOCUMENT, expression: '$.paths..servers.*');

		$this->assertSame(['http://c'], array_column(array_column($nodes, 'value'), 'url'));
	}//end testRecursiveDescentFindsTheKeyAtAnyDepth()

	public function testBracketNotationNamesAKeyWithPunctuation(): void {
		$nodes = $this->selector->select(document: ['content' => ['application/json' => 1]], expression: "$.content['application/json']");

		$this->assertSame(1, $nodes[0]['value']);
	}//end testBracketNotationNamesAKeyWithPunctuation()

	public function testAMissingMemberSelectsNothing(): void {
		$this->assertSame([], $this->selector->select(document: self::DOCUMENT, expression: '$.components.schemas.*'));
	}//end testAMissingMemberSelectsNothing()

	public function testAnUnsupportedStepIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported selector step');

		$this->selector->select(document: self::DOCUMENT, expression: '$.paths[0:2]');
	}//end testAnUnsupportedStepIsRefused()

	public function testAnUnsupportedFilterIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported filter term');

		$this->selector->select(document: self::DOCUMENT, expression: '$.paths[?(@.length > 2)]');
	}//end testAnUnsupportedFilterIsRefused()

	public function testASelectorMustStartAtTheRoot(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->selector->select(document: self::DOCUMENT, expression: 'paths.*');
	}//end testASelectorMustStartAtTheRoot()
}//end class
