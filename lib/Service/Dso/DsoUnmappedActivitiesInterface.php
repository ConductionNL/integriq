<?php

/**
 * Contract of the DsoUnmappedActivities.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Dso
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

namespace OCA\Integriq\Service\Dso;

/**
 * Public operations of {@see DsoUnmappedActivities} that other components call.
 */
interface DsoUnmappedActivitiesInterface {
	/**
	 * The unmapped activities, most often seen first.
	 *
	 * An activity a row was added for after its verzoek arrived is left out.
	 * An activity without imowId or activityId cannot be mapped and is only
	 * counted. A verzoek written before the STAM identifiers carries `code`,
	 * which is read as the activityId.
	 *
	 * @return array{activities: array<int, array<string, mixed>>, withoutIdentifier: int, scanned: int, limit: int} The list.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.2
	 */
	public function list(): array;
}
