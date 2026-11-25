<?php

/**
 * Contract of the MappingLookup.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use RuntimeException;

/**
 * Public operations of {@see MappingLookup} that the mapping templates call.
 *
 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
 */
interface MappingLookupInterface {
	/**
	 * One field of the single object in an allowed schema that matches the filters.
	 *
	 * @param string              $schema  The schema, as `register/schema`.
	 * @param array<string,mixed> $filters Property filters; values must be scalar.
	 * @param string              $field   The field to return, a dot path.
	 * @param mixed               $default What none or several matches return.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException When the schema is not allowed, a filter is not scalar, or OpenRegister is missing.
	 *
	 * @spec openspec/changes/archive/2026-11-24-mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
	 */
	public function lookup(string $schema, array $filters, string $field, mixed $default = null): mixed;

	/**
	 * The field of every object in an allowed schema that matches the filters.
	 *
	 * @param string              $schema  The schema, as `register/schema`.
	 * @param array<string,mixed> $filters Property filters; values must be scalar.
	 * @param string              $field   The field to return per object, a dot path; an object without it contributes null.
	 * @param integer             $limit   The most objects answered.
	 *
	 * @return list<mixed>
	 *
	 * @throws RuntimeException When the schema is not allowed, a filter is not scalar, more objects match than the limit, or OpenRegister is missing.
	 *
	 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
	 */
	public function lookupAll(string $schema, array $filters, string $field, int $limit = 200): array;
}//end interface
