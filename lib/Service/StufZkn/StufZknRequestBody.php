<?php

/**
 * Integriq StUF-ZKN request body.
 *
 * The one ceiling and the one bounded read for the public StUF endpoints (the
 * provider routes and the ZDS confirmation route). Both parse the body as XML
 * before they know who sent it, so the body must be bounded while it is READ,
 * not after it is all in memory.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

/**
 * Bounded read of a public StUF request body.
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
 */
final class StufZknRequestBody {

	/**
	 * Inbound body ceiling (2 MiB): mitigates XML bombs and memory exhaustion.
	 *
	 * @var integer
	 */
	public const MAX_BYTES = 2097152;

	/**
	 * Read a request body, at most one byte past the ceiling.
	 *
	 * A result longer than {@see MAX_BYTES} means the body is too large; the
	 * rest of the stream is left unread, so an oversized body never reaches
	 * memory in full.
	 *
	 * @param string $stream The stream to read, `php://input` for the request body.
	 *
	 * @return string The body, at most MAX_BYTES + 1 bytes; '' when there is none.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
	 */
	public function read(string $stream = 'php://input'): string {
		// A missing stream is answered with '' below; keep fopen()'s warning out of the log.
		set_error_handler(static fn (): bool => true);
		try {
			$handle = fopen($stream, 'rb');
		} finally {
			restore_error_handler();
		}

		if ($handle === false) {
			return '';
		}

		$body = stream_get_contents($handle, (self::MAX_BYTES + 1));
		fclose($handle);

		if ($body === false) {
			return '';
		}

		return $body;
	}//end read()
}//end class
