<?php

/**
 * Integriq FieldResolution.
 *
 * The contract field one mapping expression is resolved for, and the
 * submission it reads from.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenFormulieren
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenFormulieren;

/**
 * Value object for one field resolution (ADR-011).
 *
 * @spec openspec/specs/open-formulieren-intake/spec.md#requirement-per-form-mapping-onto-ns-case-contract-fields-req-002
 */
final class FieldResolution {

	/**
	 * Constructor.
	 *
	 * @param string               $field           The contract field (title, summary, channel, priority).
	 * @param array<string, mixed> $submittedValues The submission's values.
	 */
	public function __construct(
		public readonly string $field,
		public readonly array $submittedValues,
	) {
	}//end __construct()
}//end class
