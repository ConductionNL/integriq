<?php

/**
 * One connection to a partner's file server (SFTP or FTPS).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\FileTransfer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\FileTransfer;

use RuntimeException;

/**
 * The protocol seam under the SFTP and FTPS adapters.
 *
 * The adapter opens a connection, reads the server's identity, checks it
 * against the pin, and only then logs in, so a credential never reaches a
 * server whose key changed. Paths handed in are already confined to the
 * source's root path by {@see RemotePath}.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
interface RemoteFileClient {

	/**
	 * Open the connection without logging in.
	 *
	 * @param string $host The host name.
	 * @param int    $port The port.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the server cannot be reached.
	 */
	public function open(string $host, int $port): void;

	/**
	 * The server's identity: an SSH host key or a TLS certificate, as a SHA-256 fingerprint.
	 *
	 * @return string The fingerprint, `SHA256:` followed by unpadded base64.
	 */
	public function serverFingerprint(): string;

	/**
	 * Log in.
	 *
	 * @param string $username The user name.
	 * @param string $secret   The password, or the private key for `ssh-key`.
	 * @param string $authMode `basic` or `ssh-key`.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the server refuses the login.
	 */
	public function login(string $username, string $secret, string $authMode): void;

	/**
	 * The files in a folder (no folders, no links).
	 *
	 * @param string $path The folder.
	 *
	 * @return array<int,array{name:string,size:int,mtime:int}> The files.
	 */
	public function listFiles(string $path): array;

	/**
	 * Download a file to a local path.
	 *
	 * @param string $remotePath The remote file.
	 * @param string $localPath  The local file to write.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the download fails.
	 */
	public function download(string $remotePath, string $localPath): void;

	/**
	 * Upload a local file.
	 *
	 * @param string $remotePath The remote file to write.
	 * @param string $localPath  The local file to send.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the upload fails.
	 */
	public function upload(string $remotePath, string $localPath): void;

	/**
	 * Move a remote file.
	 *
	 * @param string $from The current path.
	 * @param string $to   The new path.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the move fails.
	 */
	public function rename(string $from, string $to): void;

	/**
	 * Delete a remote file.
	 *
	 * @param string $path The file.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the delete fails.
	 */
	public function delete(string $path): void;

	/**
	 * Close the connection.
	 *
	 * @return void
	 */
	public function close(): void;
}//end interface
