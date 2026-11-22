<?php

/**
 * Where a picked-up file is written locally.
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
 * A store either keeps the file or throws: the pickup only archives or deletes
 * the remote file after `store()` returned.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */
interface PickedFileStore {

	/**
	 * Write one picked-up file.
	 *
	 * @param string   $folder The folder, relative to the store's root.
	 * @param string   $name   The file name.
	 * @param resource $stream The content, positioned at its start.
	 *
	 * @return string Where the file was written, for the run log.
	 *
	 * @throws \Throwable When the file was not written.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function store(string $folder, string $name, $stream): string;
}//end interface
