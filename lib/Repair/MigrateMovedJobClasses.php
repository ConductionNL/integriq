<?php

/**
 * Integriq Migrate Moved Job Classes Repair Step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Rewrites the stored names of the job classes listed in
 * {@see MovedJobClasses} to their current name, in the two places they are
 * stored:
 *   - the `jobClass` of every job object, which JobService resolves through
 *     the container at run time. The paged walk, the system principal and the
 *     per-object isolation are those of {@see MigrateStoredJobClasses}; this
 *     step only supplies the old-to-new map;
 *   - the `class` of queued Nextcloud background jobs in `oc_jobs`
 *     (FetchFilesJob is queued per file fetch). Nextcloud deletes a row whose
 *     class cannot be loaded, and `IJobList::add()` deduplicates by class name,
 *     so a row left under the old name would be dropped once the alias goes and
 *     is not recognised as already queued until then.
 *
 * Idempotent: a current name is not a key of the map, so a second run changes
 * nothing. Any value that is not an old name of a moved class is left as it
 * is. Never throws, because it also runs under `<install>`.
 *
 * Registered in `<install>` and `<post-migration>` in `appinfo/info.xml`,
 * right after MigrateStoredJobClasses, so a value still on the openconnector
 * namespace is first renamed to `OCA\Integriq\...` and then moved here.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Service\Job\MovedJobClasses;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrite stored names of moved job classes to their current name.
 *
 * @spec exclude Class-move plumbing: rewrites stored class-name strings and
 *       adds no behaviour of its own.
 */
class MigrateMovedJobClasses extends MigrateStoredJobClasses {
	/**
	 * The step name that prefixes its output and log lines.
	 *
	 * @var string
	 */
	protected const STEP = 'MigrateMovedJobClasses';

	/**
	 * What the stale values name, for the output lines.
	 *
	 * @var string
	 */
	protected const OLD_NAMES = 'the old name of a moved job class';

	/**
	 * Constructor for MigrateMovedJobClasses.
	 *
	 * @param ContainerInterface $container The container used to resolve the OR object service
	 * @param LoggerInterface $logger Logger for values that fail to migrate
	 * @param IDBConnection $connection The connection holding `oc_jobs`
	 *
	 * @return void
	 */
	public function __construct(
		ContainerInterface $container,
		private LoggerInterface $logger,
		private IDBConnection $connection,
	) {
		parent::__construct(container: $container, logger: $logger);
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude Class-move plumbing; it returns a human-readable label for
	 *       `occ maintenance:repair` output and adds no behaviour of its own.
	 */
	public function getName(): string {
		return 'Rewrite stored Integriq job class names that moved to a new namespace';
	}//end getName()

	/**
	 * Rewrite the moved class names on job objects, then in `oc_jobs`.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec exclude Class-move plumbing: it rewrites stored class-name strings
	 *       in place and adds no behaviour of its own. What a job DOES is
	 *       specified where it is executed.
	 */
	public function run(IOutput $output): void {
		parent::run(output: $output);

		$moved = 0;
		foreach (MovedJobClasses::NEW_NAMES as $oldName => $newName) {
			$moved += $this->moveQueuedJobs(oldName: $oldName, newName: $newName);
		}

		if ($moved > 0) {
			$output->info(self::STEP . ': rewrote ' . $moved . ' queued background job(s).');
		}
	}//end run()

	/**
	 * The current name of a moved class, or null when the name did not move.
	 *
	 * @param string $class The stored class name
	 *
	 * @return string|null The class name to store, or null to leave it
	 *
	 * @spec exclude Class-move plumbing: maps a stored class-name string and
	 *       adds no behaviour of its own.
	 */
	protected function renamedClass(string $class): ?string {
		return (MovedJobClasses::NEW_NAMES[$class] ?? null);
	}//end renamedClass()

	/**
	 * Rename the queued background jobs stored under one old class name.
	 *
	 * @param string $oldName The class name as stored
	 * @param string $newName The class name to store
	 *
	 * @return int The number of rows rewritten; 0 when the update failed
	 */
	private function moveQueuedJobs(string $oldName, string $newName): int {
		try {
			$query = $this->connection->getQueryBuilder();
			$query->update('jobs')
				->set('class', $query->createNamedParameter($newName))
				->where($query->expr()->eq('class', $query->createNamedParameter($oldName)));

			return $query->executeStatement();
		} catch (Throwable $e) {
			$this->logger->error(
				'Integriq: could not rewrite queued background jobs of a moved class',
				['class' => $oldName, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end moveQueuedJobs()
}//end class
