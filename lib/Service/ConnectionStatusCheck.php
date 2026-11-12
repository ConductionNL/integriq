<?php

/**
 * Integriq ConnectionStatusCheck.
 *
 * One connection row while {@see ConnectionStatusResolver} applies the D4
 * rules to it: the stored row, its declaration entry read as an array, and
 * the time the check runs at. Built by the resolver for one resolve() call
 * and never handed out.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * The row the D4 rules are applied to.
 *
 * @spec openspec/specs/connection-registry/spec.md#requirement-the-resolver-applies-the-d4-rules-in-order-req-conn-003
 */
final class ConnectionStatusCheck {

	/**
	 * The row's declaration entry; an empty array when it is missing or not an array.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $declaration;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $row The stored row data.
	 * @param string $now The current time as ISO 8601.
	 */
	public function __construct(
		public readonly array $row,
		public readonly string $now,
	) {
		$declaration = $row['declaration'] ?? [];
		if (is_array($declaration) === false) {
			$declaration = [];
		}

		$this->declaration = $declaration;
	}//end __construct()
}//end class
