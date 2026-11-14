<?php

/**
 * Integriq MappingOutput utility.
 *
 * Writes a mapping's result in the output format the mapping declares: JSON
 * (the default, left to the caller), XML through ArrayToXml, or CSV through
 * CsvCodec. The mapping engine keeps working on arrays; the format is applied
 * at the edge, when the result is shown in a test or sent to a target
 * (mapping-formats-and-lookups D1).
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
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Util;

/**
 * A mapping result in its declared output format.
 *
 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
 */
final class MappingOutput {

	/**
	 * The output formats and their content types.
	 */
	public const CONTENT_TYPES = [
		'json' => 'application/json',
		'xml'  => 'application/xml',
		'csv'  => 'text/csv',
	];

	/**
	 * The format and options a mapping declares.
	 *
	 * A missing or unknown format is JSON, so a mapping written before
	 * output formats existed behaves exactly as it did.
	 *
	 * @param array<string,mixed> $mapping The mapping's fields.
	 *
	 * @return array{format: string, options: array<string,mixed>}
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
	 */
	public function settings(array $mapping): array {
		$format = strtolower(trim((string)($mapping['outputFormat'] ?? 'json')));
		if (isset(self::CONTENT_TYPES[$format]) === false) {
			$format = 'json';
		}

		$options = $mapping['outputOptions'] ?? [];
		if (is_array($options) === false) {
			$options = [];
		}

		return ['format' => $format, 'options' => $options];
	}//end settings()

	/**
	 * Write a mapping result in the declared format.
	 *
	 * For JSON the body is null: the caller keeps sending the array as it did.
	 *
	 * @param array<string,mixed> $mapping The mapping's fields.
	 * @param array<mixed>        $result  The mapping result: one object, or a list of them.
	 *
	 * @return array{format: string, contentType: string, body: string|null}
	 *
	 * @spec openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
	 */
	public function render(array $mapping, array $result): array {
		['format' => $format, 'options' => $options] = $this->settings(mapping: $mapping);

		$body = null;
		if ($format === 'xml') {
			$body = $this->xml(result: $result, options: $options);
		}

		if ($format === 'csv') {
			$body = $this->csv(result: $result, options: $options);
		}

		return ['format' => $format, 'contentType' => self::CONTENT_TYPES[$format], 'body' => $body];
	}//end render()

	/**
	 * The result as XML, under the configured root element.
	 *
	 * A root element option wins over an `@root` key in the result; with
	 * neither the root is `root`. A list is written as `item0`, `item1`, ...
	 * under the root, the xml-response convention for numeric keys.
	 *
	 * @param array<mixed>        $result  The result.
	 * @param array<string,mixed> $options The output options.
	 *
	 * @return string
	 */
	private function xml(array $result, array $options): string {
		$root = trim((string)($options['rootElement'] ?? ''));
		if ($root === '') {
			$root = null;
		}

		return (new ArrayToXml())->convert(data: $result, rootTag: $root);
	}//end xml()

	/**
	 * The result as CSV: one row for an object, one per item for a list.
	 *
	 * A nested value is written as its JSON text, so no cell is lost.
	 *
	 * @param array<mixed>        $result  The result.
	 * @param array<string,mixed> $options The output options.
	 *
	 * @return string
	 */
	private function csv(array $result, array $options): string {
		$rows = [$result];
		if ($result === [] || array_is_list($result) === true) {
			$rows = array_values(array_filter($result, 'is_array'));
		}

		$cells = static fn (mixed $value): string => match (true) {
			is_array($value), is_object($value) => (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			$value === true => 'true',
			$value === false => 'false',
			default => (string)$value,
		};

		$flat = [];
		foreach ($rows as $row) {
			$flat[] = array_map($cells, $row);
		}

		return CsvCodec::encode(
			rows: $flat,
			options: [
				'delimiter' => (string)($options['csvDelimiter'] ?? ','),
				'header'    => ($options['csvHeader'] ?? true) !== false,
			]
		);
	}//end csv()
}//end class
