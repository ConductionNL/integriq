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

use DateTimeZone;
use SensitiveParameter;

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
		#[SensitiveParameter]
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

			$facts = self::factsOf(field: substr($line, 0, $space));

			$name = substr($line, $space + 1);
			$type = strtolower($facts['type'] ?? 'file');
			if ($name === '' || $type === 'cdir' || $type === 'pdir') {
				continue;
			}

			$entries[] = [
				'name'        => $name,
				'size'        => self::sizeOf(facts: $facts),
				'modifiedAt'  => self::modifiedOf(facts: $facts),
				'isDirectory' => $type === 'dir',
			];
		}//end foreach

		return $entries;
	}//end parseMlsd()

	/**
	 * The facts of an MLSD line's fact field, keys lower-cased.
	 *
	 * @param string $field The facts, `key=value;` each.
	 *
	 * @return array<string, string>
	 */
	private static function factsOf(string $field): array {
		$facts = [];
		foreach (explode(';', $field) as $fact) {
			if (str_contains($fact, '=') === true) {
				[$key, $value] = explode('=', $fact, 2);
				$facts[strtolower($key)] = $value;
			}
		}

		return $facts;
	}//end factsOf()

	/**
	 * The size fact as an integer, or null when there is none.
	 *
	 * @param array<string, string> $facts The facts.
	 *
	 * @return int|null
	 */
	private static function sizeOf(array $facts): ?int {
		if (isset($facts['size']) === false) {
			return null;
		}

		return (int)$facts['size'];
	}//end sizeOf()

	/**
	 * The modify fact as a Unix timestamp, or null when there is none or it cannot be read.
	 *
	 * @param array<string, string> $facts The facts.
	 *
	 * @return int|null
	 */
	private static function modifiedOf(array $facts): ?int {
		if (isset($facts['modify']) === false) {
			return null;
		}

		$time = date_create_immutable_from_format('YmdHis', substr($facts['modify'], 0, 14), new DateTimeZone('UTC'));
		if ($time === false) {
			return null;
		}

		return $time->getTimestamp();
	}//end modifiedOf()

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
		$folder = rtrim($directory, '/') . '/';
		try {
			$answer = $this->request(path: $folder, options: [CURLOPT_CUSTOMREQUEST => 'MLSD', CURLOPT_RETURNTRANSFER => true]);
			return self::parseMlsd(answer: (string)$answer);
		} catch (RemoteFileException) {
			// MLSD is optional (RFC 3659); vsftpd, for one, answers 500. LIST is in every server.
		}

		$entries = self::parseList(
			answer: (string)$this->request(path: $folder, options: [CURLOPT_RETURNTRANSFER => true]),
			now: time()
		);
		foreach ($entries as $index => $entry) {
			if ($entry['isDirectory'] === false) {
				$entries[$index]['modifiedAt'] = $this->modifiedTimeOf(path: $folder . $entry['name']) ?? $entry['modifiedAt'];
			}
		}

		return $entries;
	}//end listDirectory()

	/**
	 * Parse a LIST answer into entries: the Unix `ls -l` layout most servers send, and the
	 * MS-DOS layout of IIS. LIST times have minutes at best, so listDirectory() asks MDTM for
	 * the exact time of each file.
	 *
	 * @param string $answer The raw LIST lines.
	 * @param int    $now    The current Unix time; a Unix line without a year is in the last twelve months.
	 *
	 * @return list<array{name: string, size: int|null, modifiedAt: int|null, isDirectory: bool}>
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public static function parseList(string $answer, int $now): array {
		$entries = [];
		$lines = preg_split('/\r?\n/', $answer);
		if ($lines === false) {
			return [];
		}

		foreach ($lines as $line) {
			$entry = self::unixListEntry(line: $line, now: $now) ?? self::dosListEntry(line: $line);
			if ($entry === null || $entry['name'] === '.' || $entry['name'] === '..') {
				continue;
			}

			$entries[] = $entry;
		}

		return $entries;
	}//end parseList()

	/**
	 * One Unix-layout LIST line (`-rw-r--r-- 1 owner group 1204 Nov 14 07:25 name`), or null.
	 * Symbolic links and other special entries are left out: a pickup reads plain files.
	 *
	 * @param string $line The line.
	 * @param int    $now  The current Unix time.
	 *
	 * @return array{name: string, size: int|null, modifiedAt: int|null, isDirectory: bool}|null
	 */
	private static function unixListEntry(string $line, int $now): ?array {
		$pattern = '/^([d-])[rwxsStT-]{9}\S*\s+\d+\s+\S+\s+\S+\s+(\d+)\s+([A-Za-z]{3})\s+(\d{1,2})\s+(\d{1,2}:\d{2}|\d{4})\s(.+)$/';
		if (preg_match($pattern, $line, $match) !== 1) {
			return null;
		}

		return [
			'name'        => $match[6],
			'size'        => (int)$match[2],
			'modifiedAt'  => self::unixListTime(month: $match[3], day: (int)$match[4], yearOrTime: $match[5], now: $now),
			'isDirectory' => $match[1] === 'd',
		];
	}//end unixListEntry()

	/**
	 * The time of a Unix LIST line, in UTC. `HH:MM` means within the last twelve months, so a
	 * date later than tomorrow belongs to last year.
	 *
	 * @param string $month      The month abbreviation.
	 * @param int    $day        The day of the month.
	 * @param string $yearOrTime `HH:MM` or a four-digit year.
	 * @param int    $now        The current Unix time.
	 *
	 * @return int|null
	 */
	private static function unixListTime(string $month, int $day, string $yearOrTime, int $now): ?int {
		$months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
		$number = $months[strtolower($month)] ?? null;
		if ($number === null) {
			return null;
		}

		if (str_contains($yearOrTime, ':') === false) {
			return gmmktime(0, 0, 0, $number, $day, (int)$yearOrTime);
		}

		[$hour, $minute] = array_map('intval', explode(':', $yearOrTime));
		$year = (int)gmdate('Y', $now);
		$time = gmmktime($hour, $minute, 0, $number, $day, $year);
		if ($time > $now + 86400) {
			$time = gmmktime($hour, $minute, 0, $number, $day, $year - 1);
		}

		return $time;
	}//end unixListTime()

	/**
	 * One MS-DOS-layout LIST line (`11-14-26  07:25AM  1204 name` or `<DIR>`), or null.
	 *
	 * @param string $line The line.
	 *
	 * @return array{name: string, size: int|null, modifiedAt: int|null, isDirectory: bool}|null
	 */
	private static function dosListEntry(string $line): ?array {
		$pattern = '/^(\d{2})-(\d{2})-(\d{2,4})\s+(\d{1,2}):(\d{2})(AM|PM)?\s+(<DIR>|\d+)\s+(.+)$/i';
		if (preg_match($pattern, $line, $match) !== 1) {
			return null;
		}

		$year = (int)$match[3];
		if ($year < 100) {
			$year += 2000;
		}

		$hour     = (int)$match[4];
		$meridiem = strtoupper($match[6]);
		if ($meridiem !== '') {
			$hour = ($hour % 12) + (int)($meridiem === 'PM') * 12;
		}

		$isDirectory = strtoupper($match[7]) === '<DIR>';

		return [
			'name'        => $match[8],
			'size'        => $isDirectory === true ? null : (int)$match[7],
			'modifiedAt'  => gmmktime($hour, (int)$match[5], 0, (int)$match[1], (int)$match[2], $year),
			'isDirectory' => $isDirectory,
		];
	}//end dosListEntry()

	/**
	 * The exact modification time of a file (MDTM), or null when the server does not answer it.
	 *
	 * @param string $path The server path of the file.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	private function modifiedTimeOf(string $path): ?int {
		try {
			$time = $this->transfer(path: $path, options: [CURLOPT_NOBODY => true, CURLOPT_FILETIME => true, CURLOPT_RETURNTRANSFER => true])['fileTime'];
		} catch (RemoteFileException) {
			return null;
		}

		if ($time < 0) {
			return null;
		}

		return $time;
	}//end modifiedTimeOf()

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
		return $this->transfer(path: $path, options: $options)['result'];
	}//end request()

	/**
	 * One curl request against the server, TLS required and verified, with the file time
	 * curl read (MDTM) when CURLOPT_FILETIME was asked for.
	 *
	 * @param string            $path    The server path.
	 * @param array<int, mixed> $options Extra curl options.
	 *
	 * @return array{result: string|bool, fileTime: int} The transfer result; fileTime -1 when unknown.
	 *
	 * @throws RemoteFileException When the request fails; the message names the curl error, never the password.
	 */
	private function transfer(string $path, array $options): array {
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

		$result   = curl_exec($handle);
		$error    = curl_error($handle);
		$fileTime = (int)curl_getinfo($handle, CURLINFO_FILETIME);
		curl_close($handle);
		if ($result === false) {
			throw new RemoteFileException('The FTPS server ' . $this->server->host . ' refused the request for "' . $path . '": ' . $error);
		}

		return ['result' => $result, 'fileTime' => $fileTime];
	}//end transfer()
}//end class
