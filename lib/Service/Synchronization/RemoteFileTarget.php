<?php

/**
 * Delivers a mapped object to an SFTP or FTPS server as one CSV file.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileClient;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\RemoteFileException;

/**
 * The `sftp` and `ftps` target types of a push.
 *
 * The mapped object names the file and holds its rows: `fileName` and `rows`
 * by default, other properties through `targetConfig.fileNameField` and
 * `targetConfig.rowsField`. The rows are written as CSV with a header row
 * (`targetConfig.delimiter`, default `,`) into `targetConfig.path` on the
 * target source, through the remote file client, so the egress guard, the
 * root path, the host key pin and the brokered credential apply as they do
 * to a pickup. A repeated push of the same object writes the same file
 * again; a delivered file is never deleted.
 *
 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-push-can-deliver-a-file-to-an-sftp-or-ftps-server-req-hrx-005
 */
class RemoteFileTarget {

	/**
	 * The target types this writer serves.
	 *
	 * @var list<string>
	 */
	public const TYPES = ['sftp', 'ftps'];

	/**
	 * Constructor.
	 *
	 * @param RemoteFileClient $client The remote file client.
	 */
	public function __construct(
		private readonly RemoteFileClient $client,
	) {
	}//end __construct()

	/**
	 * Write one mapped object as a file, and answer the path it was written to.
	 *
	 * @param array<string, mixed> $source       The target source.
	 * @param array<string, mixed> $targetConfig The synchronization's target configuration.
	 * @param array<string, mixed> $targetObject The mapped object.
	 *
	 * @return array{path: string, fileName: string, rows: int} What was delivered.
	 *
	 * @throws RemoteFileException When the object names no usable file or the server refuses.
	 *
	 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-push-can-deliver-a-file-to-an-sftp-or-ftps-server-req-hrx-005
	 */
	public function deliver(array $source, array $targetConfig, array $targetObject): array {
		$fileName = trim((string)($targetObject[(string)($targetConfig['fileNameField'] ?? 'fileName')] ?? ''));
		$hasPath = (str_contains($fileName, '/') === true || str_contains($fileName, '\\') === true);
		if ($fileName === '' || $hasPath === true || $fileName === '.' || $fileName === '..') {
			throw new RemoteFileException(message: 'The mapped object names no file to deliver, or a file name with a path in it: "' . $fileName . '".');
		}

		$rows = ($targetObject[(string)($targetConfig['rowsField'] ?? 'rows')] ?? []);
		if (is_array($rows) === false || array_is_list($rows) === false) {
			throw new RemoteFileException(message: 'The mapped object\'s rows are not a list, so there is no file to write.');
		}

		$delimiter = (string)($targetConfig['delimiter'] ?? ',');
		if (strlen($delimiter) !== 1) {
			throw new RemoteFileException(message: 'The delimiter must be one character, not "' . $delimiter . '".');
		}

		$path   = rtrim((string)($targetConfig['path'] ?? ''), '/') . '/' . $fileName;
		$stream = fopen('php://temp', 'r+');
		$this->writeCsv(stream: $stream, rows: $rows, delimiter: $delimiter);
		rewind($stream);
		try {
			$this->client->deliver(source: $source, path: ltrim($path, '/'), stream: $stream);
		} finally {
			fclose($stream);
		}

		return ['path' => $path, 'fileName' => $fileName, 'rows' => count($rows)];
	}//end deliver()

	/**
	 * Write the rows as CSV: a header row with the first row's keys, then each row in that column order.
	 *
	 * A value missing from a row is written empty; a list or object is written as JSON.
	 *
	 * @param resource    $stream    The stream to write to.
	 * @param list<mixed> $rows      The rows.
	 * @param string      $delimiter The delimiter, one character.
	 *
	 * @return void
	 */
	private function writeCsv($stream, array $rows, string $delimiter): void {
		if ($rows === []) {
			return;
		}

		$columns = array_map('strval', array_keys((array)$rows[0]));
		fputcsv($stream, $columns, $delimiter, '"', '');
		foreach ($rows as $row) {
			$row   = (array)$row;
			$cells = [];
			foreach ($columns as $column) {
				$value = ($row[$column] ?? '');
				if (is_array($value) === true || is_object($value) === true) {
					$value = (string)json_encode($value);
				}

				$cells[] = (string)$value;
			}

			fputcsv($stream, $cells, $delimiter, '"', '');
		}
	}//end writeCsv()
}//end class
