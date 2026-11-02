<?php

/**
 * Contract of the InlineSecretMigrationExecutor.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Security
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

namespace OCA\Integriq\Service\Security;

use RuntimeException;

/**
 * Public operations of {@see InlineSecretMigrationExecutor} that other components call.
 */
interface InlineSecretMigrationExecutorInterface {
	/**
	 * Migrate every source's inline secrets, then re-report the true Phase D gate.
	 *
	 * Fails closed BEFORE touching any source when the installed broker cannot
	 * mint or cannot resolve organisation-scoped credentials sessionlessly.
	 *
	 * @param int $limit Maximum number of sources to inspect.
	 *
	 * @return array{sources: array<int, array<string, mixed>>, totalSources: int, migrated: int, failed: int,
	 *     blocked: int, skipped: int, postRun: array{clean: bool, pending: int, manual: int}}
	 *
	 * @throws RuntimeException When the broker is unavailable or too old to migrate safely (nothing is rewritten).
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	public function migrateAll(int $limit = 1000): array;

	/**
	 * Perform a REAL run across EVERY migratable schema.
	 *
	 * The counterpart of {@see InlineSecretMigrationPlanner::planEverything()},
	 * and the reason it exists: the dry-run planned every schema in `MIGRATABLE`
	 * while the real run called {@see migrateAll()}, which is hardcoded to
	 * `source`. `sender_identity` was therefore reported as `wouldMigrate: N` by
	 * `--dry-run` and migrated 0 by the real run — a divergence an operator sees
	 * as a migration that silently does nothing (integriq#2104 review
	 * 5264751700, blocker 2).
	 *
	 * `migrateAll()` stays as the `source` entry point, and its `postRun.clean`
	 * is still the SOURCE-only Phase D gate that
	 * {@see \OCA\Integriq\Repair\RemoveMigratedSourceSecretFields} reads —
	 * folding another schema into that number would block source's property
	 * removal on an unrelated schema's state. The per-schema runs are returned
	 * separately for exactly that reason.
	 *
	 * @param integer $limit Maximum objects to inspect per schema.
	 *
	 * @return array{schemas: array<string,mixed>, migrated: int, failed: int,
	 *         blocked: int, skipped: int} The per-schema runs and their totals.
	 *         Deliberately NO `clean` key: `migrateSchema()` already returns one
	 *         inside `postRun`, meaning the post-run RE-PLAN verdict (nothing
	 *         pending AND nothing needing manual review). A second `clean` here
	 *         would mean something narrower — no failures and no blocks, while a
	 *         manual-review field counts as `skipped` — and nothing read it
	 *         (integriq#2104 review 5266971176).
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register
	 */
	public function migrateEverything(int $limit = 1000): array;

	/**
	 * Migrate one schema's estate.
	 *
	 * The generic form of {@see migrateAll()}, which stays as the `source` entry
	 * point because its `postRun.clean` is the SOURCE Phase D gate that
	 * {@see \OCA\Integriq\Repair\RemoveMigratedSourceSecretFields} reads. Folding
	 * another schema into that number would block source's property removal on an
	 * unrelated schema's state.
	 *
	 * Every safety rule of migrateAll() applies unchanged: mint → verify
	 * byte-for-byte → write the reference → null the inline value, per object per
	 * field, with per-object isolation and no batch transaction.
	 *
	 * @param string $schema The schema slug to migrate.
	 * @param int $limit Maximum objects to inspect.
	 *
	 * @return array{schema: string, objects: array<int, array<string, mixed>>, totalObjects: int,
	 *     migrated: int, failed: int, blocked: int, skipped: int, postRun: array<string, mixed>}
	 *
	 * @spec openspec/changes/enrol-sender-identity-in-credential-broker/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-signing-key-is-held-in-the-broker-not-in-the-register
	 */
	public function migrateSchema(string $schema, int $limit = 1000): array;
}
