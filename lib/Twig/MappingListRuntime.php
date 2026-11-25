<?php

/**
 * Integriq MappingListRuntime.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Backs the `lookupAll()` mapping function: a question about a set of register
 * rows ("has this employee any open assignment"), answered behind the same
 * administered allowlist as `lookup()`. Kept apart from MappingRuntime so each
 * runtime stays one concern.
 *
 * @category Twig
 * @package  OCA\Integriq\Twig
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Twig;

use OCA\Integriq\Service\MappingLookupInterface;
use RuntimeException;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The list lookup a mapping template calls as `lookupAll()`.
 *
 * @spec openspec/specs/mapping-and-search/spec.md
 */
class MappingListRuntime implements RuntimeExtensionInterface {

	/**
	 * Constructor.
	 *
	 * @param MappingLookupInterface|null $lookup The allowlisted register lookup; null refuses every lookup.
	 *
	 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
	 */
	public function __construct(
		private readonly ?MappingLookupInterface $lookup = null,
	) {
	}//end __construct()

	/**
	 * The field of every object in an allowed register schema matching the filters.
	 *
	 * `{{ lookupAll('humaniq/OrgAssignment', {'employeeId': id}, 'endDate') }}`.
	 * Without the lookup service every call is refused, never answered from an
	 * unguarded read.
	 *
	 * @param string              $schema  The schema, as `register/schema`.
	 * @param array<string,mixed> $filters Property filters.
	 * @param string              $field   The field to return per object; an object without it contributes null.
	 * @param integer             $limit   The most objects answered.
	 *
	 * @return list<mixed>
	 *
	 * @throws RuntimeException When lookups are not available here, or the lookup refuses.
	 *
	 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
	 */
	public function lookupAll(string $schema, array $filters, string $field, int $limit = 200): array {
		if ($this->lookup === null) {
			throw new RuntimeException(message: sprintf('lookupAll() may not read "%s": lookups are not available here.', $schema));
		}

		return $this->lookup->lookupAll(schema: $schema, filters: $filters, field: $field, limit: $limit);
	}//end lookupAll()
}//end class
