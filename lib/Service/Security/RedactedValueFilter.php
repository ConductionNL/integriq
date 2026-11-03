<?php

/**
 * Leaves the values an export masked out of imported data.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Security;

/**
 * Removes every SensitiveFieldRegistry::PLACEHOLDER value, and every array that
 * held nothing but placeholders, at any depth. A list keeps its other items in order.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */
final class RedactedValueFilter {

	/**
	 * The data without masked values.
	 *
	 * @param array $data The data.
	 *
	 * @return array The filtered copy.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function filter(array $data): array {
		$isList = array_is_list($data);
		foreach ($data as $key => $value) {
			if ($this->isMasked(value: $value) === true) {
				unset($data[$key]);
				continue;
			}

			if (is_array($value) === true) {
				$data[$key] = $this->filter(data: $value);
			}
		}

		if ($isList === true) {
			return array_values($data);
		}

		return $data;
	}//end filter()

	/**
	 * Whether a value is the placeholder, or a non-empty array of only masked values.
	 *
	 * @param mixed $value The value.
	 *
	 * @return boolean
	 */
	private function isMasked(mixed $value): bool {
		if ($value === SensitiveFieldRegistry::PLACEHOLDER) {
			return true;
		}

		if (is_array($value) === false || $value === []) {
			return false;
		}

		foreach ($value as $inner) {
			if ($this->isMasked(value: $inner) === false) {
				return false;
			}
		}

		return true;
	}//end isMasked()
}//end class
