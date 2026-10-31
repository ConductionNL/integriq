<?php

/**
 * Contract of the OptOutTableMigrator.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound\Identity
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

namespace OCA\Integriq\Outbound\Identity;

/**
 * Public operations of {@see OptOutTableMigrator} that other components call.
 */
interface OptOutTableMigratorInterface {
	/**
	 * Copy every OpenRegister opt-out the table does not hold yet.
	 *
	 * The read is an engine read (`_rbac: false`): a repair step has no user,
	 * and the schema is deny-all. Nothing is written to OpenRegister.
	 *
	 * @return array{read:int,copied:int,present:int,skipped:int} What it did.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function migrate(): array;
}
