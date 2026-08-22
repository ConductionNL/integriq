<?php

/**
 * Integriq Forms Not-Found Exception.
 *
 * Raised when the configured `Source`, form id, or submission id referenced
 * by a `nextcloud-form` operation does not exist (either not found locally,
 * or the Forms API itself returned a 404). Discovery-endpoint callers map
 * this to HTTP 404, mirroring `TablesNotFoundException` exactly.
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
 * Thrown when a Source, form, or submission referenced by a Forms operation is not found.
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005
 */
class FormsNotFoundException extends Exception {
	/**
	 * Constructor.
	 *
	 * @param string $message Human-readable, secret-free message.
	 */
	public function __construct(string $message) {
		parent::__construct(message: $message, code: 404);

	}//end __construct()
}//end class
