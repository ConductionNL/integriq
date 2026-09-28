<?php

/**
 * Integriq Roster Delivery Exception.
 *
 * A rostering delivery that could not reach planninq, carrying the contract's
 * error code (`unknown-source`, `fetch-failed`, `planninq-absent`,
 * `planninq-refused`) so a caller can report the failure precisely.
 *
 * @category Source
 * @package  OCA\Integriq\Sources\Roster
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
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Roster;

use RuntimeException;
use Throwable;

/**
 * A failed rostering delivery with its contract error code.
 *
 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */
class RosterDeliveryException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string         $errorCode The contract error code.
	 * @param string         $message   A readable reason.
	 * @param Throwable|null $previous  The cause, if any.
	 */
	public function __construct(
		private readonly string $errorCode,
		string $message,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 0, previous: $previous);
	}//end __construct()

	/**
	 * The contract error code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rostering-adapter-targets-planninq/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}//end getErrorCode()
}//end class
