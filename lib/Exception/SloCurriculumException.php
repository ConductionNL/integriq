<?php

/**
 * Integriq SLO curriculum exception.
 *
 * Raised when an SLO curriculum request or response cannot become an import:
 * an error status from SLO, a body that does not parse, a guard limit, or a
 * request the recorded fixture has no answer for.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
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
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use RuntimeException;
use Throwable;

/**
 * A failed SLO curriculum read, carrying the HTTP status when there was one.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
 */
class SloCurriculumException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message What went wrong, naming the request.
	 * @param int $status The HTTP status SLO answered, or 0 when there was none.
	 * @param Throwable|null $previous The underlying error, if any.
	 */
	public function __construct(string $message, private readonly int $status = 0, ?Throwable $previous = null) {
		parent::__construct(message: $message, code: $status, previous: $previous);
	}//end __construct()

	/**
	 * The HTTP status SLO answered, or 0 for a parse, guard or fixture failure.
	 *
	 * @return int HTTP status.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
