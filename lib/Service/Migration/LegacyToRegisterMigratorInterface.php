<?php

/**
 * Contract of the LegacyToRegisterMigrator.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Migration;

/**
 * Public operations of {@see LegacyToRegisterMigrator} that other components call.
 *
 * The method signatures repeat those of LegacyToRegisterMigrator unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface LegacyToRegisterMigratorInterface {
	/**
	 * Run the migration. Per the spec contract:
	 *  - dryRun=true: count rows without writing
	 *  - entitySlug non-null: run a single entity (no flag flip)
	 *  - entitySlug=null: full run; flip storage_migrated on success
	 *
	 * @param boolean $dryRun Run without writing, only counting.
	 * @param string|null $entitySlug Single-entity override (slug from ENTITY_ORDER).
	 * @param integer $batchSize Default 10,000; valid range [100, 100000].
	 *
	 * @return array<array{slug:string,legacyCount:int,migratedCount:int,skipped:int,fkRewrites:int,elapsedMs:int}>
	 *
	 * @spec openspec/specs/openconnector-storage-migration/spec.md
	 */
	public function migrateAll(bool $dryRun = false, ?string $entitySlug = null, int $batchSize = 10000): array;

	/**
	 * Verify post-migration row-count parity per entity (Task 17 / ADR-001 seed-data deviation).
	 *
	 * For each of the 15 entities, compares the legacy table row count against the
	 * count of OR objects in oc_openregister_objects for the matching register +
	 * schema. Skipped rows (recorded in a prior migrateAll() run) count toward the
	 * legacy tally but NOT the register tally, so callers that pass the per-entity
	 * `skipped` figures get a true parity check rather than a false mismatch.
	 *
	 * The legacy seed for openconnector IS the migrated live data (no hand-crafted
	 * seed set), so this method is the canonical verification surface in place of a
	 * static seed-fixture comparison.
	 *
	 * @param array<string, int> $skippedBySlug Optional per-slug skipped counts from a
	 *                                          prior migrateAll() result, subtracted from
	 *                                          the legacy tally before the equality check.
	 *
	 * @return array<int, array{slug:string,legacy:int,register:int,skipped:int,equal:bool}>
	 *
	 * @spec openspec/specs/openconnector-storage-migration/spec.md
	 */
	public function verifyRowCounts(array $skippedBySlug = []): array;
}
