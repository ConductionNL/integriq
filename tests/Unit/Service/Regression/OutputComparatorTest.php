<?php

/**
 * Unit tests: the path-by-path comparison of a test case's output.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Regression
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/automation-integration-regression-tests/specs/integration-regression-tests/spec.md#requirement-a-suite-run-replays-every-case-without-calling-the-source-or-writing-req-irt-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Regression;

use OCA\Integriq\Service\Regression\OutputComparator;
use PHPUnit\Framework\TestCase;

/**
 * Differences, absences and ignored paths.
 */
class OutputComparatorTest extends TestCase {

	/**
	 * Equal outputs have no differences, whatever the key order.
	 *
	 * @return void
	 */
	public function testEqualOutputsMatch(): void {
		$this->assertSame(
			expected: [],
			actual: OutputComparator::differences(
				expected: ['a' => 1, 'b' => ['c' => 'x', 'd' => [1, 2]]],
				actual: ['b' => ['d' => [1, 2], 'c' => 'x'], 'a' => 1]
			)
		);
	}//end testEqualOutputsMatch()

	/**
	 * A changed, a missing and an extra value are each named by path; an empty object is a value of its own.
	 *
	 * @return void
	 */
	public function testEveryDifferingPathIsNamed(): void {
		$this->assertSame(
			expected: [
				['path' => 'name', 'expected' => 'Zaak', 'actual' => 'zaak'],
				['path' => 'status', 'expected' => '(absent)', 'actual' => []],
				['path' => 'status.code', 'expected' => 'open', 'actual' => '(absent)'],
				['path' => 'tags.1', 'expected' => '(absent)', 'actual' => 'b'],
				['path' => 'total', 'expected' => 1, 'actual' => '1'],
			],
			actual: OutputComparator::differences(
				expected: ['name' => 'Zaak', 'status' => ['code' => 'open'], 'tags' => ['a'], 'total' => 1],
				actual: ['name' => 'zaak', 'status' => [], 'tags' => ['a', 'b'], 'total' => '1']
			)
		);
	}//end testEveryDifferingPathIsNamed()

	/**
	 * An ignored path, and everything below it, does not fail the case; a sibling with the same prefix does.
	 *
	 * @return void
	 */
	public function testAnIgnoredPathDoesNotFailTheCase(): void {
		$this->assertSame(
			expected: [],
			actual: OutputComparator::differences(
				expected: ['name' => 'x', 'dateModified' => '2026-01-01', 'meta' => ['at' => 1]],
				actual: ['name' => 'x', 'dateModified' => '2026-11-13', 'meta' => ['at' => 2, 'by' => 'job']],
				ignored: ['dateModified', 'meta']
			)
		);
		$this->assertSame(
			expected: [['path' => 'dateModifiedBy', 'expected' => 'a', 'actual' => 'b']],
			actual: OutputComparator::differences(
				expected: ['dateModifiedBy' => 'a'],
				actual: ['dateModifiedBy' => 'b'],
				ignored: ['dateModified']
			)
		);
	}//end testAnIgnoredPathDoesNotFailTheCase()

	/**
	 * An expected empty output against a filled one names every filled path.
	 *
	 * @return void
	 */
	public function testAnEmptyExpectationNamesEveryPath(): void {
		$this->assertSame(
			expected: [['path' => 'name', 'expected' => '(absent)', 'actual' => 'x']],
			actual: OutputComparator::differences(expected: [], actual: ['name' => 'x'])
		);
	}//end testAnEmptyExpectationNamesEveryPath()
}//end class
