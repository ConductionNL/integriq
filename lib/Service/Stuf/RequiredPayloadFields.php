<?php

/**
 * Integriq required payload fields.
 *
 * Finds the first required field of an export payload that is missing,
 * null, or a blank string. Each export envelope translator raises its own
 * refusal naming that field.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Stuf
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
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Stuf;

/**
 * Checks an export payload for its required fields.
 *
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
 */
final class RequiredPayloadFields {

	/**
	 * The first required field that is missing, null or a blank string.
	 *
	 * @param array<int, string>   $fields  The required field names, in check order.
	 * @param array<string, mixed> $payload The payload to check.
	 *
	 * @return string|null The first missing field, or null when all are present.
	 *
	 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-004-basispoort-sync-translation-with-sso-hand-off
	 */
	public static function firstMissing(array $fields, array $payload): ?string {
		foreach ($fields as $field) {
			$value = ($payload[$field] ?? null);
			$isEmptyString = (is_string($value) === true && trim($value) === '');
			if ($value === null || $isEmptyString === true) {
				return $field;
			}
		}

		return null;

	}//end firstMissing()
}//end class
