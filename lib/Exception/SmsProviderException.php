<?php

/**
 * Integriq SMS Provider Exception.
 *
 * Raised when an SMS channel provider operation cannot proceed: an invalid
 * E.164 recipient, an unreachable/erroring gateway, a missing/undecryptable
 * credential, or a provider configuration error. Messages MUST stay
 * secret-free — they may name configuration keys and references, never key
 * material (mirrors PeppolProviderException / ADR-007).
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;
use Throwable;

/**
 * Thrown on any SMS channel provider or configuration failure.
 *
 * @spec openspec/specs/notifynl-sms-channel/spec.md
 */
class SmsProviderException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string $message What went wrong.
	 * @param string $errorCode A machine-readable code, for example `opted-out` when the
	 *                          opt-out list refused the send (opt-out-before-send).
	 * @param int $code The exception code.
	 * @param Throwable|null $previous The cause.
	 */
	public function __construct(
		string $message = '',
		private readonly string $errorCode = '',
		int $code = 0,
		?Throwable $previous = null,
	) {
		parent::__construct($message, $code, $previous);

	}//end __construct()

	/**
	 * The machine-readable code, or empty.
	 *
	 * @return string The code.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-every-integriq-sender-asks-the-opt-out-list-before-it-sends-req-ooa-001
	 */
	public function getErrorCode(): string {
		return $this->errorCode;

	}//end getErrorCode()

}//end class
