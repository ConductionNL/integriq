<?php

/**
 * Keyed registry of the migration source adapters this instance ships.
 *
 * @category Registry
 * @package  OCA\Integriq\Migration
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

namespace OCA\Integriq\Migration;

/**
 * A second incumbent is a registration here plus an adapter, and nothing else.
 * No engine change, no consuming app change.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#scenario-a-second-incumbent-needs-no-engine-change
 */
class MigrationSourceRegistry {
	/**
	 * Adapters keyed by source id.
	 *
	 * @var array<string,MigrationSourceAdapterInterface>
	 */
	private array $adapters = [];

	/**
	 * Constructor.
	 *
	 * @param iterable<MigrationSourceAdapterInterface> $adapters The adapters.
	 */
	public function __construct(iterable $adapters = []) {
		foreach ($adapters as $adapter) {
			$this->register($adapter);
		}
	}//end __construct()

	/**
	 * Register one adapter. The first registration under an id wins.
	 *
	 * @param MigrationSourceAdapterInterface $adapter The adapter.
	 *
	 * @return bool True when this adapter took the id.
	 */
	public function register(MigrationSourceAdapterInterface $adapter): bool {
		if (isset($this->adapters[$adapter->id()]) === true) {
			return false;
		}

		$this->adapters[$adapter->id()] = $adapter;

		return true;
	}//end register()

	/**
	 * The adapter registered under a source id.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return MigrationSourceAdapterInterface The adapter.
	 *
	 * @throws UnknownMigrationSourceException When nothing answers to the id.
	 */
	public function get(string $sourceId): MigrationSourceAdapterInterface {
		if (isset($this->adapters[$sourceId]) === false) {
			throw new UnknownMigrationSourceException($sourceId, $this->ids());
		}

		return $this->adapters[$sourceId];
	}//end get()

	/**
	 * Every registered source id.
	 *
	 * @return array<int,string> Source ids.
	 */
	public function ids(): array {
		return array_keys($this->adapters);
	}//end ids()

	/**
	 * What every registered adapter says it can yield.
	 *
	 * @return array<int,array<string,mixed>> Adapter descriptions.
	 */
	public function describeAll(): array {
		return array_values(
			array_map(static fn (MigrationSourceAdapterInterface $a): array => $a->describe(), $this->adapters)
		);
	}//end describeAll()
}//end class
