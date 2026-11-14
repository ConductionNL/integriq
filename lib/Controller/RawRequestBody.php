<?php

/**
 * RawRequestBody
 *
 * Reads the exact request body bytes a signed inbound route verifies its
 * signature over. `$this->request->getParams()` is decoded and normalised, so
 * it would desync from the signed payload for non-form content types.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

/**
 * The raw body of the current request.
 *
 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
 */
final class RawRequestBody {

	/**
	 * Read the raw request body bytes.
	 *
	 * @return string The raw request body, or '' when it cannot be read.
	 *
	 * @spec openspec/changes/dso-stam-pkioverheid-signature-verification/tasks.md#task-3
	 */
	public function read(): string {
		$content = file_get_contents(filename: 'php://input');
		if ($content === false) {
			return '';
		}

		return $content;

	}//end read()
}//end class
