<?php

/**
 * Integriq — a Berichtenbox call that did not go through.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Berichtenbox
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

namespace OCA\Integriq\Adapters\Berichtenbox;

use RuntimeException;
use Throwable;

/**
 * A Berichtenbox call that did not go through, with a stable code the sending app can act on.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-live-binding-speaks-the-interface-logius-publishes-req-dpa-008
 */
class BerichtenboxException extends RuntimeException {
	/**
	 * The letter breaks a rule of the official schema or of the aansluithandleiding.
	 */
	public const CODE_INVALID_LETTER = 'invalid_letter';

	/**
	 * Logius answered the subscription check with a fault.
	 */
	public const CODE_SUBSCRIPTION_FAULT = 'subscription_check_failed';

	/**
	 * The ebMS adapter did not take the batch.
	 */
	public const CODE_TRANSPORT = 'transport_failed';

	/**
	 * The source lacks a value or a usable certificate.
	 */
	public const CODE_NOT_CONFIGURED = 'not_configured';

	/**
	 * Constructor.
	 *
	 * @param string $message The reason, in words an operator reads.
	 * @param string $reason One of the CODE_* constants.
	 * @param Throwable|null $previous The cause.
	 */
	public function __construct(string $message, private readonly string $reason, ?Throwable $previous = null) {
		parent::__construct(message: $message, code: 0, previous: $previous);
	}//end __construct()

	/**
	 * The stable code.
	 *
	 * @return string One of the CODE_* constants.
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class
