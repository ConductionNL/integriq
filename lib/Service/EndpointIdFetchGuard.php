<?php

/**
 * The declarative id-fetch guard for a single-object GET (REQ-EP-010).
 *
 * An endpoint's collection path can be narrowed by the filters its
 * `inputMapping` injects, but the single-object path fetched the object by id
 * with no filter at all. So `/motions/{id}` answered an amendment, and a
 * lifecycle-gated resource answered a draft, to anyone who knew the uuid. An
 * endpoint that declares `fixedFilters` now has the fetched object checked
 * against them, on the object's own fields, and a mismatch is answered as not
 * found.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-declarative-id-fetch-guard-for-single-object-get-req-ep-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Decides whether a fetched object passes an endpoint's fixed filters.
 *
 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-declarative-id-fetch-guard-for-single-object-get-req-ep-010
 */
class EndpointIdFetchGuard {

	/**
	 * Whether the object passes every fixed filter.
	 *
	 * A filter's value is one value, or a list of values any of which passes.
	 * The object's OWN field is read, never a request parameter, so a caller
	 * cannot talk the guard round.
	 *
	 * 🔴 A MISSING FIELD DOES NOT PASS. The collection path answers a filter
	 * `lifecycle=published` without the objects that carry no lifecycle, so the
	 * single-object path must too; passing them would reopen the gap for every
	 * object stored before the field existed.
	 *
	 * No filters admits everything, which is every endpoint that declares none.
	 *
	 * @param array<string, mixed> $object       The fetched object, serialised.
	 * @param array<string, mixed> $fixedFilters The endpoint's fixed filters.
	 *
	 * @return bool True when the object may be answered.
	 *
	 * @spec openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-declarative-id-fetch-guard-for-single-object-get-req-ep-010
	 */
	public function admits(array $object, array $fixedFilters): bool {
		foreach ($fixedFilters as $field => $expected) {
			if (array_key_exists((string)$field, $object) === false) {
				return false;
			}

			$allowed = array_map(fn (mixed $value): string => $this->normalise(value: $value), (array)$expected);
			if (in_array($this->normalise(value: $object[$field]), $allowed, true) === false) {
				return false;
			}
		}

		return true;
	}//end admits()

	/**
	 * One value as the string a filter compares.
	 *
	 * A list or an object never equals a filter value, so it normalises to a
	 * string no filter value can take.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The comparable form.
	 */
	private function normalise(mixed $value): string {
		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return "\0not-a-scalar";
	}//end normalise()
}//end class
