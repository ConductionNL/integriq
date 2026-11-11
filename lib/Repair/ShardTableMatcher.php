<?php

/**
 * Integriq ShardTableMatcher: which listed tables are a shard table of the
 * given registers.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

/**
 * Matches table names against the `openregister_table_{register}_` markers
 * of a set of registers; built by {@see RenameDutchColumns}.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
final class ShardTableMatcher {
	/**
	 * The `openregister_table_{register}_` markers, one per register.
	 *
	 * @var array<int|string, string>
	 */
	private array $markers;

	/**
	 * Constructor.
	 *
	 * @param array<int, mixed> $registerIds The register ids.
	 */
	public function __construct(array $registerIds) {
		$this->markers = array_map(static fn ($id): string => 'openregister_table_' . ((int)$id) . '_', $registerIds);

	}//end __construct()

	/**
	 * The listed tables that are a shard of one of the registers, once each,
	 * in listing order.
	 *
	 * @param array<int, string> $names The listed table names.
	 *
	 * @return array<int, string>
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function tablesIn(array $names): array {
		$tables = [];
		foreach ($names as $name) {
			if ($this->isShard(name: $name) === true) {
				$tables[] = $name;
			}
		}

		return array_values(array_unique($tables));

	}//end tablesIn()

	/**
	 * Whether a table name is a register marker followed by a schema id only.
	 *
	 * @param string $name The table name.
	 *
	 * @return bool
	 */
	private function isShard(string $name): bool {
		foreach ($this->markers as $marker) {
			$offset = strpos($name, $marker);
			if ($offset !== false && ctype_digit(substr($name, ($offset + strlen($marker)))) === true) {
				return true;
			}
		}

		return false;

	}//end isShard()
}//end class
