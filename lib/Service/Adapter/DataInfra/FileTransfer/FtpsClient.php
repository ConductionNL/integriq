<?php

/**
 * FTPS (explicit TLS) connection over curl.
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

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * An FTPS connection with explicit TLS and a checked server certificate.
 *
 * Design D1 named PHP's `ftp` extension, but `ftp_ssl_connect()` never checks
 * the server certificate, so a man in the middle would get the password. curl
 * requires TLS on the control and data channel (`CURLUSESSL_ALL`) and verifies
 * the certificate chain and host name, which is the certificate check
 * REQ-SFTP-001 asks for. Plain FTP is out of scope.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 *
 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromFormat is the PHP parser for the MLSD time stamp.
 */
class FtpsClient implements RemoteFileClient {

	/**
	 * Seconds before a silent server is given up on.
	 *
	 * @var int
	 */
	private const TIMEOUT = 30;

	/**
	 * The host.
	 *
	 * @var string
	 */
	private string $host = '';

	/**
	 * The port.
	 *
	 * @var int
	 */
	private int $port = 21;

	/**
	 * `user:password` for curl.
	 *
	 * @var string
	 */
	private string $userPassword = '';

	/**
	 * Remember where to connect; curl opens a connection per operation.
	 *
	 * @param string $host The host name.
	 * @param int    $port The port.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the curl extension is missing.
	 */
	public function open(string $host, int $port): void {
		if (function_exists('curl_init') === false) {
			throw new RuntimeException(message: 'FTPS needs the PHP curl extension, which is not installed.');
		}

		$this->host = $host;
		$this->port = $port;

	}//end open()

	/**
	 * FTPS is trusted through the certificate chain, not a pin.
	 *
	 * @return string Always empty: no fingerprint is pinned for FTPS.
	 */
	public function serverFingerprint(): string {
		return '';

	}//end serverFingerprint()

	/**
	 * Log in with a password; curl checks it with a listing of the root.
	 *
	 * @param string $username The user name.
	 * @param string $secret   The password.
	 * @param string $authMode Must be `basic`.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the mode is not `basic` or the server refuses.
	 */
	public function login(string $username, string $secret, string $authMode): void {
		if ($authMode !== 'basic') {
			throw new RuntimeException(message: 'FTPS supports a user name and password only.');
		}

		$this->userPassword = $username . ':' . $secret;
		$this->perform(path: '/', options: [CURLOPT_NOBODY => true]);

	}//end login()

	/**
	 * The files in a folder, read with MLSD.
	 *
	 * @param string $path The folder.
	 *
	 * @return array<int,array{name:string,size:int,mtime:int}> The files.
	 */
	public function listFiles(string $path): array {
		$listing = (string)$this->perform(
			path: rtrim($path, '/') . '/',
			options: [CURLOPT_CUSTOMREQUEST => 'MLSD', CURLOPT_RETURNTRANSFER => true]
		);

		return self::parseMlsd(listing: $listing);

	}//end listFiles()

	/**
	 * Parse an MLSD listing into files.
	 *
	 * @param string $listing The raw listing, one `facts; name` line per entry.
	 *
	 * @return array<int,array{name:string,size:int,mtime:int}> The regular files.
	 */
	public static function parseMlsd(string $listing): array {
		$files = [];
		foreach (preg_split('/\r?\n/', $listing) as $line) {
			$split = strpos($line, ' ');
			if ($split === false) {
				continue;
			}

			$facts = [];
			foreach (explode(';', strtolower(substr($line, 0, $split))) as $fact) {
				if (str_contains($fact, '=') === true) {
					[$key, $value] = explode('=', $fact, 2);
					$facts[$key] = $value;
				}
			}

			if (($facts['type'] ?? '') !== 'file') {
				continue;
			}

			$modified = DateTimeImmutable::createFromFormat('YmdHis', substr(($facts['modify'] ?? ''), 0, 14), new DateTimeZone('UTC'));
			$mtime = 0;
			if ($modified !== false) {
				$mtime = $modified->getTimestamp();
			}

			$files[] = [
				'name' => substr($line, ($split + 1)),
				'size' => (int)($facts['size'] ?? 0),
				'mtime' => $mtime,
			];
		}//end foreach

		return $files;

	}//end parseMlsd()

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
		$handle = fopen($localPath, 'wb');
		if ($handle === false) {
			throw new RuntimeException(message: 'The local file could not be opened for writing.');
		}

		try {
			$this->perform(path: $remotePath, options: [CURLOPT_FILE => $handle]);
		} finally {
			fclose($handle);
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
		$handle = fopen($localPath, 'rb');
		if ($handle === false) {
			throw new RuntimeException(message: 'The local file could not be opened for reading.');
		}

		try {
			$this->perform(
				path: $remotePath,
				options: [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $handle, CURLOPT_INFILESIZE => (int)filesize($localPath)]
			);
		} finally {
			fclose($handle);
		}

	}//end upload()

	/**
	 * Move a remote file with RNFR and RNTO.
	 *
	 * @param string $from The current path.
	 * @param string $to   The new path.
	 *
	 * @return void
	 */
	public function rename(string $from, string $to): void {
		$this->perform(path: '/', options: [CURLOPT_NOBODY => true, CURLOPT_QUOTE => ['RNFR ' . $from, 'RNTO ' . $to]]);

	}//end rename()

	/**
	 * Delete a remote file with DELE.
	 *
	 * @param string $path The file.
	 *
	 * @return void
	 */
	public function delete(string $path): void {
		$this->perform(path: '/', options: [CURLOPT_NOBODY => true, CURLOPT_QUOTE => ['DELE ' . $path]]);

	}//end delete()

	/**
	 * Nothing to close: curl opens a connection per operation.
	 *
	 * @return void
	 */
	public function close(): void {
		$this->userPassword = '';

	}//end close()

	/**
	 * Run one curl request against the server with TLS required and verified.
	 *
	 * @param string              $path    The remote path.
	 * @param array<int,mixed>    $options Extra curl options.
	 *
	 * @return string|bool What curl returned.
	 *
	 * @throws RuntimeException When curl reports an error.
	 */
	private function perform(string $path, array $options): string|bool {
		$segments = array_map('rawurlencode', explode('/', ltrim($path, '/')));
		$url = sprintf('ftp://%s:%d/%s', $this->host, $this->port, implode('/', $segments));

		$curl = curl_init();
		curl_setopt_array(
			$curl,
			$options + [
				CURLOPT_URL => $url,
				CURLOPT_USERPWD => $this->userPassword,
				CURLOPT_USE_SSL => CURLUSESSL_ALL,
				CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
				CURLOPT_TIMEOUT => (self::TIMEOUT * 10),
			]
		);
		$result = curl_exec($curl);
		$error = curl_error($curl);
		curl_close($curl);

		if ($result === false) {
			throw new RuntimeException(message: 'The FTPS server answered with an error: ' . $error);
		}

		return $result;

	}//end perform()
}//end class
