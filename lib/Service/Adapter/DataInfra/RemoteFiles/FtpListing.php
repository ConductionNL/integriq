<?php

/**
 * A FTP LIST answer read into folder entries, for servers without MLSD.
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
 * LIST has no standard layout (RFC 959 leaves it to the server). This reads the two that
 * servers send in practice: the Unix `ls -l` layout and the MS-DOS layout of IIS.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
 */
final class FtpListing {

	/**
	 * Parse a LIST answer into entries: the Unix `ls -l` layout most servers send, and the
	 * MS-DOS layout of IIS. LIST times have minutes at best, so FtpsSession::listDirectory() asks MDTM for
	 * the exact time of each file.
	 *
	 * @param string $answer The raw LIST lines.
	 * @param int    $now    The current Unix time; a Unix line without a year is in the last twelve months.
	 *
	 * @return list<array{name: string, size: int|null, modifiedAt: int|null, isDirectory: bool}>
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001
	 */
	public function parse(string $answer, int $now): array {
		$entries = [];
		foreach (explode("\n", str_replace("\r\n", "\n", $answer)) as $line) {
			$entry = self::unixListEntry(line: $line, now: $now) ?? self::dosListEntry(line: $line);
			if ($entry === null || $entry['name'] === '.' || $entry['name'] === '..') {
				continue;
			}

			$entries[] = $entry;
		}

		return $entries;
	}//end parse()

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
		$index = array_search(strtolower($month), ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true);
		if ($index === false) {
			return null;
		}

		$number = $index + 1;

		if (str_contains($yearOrTime, ':') === false) {
			return self::utcTime(hour: 0, minute: 0, month: $number, day: $day, year: (int)$yearOrTime);
		}

		[$hour, $minute] = array_map('intval', explode(':', $yearOrTime));
		$year = (int)gmdate('Y', $now);
		$time = self::utcTime(hour: $hour, minute: $minute, month: $number, day: $day, year: $year);
		if ($time !== null && $time > $now + 86400) {
			$time = self::utcTime(hour: $hour, minute: $minute, month: $number, day: $day, year: $year - 1);
		}

		return $time;
	}//end unixListTime()

	/**
	 * A UTC time to the minute, or null when it cannot be made.
	 *
	 * @param int $hour   The hour.
	 * @param int $minute The minute.
	 * @param int $month  The month.
	 * @param int $day    The day of the month.
	 * @param int $year   The year.
	 *
	 * @return int|null
	 */
	private static function utcTime(int $hour, int $minute, int $month, int $day, int $year): ?int {
		$time = gmmktime($hour, $minute, 0, $month, $day, $year);
		if ($time === false) {
			return null;
		}

		return $time;
	}//end utcTime()

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
		$size        = null;
		if ($isDirectory === false) {
			$size = (int)$match[7];
		}

		return [
			'name'        => $match[8],
			'size'        => $size,
			'modifiedAt'  => self::utcTime(hour: $hour, minute: (int)$match[5], month: (int)$match[1], day: (int)$match[2], year: $year),
			'isDirectory' => $isDirectory,
		];
	}//end dosListEntry()
}//end class
