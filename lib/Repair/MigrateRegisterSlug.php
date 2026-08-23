<?php

/**
 * Renames this app's OpenRegister register SLUGS in place, before the import.
 *
 * WHY A REPAIR STEP AT ALL. OpenRegister's ImportHandler resolves a register by
 * SLUG and by nothing else — `registerMapper->find(id: strtolower($data['slug']))`
 * — and the `DoesNotExistException` branch is not an error path, it is the
 * "create a new one" path. So shipping a renamed slug in the register JSON
 * without renaming the row first does not rename anything: the import finds no
 * match, CREATES A SECOND, EMPTY REGISTER, and the app addresses that one from
 * then on. Every stored object stays behind on the old row, reachable by
 * nothing. Nothing errors. The app simply looks new.
 *
 * WHY IT DOES NOT TOUCH A SINGLE OBJECT. Measured against a live install rather
 * than assumed: an object is bound to its register by NUMERIC ID, not by slug.
 * Every shard table's `_register` column holds the id, and the tables themselves
 * are named `oc_openregister_table_<registerId>_<schemaId>` — OpenRegister
 * composes that name from `$register->getId()` at every call site, and
 * RegisterSchemaLinkageRepairService rejects anything that is not
 * `^[A-Za-z0-9]+_openregister_table_[0-9]+_[0-9]+$`. There is no slug anywhere
 * in the physical layout. Renaming a slug therefore re-points nothing and can
 * strand nothing: it is a one-column UPDATE on one row, and every object,
 * table, schema link and folder follows it untouched.
 *
 * WHY `x-openregister.app` MOVES WITH IT. For a `type: application`
 * configuration, ImportHandler's autoCreateRegisterIfApplication() reads
 * `$slug = $xOpenregister['app'] ?? $appId` — that field IS a register slug, not
 * an attribution label. Moving it alone is what rendered decidiq's Goals index
 * empty twice. The two values move together or not at all.
 *
 * ORDERING IS LOAD-BEARING. This runs FIRST among the register steps, ahead of
 * everything that resolves a register by slug and ahead of the register import.
 * It runs after the app-id steps, which move `oc_appconfig` / `oc_preferences`
 * rows and have nothing to do with registers.
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
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames the register rows the import will match against.
 *
 * @spec exclude No canonical spec covers the `openconnector` -> `integriq`
 *  register-slug migration. Pointing this at an existing spec would report
 *  conformance to a requirement that says nothing about it.
 */
class MigrateRegisterSlug implements IRepairStep {

	/**
	 * Old register slug => new register slug.
	 *
	 * @var array<string, string>
	 */
	public const SLUG_MAP = [
		'openconnector' => 'integriq',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 * @param MigrateRegisterSlugDecisions $decisions The pure predicates.
	 *
	 * @spec exclude No canonical spec covers the `openconnector` -> `integriq`
	 *  register-slug migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly MigrateRegisterSlugDecisions $decisions = new MigrateRegisterSlugDecisions(),
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the `openconnector` -> `integriq`
	 *  register-slug migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Rename Integriq register slugs';
	}//end getName()

	/**
	 * Rename the slugs on this app's existing register rows.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the `openconnector` -> `integriq`
	 *  register-slug migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function run(IOutput $output): void {
		$existing = $this->existingSlugs();
		$plan = $this->decisions->plan(map: self::SLUG_MAP, existing: $existing);

		foreach ($plan['refused'] as $old => $why) {
			$this->logger->warning(
				'MigrateRegisterSlug: ' . $why . '; renaming neither.',
				['old' => $old]
			);
		}

		$renamed = 0;
		foreach ($plan['renames'] as $old => $new) {
			if ($this->renameSlug(old: $old, new: $new) === true) {
				$renamed++;
			}
		}

		$output->info(
			sprintf(
				'MigrateRegisterSlug: %d register slug(s) renamed, %d refused.',
				$renamed,
				count($plan['refused'])
			)
		);
	}//end run()

	/**
	 * Read the slugs currently held by the registers on both sides of the map.
	 *
	 * A read failure yields an empty set, which plans no rename at all. That is
	 * the safe direction: this step must never turn a database hiccup into an
	 * aborted install.
	 *
	 * @return array<int, string>
	 */
	private function existingSlugs(): array {
		$slugs = $this->decisions->slugsToRead(map: self::SLUG_MAP);
		$placeholders = $this->decisions->placeholders(count: count($slugs));

		try {
			$rows = $this->db->executeQuery(
				'SELECT slug FROM `*PREFIX*openregister_registers` WHERE slug IN (' . $placeholders . ')',
				$slugs
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'MigrateRegisterSlug: could not read register slugs; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->decisions->slugsFrom(rows: $rows);
	}//end existingSlugs()

	/**
	 * Rename one register slug.
	 *
	 * Scoped to the old slug alone: the row's id, schemas, folder, application
	 * and every shard table it owns are keyed on the numeric id and are
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
				'UPDATE `*PREFIX*openregister_registers` SET slug = ? WHERE slug = ?',
				[$new, $old]
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'MigrateRegisterSlug: register slug rename failed.',
				['old' => $old, 'new' => $new, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end renameSlug()
}//end class
