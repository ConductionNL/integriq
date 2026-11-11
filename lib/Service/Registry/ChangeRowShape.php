<?php

/**
 * Integriq ChangeRowShape.
 *
 * How one registry names the identity and the event reference in the rows of
 * its change feed, and the reading of those rows into SubscriptionChanges.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registry;

/**
 * Value object for a change feed's row shape (ADR-011).
 *
 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */
final class ChangeRowShape {

	/**
	 * Constructor.
	 *
	 * @param string $identityKey  The row key holding the identity (bsn, kvk number).
	 * @param string $referenceKey The row key holding the registry's own event id; `eventReference` is the fallback.
	 */
	public function __construct(
		public readonly string $identityKey,
		public readonly string $referenceKey,
	) {
	}//end __construct()

	/**
	 * The changes in a feed's rows, in order.
	 *
	 * A row that is not an array, has no identity or carries no changed
	 * properties is skipped.
	 *
	 * @param mixed $rows The feed's rows.
	 *
	 * @return list<SubscriptionChange>
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
	 */
	public function changesIn(mixed $rows): array {
		if (is_iterable($rows) === false) {
			return [];
		}

		$changes = [];
		foreach ($rows as $row) {
			$change = $this->changeOf(row: $row);
			if ($change !== null) {
				$changes[] = $change;
			}
		}

		return $changes;
	}//end changesIn()

	/**
	 * One row as a change, or null when it is skipped.
	 *
	 * @param mixed $row One feed row.
	 *
	 * @return SubscriptionChange|null
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
	 */
	private function changeOf(mixed $row): ?SubscriptionChange {
		if (is_array($row) === false) {
			return null;
		}

		$identity = (string)($row[$this->identityKey] ?? '');
		$properties = self::changedProperties(row: $row);
		if ($identity === '' || $properties === null) {
			return null;
		}

		return new SubscriptionChange($identity, $properties, (string)($row[$this->referenceKey] ?? $row['eventReference'] ?? ''));
	}//end changeOf()

	/**
	 * The row's changed properties, or null when it carries none.
	 *
	 * @param array<string, mixed> $row One feed row.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
	 */
	private static function changedProperties(array $row): ?array {
		$properties = ($row['gewijzigd'] ?? []);
		if (is_array($properties) === false || $properties === []) {
			return null;
		}

		return $properties;
	}//end changedProperties()
}//end class
