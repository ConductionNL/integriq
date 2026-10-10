<?php

/**
 * SFTP connection over phpseclib 3.
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

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use RuntimeException;

/**
 * An SFTP connection: host key read before login, password or key login.
 *
 * phpseclib 3 (MIT) is required by integriq because Nextcloud 32 to 34 bundle
 * phpseclib 2 (`phpseclib\`) and only Nextcloud 35 bundles 3.0.55; the
 * `phpseclib3\` namespace does not clash with the older one (design D1).
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
class SftpClient implements RemoteFileClient {

	/**
	 * Seconds before a silent server is given up on.
	 *
	 * @var int
	 */
	private const TIMEOUT = 30;

	/**
	 * SFTP file type of a regular file (NET_SFTP_TYPE_REGULAR).
	 *
	 * @var int
	 */
	private const TYPE_REGULAR = 1;

	/**
	 * The open connection.
	 *
	 * @var SFTP|null
	 */
	private ?SFTP $sftp = null;

	/**
	 * Open the connection without logging in.
	 *
	 * @param string $host The host name.
	 * @param int    $port The port.
	 *
	 * @return void
	 */
	public function open(string $host, int $port): void {
		$this->sftp = new SFTP(host: $host, port: $port, timeout: self::TIMEOUT);

	}//end open()

	/**
	 * The SHA-256 fingerprint of the server's host key, as OpenSSH prints it.
	 *
	 * @return string The fingerprint.
	 *
	 * @throws RuntimeException When the key exchange fails.
	 */
	public function serverFingerprint(): string {
		$key = $this->connection()->getServerPublicHostKey();
		if (is_string($key) === false || $key === '') {
			throw new RuntimeException(message: 'The server did not present a host key.');
		}

		return self::fingerprintOf(publicKey: $key);

	}//end serverFingerprint()

	/**
	 * The OpenSSH SHA-256 fingerprint of a public key line.
	 *
	 * @param string $publicKey `<type> <base64 blob>` as the server sends it.
	 *
	 * @return string `SHA256:` followed by the unpadded base64 digest of the blob.
	 */
	public static function fingerprintOf(string $publicKey): string {
		$parts = explode(' ', trim($publicKey));
		$blob = base64_decode(($parts[1] ?? $parts[0]), true);
		if ($blob === false) {
			$blob = $publicKey;
		}

		return 'SHA256:' . rtrim(base64_encode(hash(algo: 'sha256', data: $blob, binary: true)), '=');

	}//end fingerprintOf()

	/**
	 * Log in with a password or a private key.
	 *
	 * @param string $username The user name.
	 * @param string $secret   The password, or the private key for `ssh-key`.
	 * @param string $authMode `basic` or `ssh-key`.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the server refuses the login.
	 */
	public function login(string $username, string $secret, string $authMode): void {
		$credential = $secret;
		if ($authMode === 'ssh-key') {
			$credential = PublicKeyLoader::load($secret);
		}

		if ($this->connection()->login($username, $credential) !== true) {
			throw new RuntimeException(message: 'The server refused the login for "' . $username . '".');
		}

	}//end login()

	/**
	 * The files in a folder.
	 *
	 * @param string $path The folder.
	 *
	 * @return array<int,array{name:string,size:int,mtime:int}> The files.
	 *
	 * @throws RuntimeException When the folder cannot be read.
	 */
	public function listFiles(string $path): array {
		$entries = $this->connection()->rawlist($path);
		if (is_array($entries) === false) {
			throw new RuntimeException(message: 'The folder "' . $path . '" could not be read.');
		}

		$files = [];
		foreach ($entries as $name => $attributes) {
			if ((int)($attributes['type'] ?? 0) !== self::TYPE_REGULAR) {
				continue;
			}

			$files[] = [
				'name' => (string)$name,
				'size' => (int)($attributes['size'] ?? 0),
				'mtime' => (int)($attributes['mtime'] ?? 0),
			];
		}

		return $files;

	}//end listFiles()

	/**
	 * Download a file straight to disk.
	 *
	 * @param string $remotePath The remote file.
	 * @param string $localPath  The local file to write.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the download fails.
	 */
	public function download(string $remotePath, string $localPath): void {
		if ($this->connection()->get($remotePath, $localPath) === false) {
			throw new RuntimeException(message: 'The file "' . $remotePath . '" could not be downloaded.');
		}

	}//end download()

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
	public function upload(string $remotePath, string $localPath): void {
		if ($this->connection()->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE) !== true) {
			throw new RuntimeException(message: 'The file "' . $remotePath . '" could not be uploaded.');
		}

	}//end upload()

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
	public function rename(string $from, string $to): void {
		if ($this->connection()->rename($from, $to) !== true) {
			throw new RuntimeException(message: 'The file "' . $from . '" could not be moved to "' . $to . '".');
		}

	}//end rename()

	/**
	 * Delete a remote file.
	 *
	 * @param string $path The file.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the delete fails.
	 */
	public function delete(string $path): void {
		if ($this->connection()->delete($path, false) !== true) {
			throw new RuntimeException(message: 'The file "' . $path . '" could not be deleted.');
		}

	}//end delete()

	/**
	 * Close the connection.
	 *
	 * @return void
	 */
	public function close(): void {
		$this->sftp?->disconnect();
		$this->sftp = null;

	}//end close()

	/**
	 * The open connection.
	 *
	 * @return SFTP The connection.
	 *
	 * @throws RuntimeException When open() was not called.
	 */
	private function connection(): SFTP {
		if ($this->sftp === null) {
			throw new RuntimeException(message: 'The SFTP connection is not open.');
		}

		return $this->sftp;

	}//end connection()
}//end class
