<?php

/**
 * Contract of the FlowGraphMigrationService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see FlowGraphMigrationService} that other components call.
 *
 * The method signatures repeat those of FlowGraphMigrationService unchanged, so the findings
 * phpmd reports on them belong to that class and are suppressed here.
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
interface FlowGraphMigrationServiceInterface {
	/**
	 * Result marker: the flow's graph was written (or would be, on a dry run).
	 *
	 * @var string
	 */
	public const MIGRATED = 'migrated';

	/**
	 * Result marker: the translator refused the flow; reasons attached.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * Translate every live `flow` object's steps into a graph, in place.
	 *
	 * @param bool $apply False (the default posture for the occ command) reports
	 *                    what WOULD happen without writing anything.
	 *
	 * @return array<int, array{id: string, name: string, action: string, reasons: array<int, string>}> One row per flow.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function migrate(bool $apply = false): array;

	/**
	 * Remove the written graph from every live `flow` object, in place.
	 *
	 * The rollback of a migration whose forward direction is additive: it
	 * deletes `nodes`/`edges` and leaves `steps` as the only shape again. A
	 * flow whose `steps` are gone is REFUSED — deleting its graph would
	 * leave nothing executable at all, and how its steps vanished is a
	 * question for a person, not a rollback.
	 *
	 * @param bool $apply False reports what WOULD happen without writing.
	 *
	 * @return array<int, array{id: string, name: string, action: string, reasons: array<int, string>}> One row per flow.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function rollback(bool $apply = false): array;
}
