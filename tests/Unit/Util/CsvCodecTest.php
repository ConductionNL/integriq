<?php

/**
 * Unit tests for CsvCodec.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/mapping-formats-and-lookups/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Util;

use InvalidArgumentException;
use OCA\Integriq\Util\CsvCodec;
use PHPUnit\Framework\TestCase;

/**
 * CSV decode and encode.
 *
 * @covers \OCA\Integriq\Util\CsvCodec
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-synchronization-reads-a-csv-source-response-req-mfl-001
 */
class CsvCodecTest extends TestCase {

	/**
	 * A quoted field with a line break stays one field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#scenario-a-quoted-line-break-survives
	 */
	public function testAQuotedLineBreakStaysOneField(): void {
		$rows = CsvCodec::decode("naam,adres\nJansen,\"Dorpsstraat 1\n1234 AB Ergens\"\nDe Vries,Kerkweg 2\n");

		$this->assertSame(
			[
				['naam' => 'Jansen', 'adres' => "Dorpsstraat 1\n1234 AB Ergens"],
				['naam' => 'De Vries', 'adres' => 'Kerkweg 2'],
			],
			$rows
		);
	}//end testAQuotedLineBreakStaysOneField()

	/**
	 * Blank lines are skipped, a short row gets empty values, an extra value is dropped and a BOM is ignored.
	 *
	 * @return void
	 */
	public function testRowsAreKeyedByTheHeader(): void {
		$rows = CsvCodec::decode("\xEF\xBB\xBFa;b\n\n1\n2;3;4\r\n", ['delimiter' => ';']);

		$this->assertSame([['a' => '1', 'b' => ''], ['a' => '2', 'b' => '3']], $rows);
	}//end testRowsAreKeyedByTheHeader()

	/**
	 * Without a header each row is a list of its values.
	 *
	 * @return void
	 */
	public function testWithoutAHeaderRowsAreLists(): void {
		$this->assertSame([['x', 'y'], ['z', '']], CsvCodec::decode("x,y\nz,", ['header' => false]));
	}//end testWithoutAHeaderRowsAreLists()

	/**
	 * Empty text has no rows and no headers.
	 *
	 * @return void
	 */
	public function testEmptyTextHasNoRows(): void {
		$this->assertSame([], CsvCodec::decode(''));
		$this->assertSame([], CsvCodec::headers(''));
	}//end testEmptyTextHasNoRows()

	/**
	 * The headers are the first record, read through the enclosure.
	 *
	 * @return void
	 */
	public function testHeadersAreTheFirstRecord(): void {
		$this->assertSame(['id', 'naam, voluit'], CsvCodec::headers("id,'naam, voluit'\n1,x", ['enclosure' => "'"]));
	}//end testHeadersAreTheFirstRecord()

	/**
	 * Two rows encoded with a header and `;` are three lines, in the first row's column order.
	 *
	 * @return void
	 */
	public function testTwoRowsEncodeToThreeLines(): void {
		$text = CsvCodec::encode(
			[
				['code' => '0363', 'naam' => 'Amsterdam', 'actief' => true],
				['naam' => 'Rotterdam; Centrum', 'code' => 599],
			],
			['delimiter' => ';']
		);

		$this->assertSame("code;naam;actief\n0363;Amsterdam;true\n599;\"Rotterdam; Centrum\";\n", $text);
	}//end testTwoRowsEncodeToThreeLines()

	/**
	 * Encoding without a header writes only the rows; a nested value is written as JSON.
	 *
	 * @return void
	 */
	public function testEncodeWithoutAHeader(): void {
		$text = CsvCodec::encode([['a' => 'x', 'b' => ['k' => 1]]], ['header' => false]);

		$this->assertSame("x,\"{\"\"k\"\":1}\"\n", $text);
	}//end testEncodeWithoutAHeader()

	/**
	 * What encode writes, decode reads back.
	 *
	 * @return void
	 */
	public function testEncodeAndDecodeRoundTrip(): void {
		$rows = [['id' => '1', 'tekst' => "twee\nregels, met \"aanhalingstekens\""]];

		$this->assertSame($rows, CsvCodec::decode(CsvCodec::encode($rows)));
	}//end testEncodeAndDecodeRoundTrip()

	/**
	 * A delimiter of more than one character is refused.
	 *
	 * @return void
	 */
	public function testALongDelimiterIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('one character');

		CsvCodec::decode('a', ['delimiter' => '::']);
	}//end testALongDelimiterIsRefused()
}//end class
