<?php

/**
 * Reads the members of a `.zip` archive a source answered with.
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

use RuntimeException;
use ZipArchive;

/**
 * Unpacks a zip archive held in memory into its file members, in archive order.
 *
 * A bulk source that publishes one file per day inside a single archive
 * (a month of Atom feeds, a year of XML notices) cannot be read by the gzip
 * path: gzip holds exactly one stream, zip holds many. Each member comes back
 * as its own body, so the page reader parses every member the way it parses a
 * plain response.
 *
 * Bounded on purpose. The archive comes from an arbitrary configured source,
 * so the member count and the total unpacked size are capped, and directory
 * entries, macOS resource forks and members outside the configured name
 * pattern are skipped rather than parsed.
 *
 * @spec openspec/changes/native-data-gathering-provider/tasks.md
 */
class ZipArchiveMembers {

	/**
	 * The most members read from one archive.
	 */
	public const DEFAULT_MAX_MEMBERS = 1000;

	/**
	 * The most bytes all members together may unpack to (256 MiB).
	 */
	public const DEFAULT_MAX_TOTAL_BYTES = 268435456;

	/**
	 * Constructor.
	 *
	 * @param int $maxMembers    The most members read from one archive.
	 * @param int $maxTotalBytes The most bytes all members may unpack to.
	 */
	public function __construct(
		private readonly int $maxMembers = self::DEFAULT_MAX_MEMBERS,
		private readonly int $maxTotalBytes = self::DEFAULT_MAX_TOTAL_BYTES,
	) {
	}//end __construct()

	/**
	 * Whether bytes start with the zip local-file-header signature.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return bool True when the bytes look like a zip archive.
	 *
	 * @spec openspec/changes/native-data-gathering-provider/tasks.md
	 */
	public function looksLikeZip(string $bytes): bool {
		return str_starts_with($bytes, "PK\x03\x04") === true;
	}//end looksLikeZip()

	/**
	 * The file members of an archive, in archive order.
	 *
	 * @param string      $bytes   The archive.
	 * @param string|null $pattern A shell pattern a member name must match (fnmatch), or null for every member.
	 *
	 * @return list<array{name: string, body: string}> The members.
	 *
	 * @throws RuntimeException When the archive cannot be opened or a limit is exceeded.
	 *
	 * @spec openspec/changes/native-data-gathering-provider/tasks.md
	 */
	public function read(string $bytes, ?string $pattern=null): array {
		if (class_exists(ZipArchive::class) === false) {
			throw new RuntimeException('the zip extension is not installed');
		}

		$path = tempnam(sys_get_temp_dir(), 'integriq-zip-');
		if ($path === false || file_put_contents($path, $bytes) === false) {
			throw new RuntimeException('the archive could not be written to a temporary file');
		}

		try {
			return $this->readFile(path: $path, pattern: $pattern);
		} finally {
			unlink($path);
		}
	}//end read()

	/**
	 * The file members of an archive on disk.
	 *
	 * @param string      $path    The archive's path.
	 * @param string|null $pattern A shell pattern a member name must match, or null.
	 *
	 * @return list<array{name: string, body: string}> The members.
	 *
	 * @throws RuntimeException When the archive cannot be opened or a limit is exceeded.
	 */
	private function readFile(string $path, ?string $pattern): array {
		$zip = new ZipArchive();
		$opened = $zip->open($path, ZipArchive::RDONLY);
		if ($opened !== true) {
			throw new RuntimeException('the archive could not be opened (zip error '.(string)$opened.')');
		}

		try {
			$members = [];
			$total = 0;
			for ($index = 0; $index < $zip->numFiles; $index++) {
				$stat = $zip->statIndex($index);
				if ($stat === false || $this->isReadable(name: (string)$stat['name'], pattern: $pattern) === false) {
					continue;
				}

				if (count($members) >= $this->maxMembers) {
					throw new RuntimeException('the archive holds more than '.$this->maxMembers.' members');
				}

				// The declared size is the archive's own claim, so the read is
				// capped at what is left of the budget and the bytes actually
				// unpacked are what is counted.
				$body = $zip->getFromIndex($index, ($this->maxTotalBytes - $total + 1));
				if ($body === false) {
					throw new RuntimeException('member '.$stat['name'].' could not be read');
				}

				$total += strlen($body);
				if ($total > $this->maxTotalBytes) {
					throw new RuntimeException('the archive unpacks to more than '.$this->maxTotalBytes.' bytes');
				}

				$members[] = ['name' => (string)$stat['name'], 'body' => $body];
			}

			return $members;
		} finally {
			$zip->close();
		}
	}//end readFile()

	/**
	 * Whether a member is a file worth parsing.
	 *
	 * @param string      $name    The member's name in the archive.
	 * @param string|null $pattern A shell pattern its base name must match, or null.
	 *
	 * @return bool True for a file member that matches the pattern.
	 */
	private function isReadable(string $name, ?string $pattern): bool {
		if (str_ends_with($name, '/') === true || str_starts_with($name, '__MACOSX/') === true) {
			return false;
		}

		$baseName = basename($name);
		if (str_starts_with($baseName, '._') === true) {
			return false;
		}

		if ($pattern === null || $pattern === '') {
			return true;
		}

		return fnmatch($pattern, $baseName) === true || fnmatch($pattern, $name) === true;
	}//end isReadable()
}//end class
