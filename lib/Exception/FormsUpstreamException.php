<?php

/**
 * Integriq Forms Upstream Exception.
 *
 * Raised when a call to the Nextcloud Forms API fails at the transport level
 * (network error) or returns a non-2xx/non-4xx status (5xx). Discovery-
 * endpoint callers map this to HTTP 502; the message carries a reference to
 * the underlying `CallLog`, never the raw upstream body (redaction is
 * already applied by `CallService`, but this exception's own message stays
 * terse regardless), mirroring `TablesUpstreamException` exactly.
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
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when the Forms API is unreachable or errors at the transport/5xx level.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
 */
class FormsUpstreamException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable, secret-free message.
	 */
	public function __construct(string $message) {
		parent::__construct(message: $message, code: 502);

	}//end __construct()
}//end class
