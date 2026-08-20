<?php

/**
 * OpenConnector Approval State Exception.
 *
 * Raised when an `approval_request` cannot be acted on in its current state:
 * not found, not `pending`, or already past its `expiresAt`. Carries the HTTP
 * status the controller should map the failure to (404/409), per
 * openspec/changes/hitl-approval-rule-action/design.md API Design tables.
 *
 * @category Exception
 * @package  OCA\OpenConnector\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;
use Throwable;

/**
 * Thrown when an approval_request is not actionable in its current state.
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */
class ApprovalStateException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable reason.
	 * @param integer $httpStatus The HTTP status the controller should return (404 or 409).
	 * @param Throwable|null $previous Previous exception, if any.
	 */
	public function __construct(
		string $message,
		private readonly int $httpStatus = 409,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 0, previous: $previous);

	}//end __construct()

	/**
	 * The HTTP status the controller should map this failure to.
	 *
	 * @return integer
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	public function getHttpStatus(): int {
		return $this->httpStatus;
	}//end getHttpStatus()
}//end class
