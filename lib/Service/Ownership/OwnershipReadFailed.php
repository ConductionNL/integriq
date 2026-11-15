<?php

/**
 * A failed read while working out who owns a record.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Ownership
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

namespace OCA\Integriq\Service\Ownership;

use RuntimeException;
use Throwable;

/**
 * Thrown by the strict ownership read; says whether a contract was already found.
 *
 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
 */
class OwnershipReadFailed extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string         $message       What failed.
	 * @param bool           $contractFound Whether a synchronisation contract for the record was read before the failure.
	 * @param Throwable|null $previous      The underlying failure.
	 */
	public function __construct(
		string $message,
		public readonly bool $contractFound,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 0, previous: $previous);
	}//end __construct()
}//end class
