<?php

/**
 * The state of one legacy entity migration: what it reads and what it has counted.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Migration
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

namespace OCA\Integriq\Service\Migration;

/**
 * The entity being migrated, its batch size and row count, and the tallies
 * the batches add to.
 *
 * @spec openspec/specs/openconnector-storage-migration/spec.md
 */
final class EntityMigrationRun {

	/**
	 * The entity's schema slug.
	 *
	 * @var string
	 */
	public readonly string $slug;

	/**
	 * The legacy table without the oc_ prefix.
	 *
	 * @var string
	 */
	public readonly string $tableShort;

	/**
	 * Whether synchronization sourceId/targetId branching applies.
	 *
	 * @var boolean
	 */
	public readonly bool $hasSyncBranching;

	/**
	 * Rows read per batch.
	 *
	 * @var integer
	 */
	public int $batchSize = 0;

	/**
	 * Legacy rows counted before the run.
	 *
	 * @var integer
	 */
	public int $count = 0;

	/**
	 * Rows inserted.
	 *
	 * @var integer
	 */
	public int $migrated = 0;

	/**
	 * Rows skipped: no uuid, or a failed insert.
	 *
	 * @var integer
	 */
	public int $skipped = 0;

	/**
	 * Per-variant counts of the synchronization id branching.
	 *
	 * @var array<string, int>
	 */
	public array $syncBranchCounts = ['integer-pk' => 0, 'register-schema' => 0, 'uuid' => 0, 'unrecognised' => 0];

	/**
	 * Start the run of one entity.
	 *
	 * @param array{slug:string,legacyTable:string,hasSyncBranching?:bool} $entity The entity descriptor.
	 * @param integer $schemaPk The OR schema PK the entity's rows are written under.
	 */
	public function __construct(array $entity, public readonly int $schemaPk) {
		$this->slug = $entity['slug'];
		$this->tableShort = str_replace('oc_', '', $entity['legacyTable']);
		$this->hasSyncBranching = (bool)($entity['hasSyncBranching'] ?? false);
	}//end __construct()

	/**
	 * The run's result row.
	 *
	 * @return array{slug:string,legacyCount:int,migratedCount:int,skipped:int,fkRewrites:int,branching:array<string,int>|null}
	 *
	 * @spec openspec/specs/openconnector-storage-migration/spec.md
	 */
	public function result(): array {
		$branching = null;
		if ($this->hasSyncBranching === true) {
			$branching = $this->syncBranchCounts;
		}

		return [
			'slug' => $this->slug,
			'legacyCount' => $this->count,
			'migratedCount' => $this->migrated,
			'skipped' => $this->skipped,
			// Populated by FK rewrite pass.
			'fkRewrites' => 0,
			'branching' => $branching,
		];
	}//end result()
}//end class
