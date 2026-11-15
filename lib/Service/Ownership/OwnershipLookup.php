<?php

/**
 * Integriq OwnershipLookup.
 *
 * One lookup of an integriq object by a field: the schema, the field and the
 * value it must hold.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Ownership
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Ownership;

/**
 * Value object for one ownership lookup (ADR-011).
 *
 * @spec openspec/specs/source-owned-records/spec.md#requirement-a-record-maintained-from-a-source-says-who-owns-it-req-sor-001
 */
final class OwnershipLookup {

	/**
	 * Constructor.
	 *
	 * @param string $schema     The integriq schema slug.
	 * @param string $matchKey   The field filtered on and compared.
	 * @param string $matchValue The value it must hold.
	 */
	public function __construct(
		public readonly string $schema,
		public readonly string $matchKey,
		public readonly string $matchValue,
	) {
	}//end __construct()
}//end class
