<?php

/**
 * Integriq CsvCodec utility.
 *
 * Decodes delimited text into rows and encodes rows back into it. Reading goes
 * through `fgetcsv()` on a `php://temp` stream rather than splitting on line
 * breaks first, so a quoted field that holds a line break stays one field.
 * Both the file migration source and a synchronization reading a CSV source
 * response use it, so the two cannot drift apart.
 *
 * OpenRegister's ImportService and ExportService read and write CSV for a whole
 * register through PhpSpreadsheet and are bound to a schema; they cannot take
 * a mapping payload, which is why this codec exists beside them.
 *
 * @category Util
 * @package  OCA\Integriq\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @see \OCA\OpenRegister\Service\ImportService
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-synchronization-reads-a-csv-source-response-req-mfl-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Util;

use InvalidArgumentException;

/**
 * CSV decode and encode with configurable delimiter, enclosure and header row.
 *
 * Options, for both directions:
 * - `delimiter`: one character, default `,`;
 * - `enclosure`: one character, default `"`;
 * - `header`: whether the first row names the columns, default true.
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-synchronization-reads-a-csv-source-response-req-mfl-001
 */
final class CsvCodec {

	/**
	 * Decode delimited text into rows.
	 *
	 * With a header row each row is keyed by column name, a missing value is
	 * an empty string and a value beyond the last column is dropped. Without
	 * one each row is a list of its values. Blank lines are skipped.
	 *
	 * @param string              $content The text.
	 * @param array<string,mixed> $options `delimiter`, `enclosure` and `header`.
	 *
	 * @return list<array<int|string,string>> The rows.
	 *
	 * @throws InvalidArgumentException When the delimiter or enclosure is not one character.
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#scenario-a-quoted-line-break-survives
	 */
	public static function decode(string $content, array $options = []): array {
		$records = self::records(content: $content, options: $options);
		if (self::hasHeader(options: $options) === false || $records === []) {
			return $records;
		}

		$headers = array_shift($records);
		$rows = [];
		foreach ($records as $record) {
			$rows[] = self::keyed(headers: $headers, values: $record);
		}

		return $rows;
	}//end decode()

	/**
	 * The column names of delimited text: its first record.
	 *
	 * @param string              $content The text.
	 * @param array<string,mixed> $options `delimiter` and `enclosure`.
	 *
	 * @return list<string> The column names.
	 *
	 * @throws InvalidArgumentException When the delimiter or enclosure is not one character.
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-synchronization-reads-a-csv-source-response-req-mfl-001
	 */
	public static function headers(string $content, array $options = []): array {
		return (self::records(content: $content, options: $options, limit: 1)[0] ?? []);
	}//end headers()

	/**
	 * Encode rows as delimited text, one line per row.
	 *
	 * With a header row the column names are the keys of the first row, and
	 * every row is written in that column order with a missing value empty.
	 *
	 * @param list<array<int|string,mixed>> $rows    The rows; values are written as strings.
	 * @param array<string,mixed>           $options `delimiter`, `enclosure` and `header`.
	 *
	 * @return string The text, each line ending in `\n`.
	 *
	 * @throws InvalidArgumentException When the delimiter or enclosure is not one character.
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
	 */
	public static function encode(array $rows, array $options = []): string {
		[$delimiter, $enclosure] = self::characters(options: $options);
		$columns = [];
		if ($rows !== []) {
			$columns = array_keys((array)reset($rows));
		}

		$stream = fopen('php://temp', 'r+');
		if (self::hasHeader(options: $options) === true && $columns !== []) {
			fputcsv($stream, array_map('strval', $columns), $delimiter, $enclosure, '');
		}

		foreach ($rows as $row) {
			fputcsv($stream, self::ordered(columns: $columns, row: $row), $delimiter, $enclosure, '');
		}

		rewind($stream);
		$text = (string)stream_get_contents($stream);
		fclose($stream);

		return $text;
	}//end encode()

	/**
	 * Every non-blank record of the text, as lists of strings.
	 *
	 * @param string              $content The text.
	 * @param array<string,mixed> $options `delimiter` and `enclosure`.
	 * @param int                 $limit   The most records read; 0 for all.
	 *
	 * @return list<list<string>> The records.
	 *
	 * @throws InvalidArgumentException When the delimiter or enclosure is not one character.
	 */
	private static function records(string $content, array $options, int $limit = 0): array {
		[$delimiter, $enclosure] = self::characters(options: $options);
		$stream = fopen('php://temp', 'r+');
		fwrite($stream, self::withoutBom(content: $content));
		rewind($stream);

		$records = [];
		$record = fgetcsv($stream, null, $delimiter, $enclosure, '');
		while ($record !== false && ($limit === 0 || count($records) < $limit)) {
			if ($record !== [null]) {
				$records[] = array_map(static fn ($value): string => (string)$value, $record);
			}

			$record = fgetcsv($stream, null, $delimiter, $enclosure, '');
		}

		fclose($stream);

		return $records;
	}//end records()

	/**
	 * A record keyed by the header's column names.
	 *
	 * @param list<string> $headers The column names.
	 * @param list<string> $values  The record.
	 *
	 * @return array<string,string> The row.
	 */
	private static function keyed(array $headers, array $values): array {
		$row = [];
		foreach ($headers as $index => $header) {
			$row[$header] = ($values[$index] ?? '');
		}

		return $row;
	}//end keyed()

	/**
	 * A row's values in column order, a missing value empty.
	 *
	 * @param list<int|string>          $columns The column order.
	 * @param array<int|string,mixed>   $row     The row.
	 *
	 * @return list<string> The values.
	 */
	private static function ordered(array $columns, array $row): array {
		$values = [];
		foreach ($columns as $column) {
			$values[] = self::cell(value: ($row[$column] ?? ''));
		}

		return $values;
	}//end ordered()

	/**
	 * One value as cell text: scalars as themselves, booleans as true or false, the rest as JSON.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private static function cell(mixed $value): string {
		if (is_bool($value) === true) {
			return var_export($value, true);
		}

		if (is_scalar($value) === true || $value === null) {
			return (string)$value;
		}

		return (string)json_encode($value);
	}//end cell()

	/**
	 * Whether the options say the first row names the columns (default true).
	 *
	 * @param array<string,mixed> $options The options.
	 *
	 * @return bool
	 */
	private static function hasHeader(array $options): bool {
		return ($options['header'] ?? true) !== false;
	}//end hasHeader()

	/**
	 * The delimiter and enclosure from the options, each one character.
	 *
	 * @param array<string,mixed> $options The options.
	 *
	 * @return array{0: string, 1: string} The delimiter and enclosure.
	 *
	 * @throws InvalidArgumentException When either is not one character.
	 */
	private static function characters(array $options): array {
		$delimiter = (string)($options['delimiter'] ?? ',');
		$enclosure = (string)($options['enclosure'] ?? '"');
		if (strlen($delimiter) !== 1 || strlen($enclosure) !== 1) {
			throw new InvalidArgumentException('A CSV delimiter and enclosure must each be one character.');
		}

		return [$delimiter, $enclosure];
	}//end characters()

	/**
	 * The text without a leading UTF-8 byte order mark.
	 *
	 * @param string $content The text.
	 *
	 * @return string The text.
	 */
	private static function withoutBom(string $content): string {
		if (str_starts_with($content, "\xEF\xBB\xBF") === true) {
			return substr($content, 3);
		}

		return $content;
	}//end withoutBom()
}//end class
