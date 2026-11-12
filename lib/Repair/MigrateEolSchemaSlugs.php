<?php
/**
 * Renames the two camelCase endoflife.date schema slugs to snake_case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `eolProduct` and `eolCycle` were the only camelCase slugs among the 55 this
 * app declares. Every other slug is snake_case, and a register that spells two
 * of its schemas differently from the other fifty-three is a register whose
 * URLs cannot be guessed.
 *
 * WHY A ROW RENAME IS SAFE. An object is bound to its schema by NUMERIC ID, not
 * by slug: every shard table's `_schema` column holds the id, and the tables
 * themselves are named `oc_openregister_table_<registerId>_<schemaId>`. There is
 * no slug anywhere in the physical layout, so renaming one re-points nothing and
 * can strand nothing. The eight seeded `eolProduct` objects and their magic
 * table follow the rename untouched.
 *
 * WHY THIS HAS TO BE A REPAIR STEP AND NOT JUST A FRAGMENT EDIT.
 * `ImportHandler` matches an incoming schema by `$data['slug']`, never by the
 * dict key it is filed under. Change the slug in the register fragment alone and
 * the importer finds no match, CREATES a second schema, and leaves the original
 * row, its magic table and its eight objects in place forever — the import
 * unions schema ids into the register and never removes one. Renaming the row
 * first is what makes the fragment land on the schema that already exists.
 *
 * WHY THE STORED REFERENCES MOVE TOO. A synchronization addresses its target as
 * the literal string `<registerSlug>/<schemaSlug>`, so the eight seeded
 * endoflife-date synchronizations hold `integriq/eolCycle` in `target_id`. The
 * seeds are re-imported with the corrected value, but an operator's own
 * synchronization pointing at the same schema is not, and a target that resolves
 * to nothing fails at run time rather than at import. So this step rewrites the
 * stored value as well, guarded on an exact match.
 *
 * ORDERING IS LOAD-BEARING. It runs BEFORE `InitializeRegister`, for the reason
 * above: after the import, the duplicate schema already exists and this step can
 * only refuse.
 *
 * NON-DESTRUCTIVE AND IDEMPOTENT. It renames only when the old slug is present
 * and the new one is not; a second run finds nothing to do and says so. It
 * refuses rather than merges when both exist, because two rows sharing a slug
 * means the lower id silently wins every lookup, and choosing between them is a
 * decision about data. It never throws: under `<install>` an escaping exception
 * aborts the install and the app never enables at all.
 *
 * @category  Repair
 * @package   OCA\Integriq\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames the endoflife.date schema rows the import will match against.
 *
 * @spec openspec/specs/endoflife-date-source/spec.md
 */
class MigrateEolSchemaSlugs implements IRepairStep {

	/**
	 * Old schema slug => new schema slug.
	 *
	 * @var array<string, string>
	 */
	public const SLUG_MAP = [
		'eolProduct' => 'eol_product',
		'eolCycle' => 'eol_cycle',
	];

	/**
	 * The register slug these schemas live under, and the prefix of a stored
	 * `<registerSlug>/<schemaSlug>` reference.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-the-two-schema-slugs-are-snake-case-and-an-existing-install-is-renamed-in-place
	 */
	public function getName(): string {
		return 'Rename the endoflife.date schema slugs to snake_case';
	}//end getName()

	/**
	 * Plan the renames from the slugs currently present.
	 *
	 * Pure, so the decision table is testable without a database. A pair whose
	 * old slug is absent is nothing to do; a pair where BOTH exist is refused,
	 * because merging two same-slug rows is a decision about data.
	 *
	 * @param array<string, string> $map      Old slug => new slug.
	 * @param array<int, string>    $existing Slugs found in the database.
	 *
	 * @return array{renames: array<string, string>, refused: array<string, string>}
	 *
	 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-the-two-schema-slugs-are-snake-case-and-an-existing-install-is-renamed-in-place
	 */
	public function plan(array $map, array $existing): array {
		$renames = [];
		$refused = [];

		foreach ($map as $old => $new) {
			$hasOld = in_array($old, $existing, true);
			$hasNew = in_array($new, $existing, true);

			if ($hasOld === false) {
				continue;
			}

			if ($hasNew === true) {
				$refused[$old] = sprintf(
					"both '%s' and '%s' exist, so the lower id would silently win every lookup",
					$old,
					$new
				);
				continue;
			}

			$renames[$old] = $new;
		}

		return ['renames' => $renames, 'refused' => $refused];
	}//end plan()

	/**
	 * Rename this app's endoflife.date schema rows, then re-point the stored
	 * references that spell the slug out.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/endoflife-date-source/spec.md#requirement-the-two-schema-slugs-are-snake-case-and-an-existing-install-is-renamed-in-place
	 */
	public function run(IOutput $output): void {
		$plan = $this->plan(map: self::SLUG_MAP, existing: $this->existingSlugs());

		foreach ($plan['refused'] as $old => $why) {
			$this->logger->warning(
				'MigrateEolSchemaSlugs: ' . $why . '; renaming neither.',
				['old' => $old]
			);
		}

		$renamed = 0;
		foreach ($plan['renames'] as $old => $new) {
			if ($this->renameSlug(old: $old, new: $new) === true) {
				$renamed++;
			}
		}

		$repointed = $this->repointStoredReferences();

		$output->info(
			sprintf(
				'MigrateEolSchemaSlugs: %d schema slug(s) renamed, %d refused, %d stored reference(s) re-pointed.',
				$renamed,
				count($plan['refused']),
				$repointed
			)
		);
	}//end run()

	/**
	 * Read the slugs currently held by this app's schemas on both sides of the
	 * map.
	 *
	 * A read failure yields an empty set, which plans no rename at all. That is
	 * the safe direction: this step must never turn a database hiccup into an
	 * aborted install.
	 *
	 * Scoped to this app's own schemas. Another app owning a schema that happens
	 * to share one of these slugs is none of this step's business, and renaming
	 * it would be the cross-app collision the scoping exists to avoid.
	 *
	 * @return array<int, string>
	 */
	private function existingSlugs(): array {
		$found = [];
		foreach ($this->slugRows() as $row) {
			$slug = ($row['slug'] ?? null);
			if (is_string($slug) === true && $slug !== '') {
				$found[] = $slug;
			}
		}

		return array_values(array_unique($found));
	}//end existingSlugs()

	/**
	 * The schema rows of this app that carry an old or a new slug of the map;
	 * none (logged) when the read fails.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function slugRows(): array {
		$slugs = $this->mappedSlugs();
		$placeholders = implode(',', array_fill(0, count($slugs), '?'));

		try {
			return $this->db->executeQuery(
				'SELECT slug FROM `*PREFIX*openregister_schemas` WHERE slug IN (' . $placeholders . ')'
				. ' AND (application = ? OR application LIKE ?)',
				array_merge($slugs, [self::REGISTER_SLUG, self::REGISTER_SLUG . '.%'])
			)->fetchAll();
		} catch (Exception $e) {
			$this->logSkip(message: 'MigrateEolSchemaSlugs: could not read schema slugs; skipping.', exception: $e);
			return [];
		}
	}//end slugRows()

	/**
	 * Every old and new slug of the map, pair by pair.
	 *
	 * @return array<int, string>
	 */
	private function mappedSlugs(): array {
		$slugs = [];
		foreach (self::SLUG_MAP as $old => $new) {
			$slugs[] = $old;
			$slugs[] = $new;
		}

		return $slugs;
	}//end mappedSlugs()

	/**
	 * Rename one schema slug.
	 *
	 * Scoped to the old slug and this app's schemas. The row's id, properties,
	 * configuration and every object it owns are keyed on the numeric id and are
	 * deliberately left untouched.
	 *
	 * @param string $old Current slug.
	 * @param string $new Replacement slug.
	 *
	 * @return bool True when the row was updated.
	 */
	private function renameSlug(string $old, string $new): bool {
		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE slug = ?'
				. ' AND (application = ? OR application LIKE ?)',
				[$new, $old, self::REGISTER_SLUG, self::REGISTER_SLUG . '.%']
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'MigrateEolSchemaSlugs: schema slug rename failed.',
				['old' => $old, 'new' => $new, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end renameSlug()

	/**
	 * Re-point stored `<registerSlug>/<schemaSlug>` references on this app's
	 * synchronizations.
	 *
	 * A synchronization names its source and target as that literal string, so a
	 * schema-slug rename leaves every one of them addressing a schema that no
	 * longer answers. The seeded endoflife-date synchronizations are re-imported
	 * with the corrected value, but an operator's own is not.
	 *
	 * Guarded on an exact value match, so nothing that merely contains the old
	 * slug as a substring is touched.
	 *
	 * @return int Number of stored values rewritten.
	 */
	private function repointStoredReferences(): int {
		$table = $this->synchronizationTable();
		if ($table === null) {
			return 0;
		}

		$repointed = 0;
		foreach (self::SLUG_MAP as $old => $new) {
			$oldRef = self::REGISTER_SLUG . '/' . $old;
			$newRef = self::REGISTER_SLUG . '/' . $new;

			foreach (['source_id', 'target_id'] as $column) {
				try {
					$repointed += $this->db->executeStatement(
						'UPDATE `*PREFIX*' . $table . '` SET ' . $column . ' = ? WHERE ' . $column . ' = ?',
						[$newRef, $oldRef]
					);
				} catch (Exception $e) {
					$this->logger->warning(
						'MigrateEolSchemaSlugs: could not re-point a stored reference.',
						[
							'table' => $table,
							'column' => $column,
							'old' => $oldRef,
							'exception' => $e->getMessage(),
						]
					);
				}
			}
		}

		return $repointed;
	}//end repointStoredReferences()

	/**
	 * Resolve the physical table holding this app's synchronization objects.
	 *
	 * OpenRegister composes the name from the numeric register and schema ids,
	 * so it is looked up rather than assumed. Returned unprefixed, for
	 * interpolation into a `*PREFIX*` statement: a table name cannot be a bound
	 * parameter, so the two ids are cast to int and the composed name is checked
	 * against the same shape OpenRegister's own linkage repair enforces before
	 * it reaches SQL.
	 *
	 * @return string|null The unprefixed table name, or null when it cannot be
	 *  resolved.
	 */
	private function synchronizationTable(): ?string {
		$row = $this->synchronizationIds();
		if (is_array($row) === false) {
			return null;
		}

		return $this->tableNameFor(row: $row);
	}//end synchronizationTable()

	/**
	 * The register and schema ids of this app's synchronization schema, as
	 * the database answers them; null (logged) when the read fails.
	 *
	 * @return mixed The row, false without one, or null.
	 */
	private function synchronizationIds(): mixed {
		try {
			return $this->db->executeQuery(
				'SELECT r.id AS register_id, s.id AS schema_id'
				. ' FROM `*PREFIX*openregister_registers` r, `*PREFIX*openregister_schemas` s'
				. ' WHERE r.slug = ? AND s.slug = ? AND s.application = ?',
				[self::REGISTER_SLUG, 'synchronization', self::REGISTER_SLUG]
			)->fetch();
		} catch (Exception $e) {
			$this->logSkip(message: 'MigrateEolSchemaSlugs: could not resolve the synchronization table; skipping.', exception: $e);
			return null;
		}
	}//end synchronizationIds()

	/**
	 * The table name composed from both ids, checked against the shape
	 * OpenRegister's own linkage repair enforces; null when an id is missing.
	 *
	 * @param array<string, mixed> $row The register and schema ids.
	 *
	 * @return string|null The unprefixed table name.
	 */
	private function tableNameFor(array $row): ?string {
		$registerId = (int)($row['register_id'] ?? 0);
		$schemaId = (int)($row['schema_id'] ?? 0);
		if ($registerId === 0 || $schemaId === 0) {
			return null;
		}

		$table = 'openregister_table_' . $registerId . '_' . $schemaId;
		if (preg_match('/^openregister_table_[0-9]+_[0-9]+$/', $table) !== 1) {
			return null;
		}

		return $table;
	}//end tableNameFor()

	/**
	 * Log a read this step skips because the database refused it.
	 *
	 * @param string    $message   What could not be read.
	 * @param Exception $exception The refusal.
	 *
	 * @return void
	 */
	private function logSkip(string $message, Exception $exception): void {
		$this->logger->warning(
			$message,
			['exception' => $exception->getMessage()]
		);
	}//end logSkip()
}//end class
