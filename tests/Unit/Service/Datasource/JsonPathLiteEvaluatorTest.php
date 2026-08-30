<?php

/**
 * Unit tests for JsonPathLiteEvaluator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Datasource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Datasource;

use OCA\Integriq\Service\Datasource\JsonPathLiteEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `$.a.b.c` / `$.a[0].b` JSONPath-lite dialect.
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
 */
class JsonPathLiteEvaluatorTest extends TestCase {

	private JsonPathLiteEvaluator $evaluator;

	protected function setUp(): void {
		parent::setUp();
		$this->evaluator = new JsonPathLiteEvaluator();
	}//end setUp()

	/**
	 * Dotted-key traversal ($.a.b.c) resolves a nested scalar.
	 */
	public function testDottedPathResolvesNestedValue(): void {
		$data = ['data' => ['open_count' => 42]];
		$this->assertSame(42, $this->evaluator->evaluate(data: $data, expr: '$.data.open_count'));
	}//end testDottedPathResolvesNestedValue()

	/**
	 * Bracket array-index syntax ($.a[0].b) resolves through a list.
	 */
	public function testArrayIndexResolvesThroughList(): void {
		$data = ['items' => [['id' => 'first'], ['id' => 'second']]];
		$this->assertSame('first', $this->evaluator->evaluate(data: $data, expr: '$.items[0].id'));
		$this->assertSame('second', $this->evaluator->evaluate(data: $data, expr: '$.items[1].id'));
	}//end testArrayIndexResolvesThroughList()

	/**
	 * A path that does not exist in the document resolves to null, not an error.
	 */
	public function testMissingPathReturnsNull(): void {
		$data = ['data' => ['open_count' => 42]];
		$this->assertNull($this->evaluator->evaluate(data: $data, expr: '$.data.missing_key'));
	}//end testMissingPathReturnsNull()

	/**
	 * An out-of-range array index resolves to null.
	 */
	public function testOutOfRangeIndexReturnsNull(): void {
		$data = ['items' => [['id' => 'only']]];
		$this->assertNull($this->evaluator->evaluate(data: $data, expr: '$.items[5].id'));
	}//end testOutOfRangeIndexReturnsNull()

	/**
	 * Traversing into a scalar (not an array) resolves to null instead of throwing.
	 */
	public function testTraversalIntoScalarReturnsNull(): void {
		$data = ['data' => 'not-an-array'];
		$this->assertNull($this->evaluator->evaluate(data: $data, expr: '$.data.open_count'));
	}//end testTraversalIntoScalarReturnsNull()

	/**
	 * A bare `$` returns the whole document.
	 */
	public function testBareDollarReturnsWholeDocument(): void {
		$data = ['a' => 1];
		$this->assertSame($data, $this->evaluator->evaluate(data: $data, expr: '$'));
	}//end testBareDollarReturnsWholeDocument()
}//end class
