<?php

/**
 * An open connection to an SFTP or FTPS server.
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
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles;

/**
 * Every path given to a session is an absolute server path already confined by
 * {@see RemotePath}; the session does no path checks of its own.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
interface RemoteFileSession {

	/**
	 * The entries of a folder.
	 *
	 * @param string $directory The server path of the folder.
	 *
	 * @return list<array{name: string, size: int|null, modifiedAt: int|null, isDirectory: bool}>
	 *
	 * @throws RemoteFileException When the folder cannot be read.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function listDirectory(string $directory): array;

	/**
	 * Copy a remote file into a local stream.
	 *
	 * @param string   $path   The server path of the file.
	 * @param resource $target The stream to write to.
	 *
	 * @return void
	 *
	 * @throws RemoteFileException When the file cannot be read.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function fetch(string $path, $target): void;

	/**
	 * Write a local stream to a remote file.
	 *
	 * @param string   $path   The server path of the file.
	 * @param resource $source The stream to read from.
	 *
	 * @return void
	 *
	 * @throws RemoteFileException When the file cannot be written.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function deliver(string $path, $source): void;

	/**
	 * Move or rename a remote file.
	 *
	 * @param string $from The current server path.
	 * @param string $to   The new server path.
	 *
	 * @return void
	 *
	 * @throws RemoteFileException When the move fails.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function move(string $from, string $to): void;

	/**
	 * Delete a remote file.
	 *
	 * @param string $path The server path.
	 *
	 * @return void
	 *
	 * @throws RemoteFileException When the delete fails.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function delete(string $path): void;

	/**
	 * Close the connection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function close(): void;
}//end interface
