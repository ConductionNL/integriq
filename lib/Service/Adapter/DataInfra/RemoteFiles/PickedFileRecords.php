<?php

/**
 * Reads the records out of one picked-up file, for a pickup that feeds the mapping.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles;

use JsonException;

/**
 * The format comes from `sourceConfig.fileFormat` (`csv`, `json` or `xml`),
 * else from the file's extension:
 * - CSV: the first row names the columns, every further row is a record; the
 *   delimiter is `sourceConfig.csvDelimiter` (default `,`; `tab` or `\t` for a tab).
 * - JSON: a list gives one record per element, an object is one record;
 *   `.jsonl` / `.ndjson` give one record per line.
 * - XML: every child element of the root is a record, read as nested arrays.
 *
 * A file that cannot be read whole throws, so the pickup leaves it on the server.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */
final class PickedFileRecords {

	/**
	 * The formats a pickup can hand to the mapping.
	 */
	public const FORMATS = ['csv', 'json', 'jsonl', 'xml'];

	/**
	 * The largest file read into records, in bytes.
	 */
	public const MAX_BYTES = 20971520;

	/**
	 * File extensions per format.
	 */
	private const EXTENSIONS = [
		'csv'    => 'csv',
		'json'   => 'json',
		'jsonl'  => 'jsonl',
		'ndjson' => 'jsonl',
		'xml'    => 'xml',
	];

	/**
	 * The format of one file.
	 *
	 * @param string               $name   The file name.
	 * @param array<string, mixed> $config The synchronization's sourceConfig.
	 *
	 * @return string One of FORMATS.
	 *
	 * @throws RemoteFileException When the format is neither configured nor known from the extension.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function formatOf(string $name, array $config): string {
		$configured = strtolower(trim((string)($config['fileFormat'] ?? '')));
		if ($configured !== '' && $configured !== 'auto') {
			if (in_array($configured, self::FORMATS, true) === false) {
				throw new RemoteFileException('sourceConfig.fileFormat must be csv, json, jsonl or xml.');
			}

			return $configured;
		}

		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (isset(self::EXTENSIONS[$extension]) === false) {
			throw new RemoteFileException('"' . $name . '" is not a CSV, JSON or XML file; set sourceConfig.fileFormat to read it.');
		}

		return self::EXTENSIONS[$extension];
	}//end formatOf()

	/**
	 * The records in one file.
	 *
	 * @param string               $name    The file name.
	 * @param string               $content The file's content.
	 * @param array<string, mixed> $config  The synchronization's sourceConfig.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RemoteFileException When the file is too large or cannot be read.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function read(string $name, string $content, array $config): array {
		if (strlen($content) > self::MAX_BYTES) {
			throw new RemoteFileException('"' . $name . '" is larger than ' . self::MAX_BYTES . ' bytes and is not read into records.');
		}

		// A UTF-8 byte order mark would otherwise end up in the first column name.
		if (str_starts_with($content, "\xEF\xBB\xBF") === true) {
			$content = substr($content, 3);
		}

		return match ($this->formatOf(name: $name, config: $config)) {
			'csv'   => self::csv(name: $name, content: $content, delimiter: self::delimiterOf(config: $config)),
			'json'  => self::json(name: $name, content: $content),
			'jsonl' => self::jsonLines(name: $name, content: $content),
			default => self::xml(name: $name, content: $content),
		};
	}//end read()

	/**
	 * The CSV delimiter.
	 *
	 * @param array<string, mixed> $config The synchronization's sourceConfig.
	 *
	 * @return string One character.
	 *
	 * @throws RemoteFileException When the configured delimiter is not one character.
	 */
	private static function delimiterOf(array $config): string {
		$delimiter = (string)($config['csvDelimiter'] ?? ',');
		if ($delimiter === '\t' || strtolower($delimiter) === 'tab') {
			$delimiter = "\t";
		}

		if (strlen($delimiter) !== 1) {
			throw new RemoteFileException('sourceConfig.csvDelimiter must be one character, or tab.');
		}

		return $delimiter;
	}//end delimiterOf()

	/**
	 * Records from CSV: the header row names the columns.
	 *
	 * @param string $name      The file name, for messages.
	 * @param string $content   The content.
	 * @param string $delimiter The delimiter.
	 *
	 * @return list<array<string, string|null>>
	 *
	 * @throws RemoteFileException When the header is missing or a row has another number of columns.
	 */
	private static function csv(string $name, string $content, string $delimiter): array {
		$stream = fopen('php://temp', 'w+b');
		if ($stream === false) {
			throw new RemoteFileException('No temporary stream to read "' . $name . '".');
		}

		fwrite($stream, $content);
		rewind($stream);
		try {
			$header = fgetcsv($stream, null, $delimiter, '"', '');
			if ($header === false || $header === [null]) {
				throw new RemoteFileException('"' . $name . '" has no header row.');
			}

			$columns = array_map(static fn (?string $column): string => trim((string)$column), $header);
			$records = [];
			$line    = 1;
			while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
				$line++;
				if ($row === [null]) {
					continue;
				}

				if (count($row) !== count($columns)) {
					throw new RemoteFileException('"' . $name . '" line ' . $line . ' has ' . count($row) . ' columns, the header has ' . count($columns) . '.');
				}

				$records[] = array_combine($columns, $row);
			}
		} finally {
			fclose($stream);
		}

		return $records;
	}//end csv()

	/**
	 * Records from JSON: a list or one object.
	 *
	 * @param string $name    The file name, for messages.
	 * @param string $content The content.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RemoteFileException When the content is not a JSON object or a list of objects.
	 */
	private static function json(string $name, string $content): array {
		$decoded = self::decode(name: $name, json: $content);
		if (is_array($decoded) === false) {
			throw new RemoteFileException('"' . $name . '" is not a JSON object or list.');
		}

		if (array_is_list($decoded) === false) {
			return [$decoded];
		}

		return self::objectsOnly(name: $name, items: $decoded);
	}//end json()

	/**
	 * Records from JSON lines: one object per non-empty line.
	 *
	 * @param string $name    The file name, for messages.
	 * @param string $content The content.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RemoteFileException When a line is not a JSON object.
	 */
	private static function jsonLines(string $name, string $content): array {
		$items = [];
		$lines = preg_split('/\r\n|\n|\r/', $content);
		if ($lines === false) {
			$lines = [];
		}

		foreach ($lines as $line) {
			if (trim($line) !== '') {
				$items[] = self::decode(name: $name, json: $line);
			}
		}

		return self::objectsOnly(name: $name, items: $items);
	}//end jsonLines()

	/**
	 * Decode JSON, naming the file when it is broken.
	 *
	 * @param string $name The file name, for messages.
	 * @param string $json The JSON.
	 *
	 * @return mixed
	 *
	 * @throws RemoteFileException When the JSON is broken.
	 */
	private static function decode(string $name, string $json): mixed {
		try {
			return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw new RemoteFileException('"' . $name . '" is not valid JSON: ' . $exception->getMessage());
		}
	}//end decode()

	/**
	 * Every item must be an object.
	 *
	 * @param string            $name  The file name, for messages.
	 * @param array<int, mixed> $items The decoded items.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RemoteFileException When an item is not an object.
	 */
	private static function objectsOnly(string $name, array $items): array {
		foreach ($items as $index => $item) {
			if (is_array($item) === false || ($item !== [] && array_is_list($item) === true)) {
				throw new RemoteFileException('"' . $name . '" record ' . ($index + 1) . ' is not an object.');
			}
		}

		return array_values($items);
	}//end objectsOnly()

	/**
	 * Records from XML: every child element of the root.
	 *
	 * A document type declaration is refused, so no entity is ever expanded,
	 * and the network is never used.
	 *
	 * @param string $name    The file name, for messages.
	 * @param string $content The content.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws RemoteFileException When the content is not well-formed XML or declares a document type.
	 */
	private static function xml(string $name, string $content): array {
		if (stripos($content, '<!DOCTYPE') !== false) {
			throw new RemoteFileException('"' . $name . '" declares a document type, which a pickup does not read.');
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$root = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($root === false) {
			throw new RemoteFileException('"' . $name . '" is not well-formed XML.');
		}

		$records = [];
		foreach ($root->children() as $child) {
			$records[] = self::elementRecord(element: $child);
		}

		return $records;
	}//end xml()

	/**
	 * One element as a record: its attributes and children, or its text as `value`.
	 *
	 * @param \SimpleXMLElement $element The element.
	 *
	 * @return array<string, mixed>
	 */
	private static function elementRecord(\SimpleXMLElement $element): array {
		if ($element->count() === 0 && $element->attributes()->count() === 0) {
			return ['value' => (string)$element];
		}

		$record = json_decode((string)json_encode($element), true);
		if (is_array($record) === false) {
			return ['value' => (string)$element];
		}

		return $record;
	}//end elementRecord()
}//end class
