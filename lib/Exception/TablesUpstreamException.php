<?php

/**
 * OpenConnector Tables Upstream Exception.
 *
 * Raised when a call to the Nextcloud Tables API fails at the transport
 * level (network error) or returns a non-2xx/non-4xx status (5xx). Per
 * contract.md, discovery-endpoint callers map this to HTTP 502; the message
 * carries a reference to the underlying `CallLog`, never the raw upstream
 * body (redaction is already applied by `CallService`, but this exception's
 * own message stays terse regardless).
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
 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Exception;

use Exception;

/**
 * Thrown when the Tables API is unreachable or errors at the transport/5xx level.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
 */
class TablesUpstreamException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable, secret-free message.
	 */
	public function __construct(string $message) {
		parent::__construct(message: $message, code: 502);

	}//end __construct()
}//end class
