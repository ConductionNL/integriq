<?php

/**
 * Integriq PDOK Upstream Exception
 *
 * Thrown by PdokConnector when the upstream PDOK Locatieserver returns a
 * non-success status that cannot be recovered locally (5xx, decode failure,
 * or exhausted 429 retries).
 *
 * @category Connector
 * @package  OCA\Integriq\Connectors
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Connectors;

use RuntimeException;

/**
 * Exception carrying the upstream HTTP status code.
 */
class PdokUpstreamException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable message.
	 * @param int $statusCode Upstream HTTP status code (or 503 on transport failure).
	 */
	public function __construct(
		string $message,
		private readonly int $statusCode,
	) {
		parent::__construct(message: $message);

	}//end __construct()

	/**
	 * Get the upstream HTTP status code attached to this exception.
	 *
	 * @return int HTTP status code.
	 */
	public function getStatusCode(): int {
		return $this->statusCode;
	}//end getStatusCode()
}//end class
