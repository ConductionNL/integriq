<?php

/**
 * Integriq mail headers.
 *
 * Header reading shared by the `.eml` reader (EmlParser) and the `.msg`
 * reader (MsgParser, for the stored internet headers): unfolding a header
 * block into lower-cased names, and reading a `Date` header.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mail
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
 * @spec openspec/specs/mail-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mail;

use DateTimeImmutable;
use Throwable;

/**
 * Reads RFC 5322 header blocks.
 *
 * @spec openspec/specs/mail-intake/spec.md
 */
final class MailHeaders {

	/**
	 * Parse and unfold an LF-separated header block.
	 *
	 * Repeated headers keep the first occurrence, which is what a reader wants
	 * for `Received` and friends. Blank lines and lines without a colon are skipped.
	 *
	 * @param string $headerBlock The header block, lines separated by LF.
	 *
	 * @return array<string,string> Lower-cased header names to values.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public static function parse(string $headerBlock): array {
		$headers = [];
		$current = null;
		foreach (explode("\n", $headerBlock) as $line) {
			if ($line === '') {
				continue;
			}

			if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
				$headers[$current] .= ' ' . trim($line);
				continue;
			}

			$current = self::addHeader(headers: $headers, line: $line) ?? $current;
		}

		return $headers;

	}//end parse()

	/**
	 * Read a `Date` header into an ISO 8601 timestamp.
	 *
	 * @param string $header The header value.
	 *
	 * @return string|null The timestamp, or null when the header is absent or unreadable.
	 *
	 * @spec openspec/specs/mail-intake/spec.md
	 */
	public static function date(string $header): ?string {
		if (trim($header) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($header))->format('c');
		} catch (Throwable) {
			return null;
		}

	}//end date()

	/**
	 * Add one `Name: value` line, unless the name is already present.
	 *
	 * @param array<string,string> $headers The headers so far, by reference.
	 * @param string               $line    The header line.
	 *
	 * @return string|null The header name, or null when the line has no colon.
	 */
	private static function addHeader(array &$headers, string $line): ?string {
		$colon = strpos($line, ':');
		if ($colon === false) {
			return null;
		}

		$name = strtolower(trim(substr($line, 0, $colon)));
		if (isset($headers[$name]) === false) {
			$headers[$name] = trim(substr($line, ($colon + 1)));
		}

		return $name;

	}//end addHeader()
}//end class
