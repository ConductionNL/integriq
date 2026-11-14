<?php

/**
 * Keeps the records of picked-up files for the mapping, instead of writing the files to Nextcloud.
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

/**
 * A file whose records cannot be read throws from `store()`, so the pickup
 * counts it as failed and leaves it on the server.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */
final class MappingRecordStore implements PickedFileStore {

	/**
	 * The records read so far, in pickup order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $records = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config The synchronization's sourceConfig (format and delimiter).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly array $config,
	) {
	}//end __construct()

	/**
	 * Read the records of one picked-up file.
	 *
	 * @param string   $folder Unused: the records are not written to Files.
	 * @param string   $name   The file name.
	 * @param resource $stream The content, positioned at its start.
	 *
	 * @return string Where the records went, for the run log.
	 *
	 * @throws RemoteFileException When the file cannot be read into records.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The PickedFileStore signature.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function store(string $folder, string $name, $stream): string {
		$content = stream_get_contents($stream, (PickedFileRecords::MAX_BYTES + 1));
		if ($content === false) {
			throw new RemoteFileException('"' . $name . '" could not be read.');
		}

		$records = PickedFileRecords::read(name: $name, content: $content, config: $this->config);
		foreach ($records as $record) {
			$this->records[] = $record;
		}

		return 'mapping:' . $name . ' (' . count($records) . ' records)';
	}//end store()

	/**
	 * The records of every file stored so far.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function records(): array {
		return $this->records;
	}//end records()
}//end class
