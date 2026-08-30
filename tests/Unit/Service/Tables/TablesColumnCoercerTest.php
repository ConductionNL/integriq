<?php

/**
 * Unit tests for TablesColumnCoercer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/tables-bridge/tasks.md#task-2-tablessyncadapter--column-cache-titlecolumnid-resolution-coercion
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Tables;

use OCA\Integriq\Exception\TablesConfigException;
use OCA\Integriq\Service\Tables\TablesColumnCoercer;
use PHPUnit\Framework\TestCase;

/**
 * Directly exercises the column-type coercion rules (tables-bridge REQ-003).
 *
 * @spec openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003
 */
class TablesColumnCoercerTest extends TestCase {

	/**
	 * @var TablesColumnCoercer
	 */
	private TablesColumnCoercer $coercer;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->coercer = new TablesColumnCoercer();

	}//end setUp()

	/**
	 * A `number` column rounds to `numberDecimals` (REQ-003 scenario).
	 *
	 * @return void
	 */
	public function testNumberRoundsToDecimals(): void {
		$column = ['title' => 'Amount', 'type' => 'number', 'subtype' => null, 'constraints' => ['numberDecimals' => 2]];

		$this->assertSame(20.0, $this->coercer->coerce(column: $column, value: '19.999'));

	}//end testNumberRoundsToDecimals()

	/**
	 * A non-numeric value against a `number` column throws (REQ-003 scenario).
	 *
	 * @return void
	 */
	public function testNonNumericThrows(): void {
		$column = ['title' => 'Amount', 'type' => 'number', 'subtype' => null, 'constraints' => []];

		$this->expectException(TablesConfigException::class);
		$this->coercer->coerce(column: $column, value: 'not-a-number');

	}//end testNonNumericThrows()

	/**
	 * A `text` value exceeding `textMaxLength` throws rather than truncating.
	 *
	 * @return void
	 */
	public function testTextOverMaxLengthThrows(): void {
		$column = ['title' => 'Note', 'type' => 'text', 'subtype' => 'line', 'constraints' => ['textMaxLength' => 3]];

		$this->expectException(TablesConfigException::class);
		$this->coercer->coerce(column: $column, value: 'abcdef');

	}//end testTextOverMaxLengthThrows()

	/**
	 * A `text` value within `textMaxLength` is cast to string.
	 *
	 * @return void
	 */
	public function testTextWithinMaxLengthCasts(): void {
		$column = ['title' => 'Note', 'type' => 'text', 'subtype' => 'line', 'constraints' => ['textMaxLength' => 10]];

		$this->assertSame('42', $this->coercer->coerce(column: $column, value: 42));

	}//end testTextWithinMaxLengthCasts()

	/**
	 * A `datetime` column with `date` subtype strips the time component.
	 *
	 * @return void
	 */
	public function testDatetimeDateSubtypeStripsTime(): void {
		$column = ['title' => 'Due', 'type' => 'datetime', 'subtype' => 'date', 'constraints' => []];

		$this->assertSame('2026-07-14', $this->coercer->coerce(column: $column, value: '2026-07-14T09:30:00Z'));

	}//end testDatetimeDateSubtypeStripsTime()

	/**
	 * An unparseable `datetime` value throws.
	 *
	 * @return void
	 */
	public function testDatetimeUnparseableThrows(): void {
		$column = ['title' => 'Due', 'type' => 'datetime', 'subtype' => 'datetime', 'constraints' => []];

		$this->expectException(TablesConfigException::class);
		$this->coercer->coerce(column: $column, value: 'not-a-date');

	}//end testDatetimeUnparseableThrows()

	/**
	 * A `selection` value matching an option is returned as-is (REQ-003).
	 *
	 * @return void
	 */
	public function testSelectionMatchingOptionPasses(): void {
		$column = ['title' => 'Status', 'type' => 'selection', 'subtype' => 'selection', 'constraints' => ['selectionOptions' => ['open', 'paid', 'overdue']]];

		$this->assertSame('paid', $this->coercer->coerce(column: $column, value: 'paid'));

	}//end testSelectionMatchingOptionPasses()

	/**
	 * A `selection` value with no matching option throws (REQ-003 scenario).
	 *
	 * @return void
	 */
	public function testSelectionUnmatchedThrows(): void {
		$column = ['title' => 'Status', 'type' => 'selection', 'subtype' => 'selection', 'constraints' => ['selectionOptions' => ['open', 'paid', 'overdue']]];

		$this->expectException(TablesConfigException::class);
		$this->coercer->coerce(column: $column, value: 'cancelled');

	}//end testSelectionUnmatchedThrows()

	/**
	 * A `usergroup` column is a config error for writes (out of scope, REQ-003).
	 *
	 * @return void
	 */
	public function testUsergroupThrows(): void {
		$column = ['title' => 'Owner', 'type' => 'usergroup', 'subtype' => null, 'constraints' => []];

		$this->expectException(TablesConfigException::class);
		$this->coercer->coerce(column: $column, value: 'alice');

	}//end testUsergroupThrows()

	/**
	 * An unknown column type is a hard config error, never a silent pass-through.
	 *
	 * @return void
	 */
	public function testUnknownTypeThrows(): void {
		$column = ['title' => 'Weird', 'type' => 'quantum', 'subtype' => null, 'constraints' => []];

		$this->expectException(TablesConfigException::class);
		$this->coercer->coerce(column: $column, value: 'x');

	}//end testUnknownTypeThrows()
}//end class
