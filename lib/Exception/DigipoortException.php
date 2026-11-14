<?php

/**
 * Integriq Digipoort Exception.
 *
 * Raised when a Digipoort delivery or status request cannot be made or is
 * refused. `retryable` says whether trying the same delivery again later can
 * succeed (an unreachable channel, a missing certificate that an
 * administrator can still configure) or cannot (a filing type the source has
 * no berichtsoort for, an unknown recipient).
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
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;
use Throwable;

/**
 * A Digipoort delivery or status request that failed or was refused.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `retryable` is a fact about the failure, not a mode of the constructor.
 *
 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001
 */
class DigipoortException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param string         $message   What failed, without secrets.
	 * @param boolean        $retryable Whether the same delivery can succeed when tried again later.
	 * @param Throwable|null $previous  The cause.
	 *
	 * @spec openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001
	 */
	public function __construct(
		string $message,
		public readonly bool $retryable = true,
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, previous: $previous);
	}//end __construct()
}//end class
