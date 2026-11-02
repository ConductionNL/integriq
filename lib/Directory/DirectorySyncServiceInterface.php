<?php

/**
 * Contract of the DirectorySyncService.
 *
 * @category Contract
 * @package  OCA\Integriq\Directory
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

namespace OCA\Integriq\Directory;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see DirectorySyncService} that other components call.
 *
 * The method signatures repeat those of DirectorySyncService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface DirectorySyncServiceInterface {
	/**
	 * Run every enabled directory connection.
	 *
	 * @param boolean $dryRun Whether this is a preview that writes no membership.
	 *
	 * @return array<int,array<string,mixed>> One run record per connection.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function runAll(bool $dryRun = false): array;

	/**
	 * Run one directory connection.
	 *
	 * @param ObjectEntity $connection The directory connection Source.
	 * @param boolean $dryRun Whether this is a preview that writes no membership.
	 * @param boolean $confirmRemovals Whether an administrator confirmed a previously guarded run.
	 *
	 * @return array<string,mixed> The run record.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function run(ObjectEntity $connection, bool $dryRun = false, bool $confirmRemovals = false): array;

	/**
	 * Work out what a run would change, without changing anything.
	 *
	 * @param DirectorySnapshot $snapshot What the directory answered.
	 * @param array<string,mixed> $configuration The connection configuration.
	 *
	 * @return array<string,mixed> The plan: additions, removals, managed groups and current totals.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
	 */
	public function plan(DirectorySnapshot $snapshot, array $configuration): array;
}
