<?php

/**
 * Integriq Response Decode Exception.
 *
 * Raised by {@see \OCA\Integriq\Service\ResponseDecoder} when a response body
 * cannot be read in the mode a caller asked for: YAML that does not parse, a
 * base64 payload that is not base64, a body that is empty or too large.
 *
 * It exists so a body nobody could read is never mistaken for an empty one.
 * A decoder that returned `[]` here would let a flow map nothing, write
 * nothing and report the run as a success.
 *
 * Messages carry the mode and the parser's reason, never the body: a body can
 * hold anything the upstream chose to send.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-file-that-does-not-decode-fails-its-item-req-ghp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use RuntimeException;
use Throwable;

/**
 * Signals that a response body could not be decoded in the requested mode.
 *
 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-file-that-does-not-decode-fails-its-item-req-ghp-003
 */
class ResponseDecodeException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string         $mode     The decode mode that was asked for.
	 * @param string         $reason   Why the body could not be decoded.
	 * @param Throwable|null $previous The parser's own exception, when there is one.
	 */
	public function __construct(
		private readonly string $mode,
		private readonly string $reason,
		?Throwable $previous = null,
	) {
		parent::__construct(
			message: sprintf('The response could not be decoded as %s: %s', $mode, $reason),
			previous: $previous
		);

	}//end __construct()

	/**
	 * The decode mode that was asked for.
	 *
	 * @return string The mode.
	 *
	 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-file-that-does-not-decode-fails-its-item-req-ghp-003
	 */
	public function getMode(): string {
		return $this->mode;
	}//end getMode()

	/**
	 * Why the body could not be decoded.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/changes/sources-github-publiccode/specs/github-publiccode-source/spec.md#requirement-a-file-that-does-not-decode-fails-its-item-req-ghp-003
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class
