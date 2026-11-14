<?php

/**
 * An FTPS connection (explicit TLS) through curl.
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
 * curl, not PHP's `ftp_ssl_connect()`: the ftp extension does not verify the
 * server's certificate, and an FTPS source that accepts any certificate gives
 * the password to whoever answers. curl verifies the certificate and the host
 * name, and `CURLUSESSL_ALL` refuses a server that will not switch to TLS.
 * Each call is its own curl request on the same login.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
final class FtpsSession implements RemoteFileSession {

	/**
	 * Constructor.
	 *
	 * @param RemoteServer $server   The server.
	 * @param string       $password The password, from the credential broker.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RemoteServer $server,
		#[\SensitiveParameter]
		private readonly string $password,
	) {

	}//end __construct()

	/**
	 * Parse an MLSD answer (RFC 3659) into entries.
	 *
	 * @param string $answer The raw MLSD lines.
	 *
	 * @return list<array{name: string, size: int|null, modifiedAt: int|null, isDirectory: bool}>
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public static function parseMlsd(string $answer): array {
		$entries = [];
		$lines = preg_split('/\r?\n/', $answer);
		if ($lines === false) {
			return [];
		}

		foreach ($lines as $line) {
			$space = strpos($line, ' ');
			if ($space === false) {
				continue;
			}

			$facts = [];
			foreach (explode(';', substr($line, 0, $space)) as $fact) {
				if (str_contains($fact, '=') === true) {
					[$key, $value] = explode('=', $fact, 2);
					$facts[strtolower($key)] = $value;
				}
			}

			$name = substr($line, $space + 1);
			$type = strtolower($facts['type'] ?? 'file');
			if ($name === '' || $type === 'cdir' || $type === 'pdir') {
				continue;
			}

			$modified = null;
			if (isset($facts['modify']) === true) {
				$time     = \DateTimeImmutable::createFromFormat('YmdHis', substr($facts['modify'], 0, 14), new \DateTimeZone('UTC'));
				if ($time !== false) {
					$modified = $time->getTimestamp();
				}
			}

			$size = null;
			if (isset($facts['size']) === true) {
				$size = (int)$facts['size'];
			}

			$entries[] = [
				'name'        => $name,
				'size'        => $size,
				'modifiedAt'  => $modified,
				'isDirectory' => $type === 'dir',
			];
		}//end foreach

		return $entries;
	}//end parseMlsd()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $directory The server path of the folder.
	 *
	 * @return list<array{name: string, size: int|null, modifiedAt: int|null, isDirectory: bool}>
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function listDirectory(string $directory): array {
		$answer = $this->request(path: rtrim($directory, '/') . '/', options: [CURLOPT_CUSTOMREQUEST => 'MLSD', CURLOPT_RETURNTRANSFER => true]);

		return self::parseMlsd(answer: (string)$answer);
	}//end listDirectory()

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $path   The server path of the file.
	 * @param resource $target The stream to write to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function fetch(string $path, $target): void {
		$this->request(path: $path, options: [CURLOPT_FILE => $target]);
	}//end fetch()

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $path   The server path of the file.
	 * @param resource $source The stream to read from.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function deliver(string $path, $source): void {
		$this->request(path: $path, options: [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $source]);
	}//end deliver()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $from The current server path.
	 * @param string $to   The new server path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function move(string $from, string $to): void {
		$this->request(path: '/', options: [CURLOPT_NOBODY => true, CURLOPT_QUOTE => ['RNFR ' . $from, 'RNTO ' . $to]]);
	}//end move()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path The server path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function delete(string $path): void {
		$this->request(path: '/', options: [CURLOPT_NOBODY => true, CURLOPT_QUOTE => ['DELE ' . $path]]);
	}//end delete()

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function close(): void {
	}//end close()

	/**
	 * One curl request against the server, TLS required and verified.
	 *
	 * @param string             $path    The server path.
	 * @param array<int, mixed>  $options Extra curl options.
	 *
	 * @return string|bool The transfer result.
	 *
	 * @throws RemoteFileException When the request fails; the message names the curl error, never the password.
	 */
	private function request(string $path, array $options): string|bool {
		$handle = curl_init();
		if ($handle === false) {
			throw new RemoteFileException('The FTPS connection could not be prepared.');
		}

		$url = 'ftp://' . $this->server->host . ':' . $this->server->port . str_replace(['%2F', '%20'], ['/', '%20'], rawurlencode($path));
		curl_setopt_array(
			$handle,
			[
				CURLOPT_URL            => $url,
				CURLOPT_USE_SSL        => CURLUSESSL_ALL,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_USERNAME       => $this->server->username,
				CURLOPT_PASSWORD       => $this->password,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_NOCWD,
				CURLOPT_PROTOCOLS      => CURLPROTO_FTP,
			] + $options
		);

		$result = curl_exec($handle);
		$error  = curl_error($handle);
		curl_close($handle);
		if ($result === false) {
			throw new RemoteFileException('The FTPS server ' . $this->server->host . ' refused the request for "' . $path . '": ' . $error);
		}

		return $result;
	}//end request()
}//end class
