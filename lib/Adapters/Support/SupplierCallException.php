<?php

/**
 * Integriq supplier call exception.
 *
 * Raised when a live supplier client cannot complete its call: the supplier
 * source is missing, the call failed, the supplier answered an error status
 * (a refused credential among them), or the answer had no usable body.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Support
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
 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Support;

use RuntimeException;
use Throwable;

/**
 * A failed supplier call, carrying the HTTP status when there was one.
 *
 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
 */
class SupplierCallException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string         $message  What went wrong, naming the supplier source and endpoint.
	 * @param int            $status   The HTTP status the supplier answered, or 0 when there was none.
	 * @param Throwable|null $previous The underlying error, if any.
	 */
	public function __construct(string $message, private readonly int $status = 0, ?Throwable $previous = null) {
		parent::__construct(message: $message, code: $status, previous: $previous);
	}//end __construct()

	/**
	 * The HTTP status the supplier answered, or 0 when there was none.
	 *
	 * @return int HTTP status.
	 *
	 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * Whether the supplier refused the credential (401 or 403).
	 *
	 * @return bool True for a refused credential.
	 *
	 * @spec openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004
	 */
	public function isRefusedCredential(): bool {
		return $this->status === 401 || $this->status === 403;
	}//end isRefusedCredential()
}//end class
