<?php

/**
 * Integriq JobClassMigrationRun: the state of one pass of the stored job
 * class migration.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\Migration\IOutput;

/**
 * One pass of {@see MigrateStoredJobClasses}: the services it runs with, the
 * page it is on and what it has counted. Never leaves that class.
 *
 * @spec exclude One-off openconnector->integriq app-id rename plumbing: it
 *       rewrites a stored class-name string in place and adds no behaviour
 *       of its own. What a job DOES is specified where it is executed.
 */
final class JobClassMigrationRun {
	/**
	 * The OR object service the pass reads and writes jobs through.
	 *
	 * @var OrObjectService
	 */
	public OrObjectService $objects;

	/**
	 * The repair output channel.
	 *
	 * @var IOutput
	 */
	public IOutput $output;

	/**
	 * The offset of the page being read.
	 *
	 * @var int
	 */
	public int $offset = 0;

	/**
	 * The jobs rewritten so far.
	 *
	 * @var int
	 */
	public int $migrated = 0;

	/**
	 * The jobs whose rewrite failed so far.
	 *
	 * @var int
	 */
	public int $failed = 0;

	/**
	 * Count one job's outcome: true rewritten, false failed, null nothing to do.
	 *
	 * @param bool|null $outcome The outcome.
	 *
	 * @return void
	 *
	 * @spec exclude One-off openconnector->integriq app-id rename plumbing: it
	 *       rewrites a stored class-name string in place and adds no behaviour
	 *       of its own. What a job DOES is specified where it is executed.
	 */
	public function count(?bool $outcome): void {
		if ($outcome === true) {
			$this->migrated++;
		} elseif ($outcome === false) {
			$this->failed++;
		}

	}//end count()
}//end class
