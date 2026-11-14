<?php

/**
 * Edge-case unit tests for TablesColumnCoercer: timestamp and subtype
 * formatting, non-scalar values and label-keyed selection options.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Tables;

use DateTime;
use OCA\Integriq\Exception\TablesConfigException;
use OCA\Integriq\Service\Tables\TablesColumnCoercer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the coercion branches the scenario tests leave out.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003
 */
#[CoversClass(TablesColumnCoercer::class)]
#[UsesClass(TablesConfigException::class)]
class TablesColumnCoercerEdgeCaseTest extends TestCase {

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
	 * Build a datetime column with the given subtype.
	 *
	 * @param string|null $subtype The subtype, or null to leave it out.
	 *
	 * @return array
	 */
	private function datetimeColumn(?string $subtype): array {
		$column = ['title' => 'Due', 'type' => 'datetime', 'constraints' => []];
		if ($subtype !== null) {
			$column['subtype'] = $subtype;
		}

		return $column;

	}//end datetimeColumn()

	/**
	 * A unix timestamp is formatted per the subtype, in the default timezone.
	 *
	 * @return void
	 */
	public function testIntegerTimestampIsFormattedPerSubtype(): void {
		$timestamp = 1700000000;
		$expected = (new DateTime())->setTimestamp($timestamp);

		$this->assertSame($expected->format('Y-m-d'), $this->coercer->coerce(column: $this->datetimeColumn('date'), value: $timestamp));
		$this->assertSame($expected->format('H:i:s'), $this->coercer->coerce(column: $this->datetimeColumn('time'), value: $timestamp));
		$this->assertSame($expected->format(DateTime::ATOM), $this->coercer->coerce(column: $this->datetimeColumn(null), value: $timestamp));

	}//end testIntegerTimestampIsFormattedPerSubtype()

	/**
	 * A  subtype keeps only the time of a date string.
	 *
	 * @return void
	 */
	public function testTimeSubtypeKeepsOnlyTheTime(): void {
		$this->assertSame('05:06:07', $this->coercer->coerce(column: $this->datetimeColumn('time'), value: '2026-03-04T05:06:07+02:00'));

	}//end testTimeSubtypeKeepsOnlyTheTime()

	/**
	 * Without a subtype a date string is normalised to ISO-8601 with its offset kept.
	 *
	 * @return void
	 */
	public function testMissingSubtypeNormalisesToAtom(): void {
		$this->assertSame(
			'2026-03-04T05:06:07+02:00',
			$this->coercer->coerce(column: $this->datetimeColumn(null), value: '2026-03-04 05:06:07+02:00')
		);

	}//end testMissingSubtypeNormalisesToAtom()

	/**
	 * A value that is neither string nor int is refused for a datetime column.
	 *
	 * @return void
	 */
	public function testFloatDatetimeValueIsRefused(): void {
		$this->expectException(TablesConfigException::class);
		$this->expectExceptionMessage("Value for datetime column 'Due' is not a recognisable date");

		$this->coercer->coerce(column: $this->datetimeColumn('datetime'), value: 1.5);

	}//end testFloatDatetimeValueIsRefused()

	/**
	 * An unparseable date names the value and the column in the error.
	 *
	 * @return void
	 */
	public function testUnparseableDateMessageNamesValueAndColumn(): void {
		$this->expectException(TablesConfigException::class);
		$this->expectExceptionMessageMatches("/^Value 'soon' could not be parsed as a date for column 'Due': /");

		$this->coercer->coerce(column: $this->datetimeColumn('date'), value: 'soon');

	}//end testUnparseableDateMessageNamesValueAndColumn()

	/**
	 * A non-scalar value for a number column is described by its type.
	 *
	 * @return void
	 */
	public function testNonScalarNumberIsDescribedByType(): void {
		$column = ['title' => 'Amount', 'type' => 'number', 'constraints' => []];

		$this->expectException(TablesConfigException::class);
		$this->expectExceptionMessage("Value 'array' is not numeric for column 'Amount'");

		$this->coercer->coerce(column: $column, value: ['12']);

	}//end testNonScalarNumberIsDescribedByType()

	/**
	 * Without numberDecimals a number is cast to float unrounded.
	 *
	 * @return void
	 */
	public function testNumberWithoutDecimalsIsNotRounded(): void {
		$column = ['title' => 'Amount', 'type' => 'number', 'constraints' => []];

		$this->assertSame(19.999, $this->coercer->coerce(column: $column, value: '19.999'));
		$this->assertSame(7.0, $this->coercer->coerce(column: $column, value: 7));

	}//end testNumberWithoutDecimalsIsNotRounded()

	/**
	 * Zero decimals round a number to a whole value.
	 *
	 * @return void
	 */
	public function testZeroDecimalsRoundToWhole(): void {
		$column = ['title' => 'Amount', 'type' => 'number', 'constraints' => ['numberDecimals' => 0]];

		$this->assertSame(3.0, $this->coercer->coerce(column: $column, value: '2.5'));

	}//end testZeroDecimalsRoundToWhole()

	/**
	 * A selection option given as an array matches on its label.
	 *
	 * @return void
	 */
	public function testSelectionMatchesOnOptionLabel(): void {
		$column = [
			'title' => 'Status',
			'type' => 'selection',
			'constraints' => ['selectionOptions' => [['id' => 1, 'label' => 'Open'], ['id' => 2, 'label' => 'Paid']]],
		];

		$this->assertSame('Paid', $this->coercer->coerce(column: $column, value: 'Paid'));

	}//end testSelectionMatchesOnOptionLabel()

	/**
	 * A non-array selectionOptions constraint matches nothing.
	 *
	 * @return void
	 */
	public function testNonArraySelectionOptionsMatchNothing(): void {
		$column = ['title' => 'Status', 'type' => 'selection', 'constraints' => ['selectionOptions' => 'open']];

		$this->expectException(TablesConfigException::class);
		$this->expectExceptionMessage("Value 'open' does not match any option of column 'Status' (allowed: )");

		$this->coercer->coerce(column: $column, value: 'open');

	}//end testNonArraySelectionOptionsMatchNothing()

	/**
	 * A text column without textMaxLength casts any length to string.
	 *
	 * @return void
	 */
	public function testTextWithoutMaxLengthCastsToString(): void {
		$column = ['title' => 'Note', 'type' => 'text', 'constraints' => []];

		$this->assertSame('42', $this->coercer->coerce(column: $column, value: 42));
		$this->assertSame(str_repeat('x', 5000), $this->coercer->coerce(column: $column, value: str_repeat('x', 5000)));

	}//end testTextWithoutMaxLengthCastsToString()

	/**
	 * A column without a type is refused as unsupported.
	 *
	 * @return void
	 */
	public function testMissingTypeIsRefused(): void {
		$this->expectException(TablesConfigException::class);
		$this->expectExceptionMessage("Column 'Mystery' has an unsupported type '' for write coercion");

		$this->coercer->coerce(column: ['title' => 'Mystery'], value: 'x');

	}//end testMissingTypeIsRefused()
}//end class
