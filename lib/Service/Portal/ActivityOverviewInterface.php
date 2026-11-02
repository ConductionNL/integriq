<?php

/**
 * Contract of the ActivityOverview.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Portal
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

namespace OCA\Integriq\Service\Portal;

/**
 * Public operations of {@see \OCA\Integriq\Service\Portal\ActivityOverview} that other components call.
 */
interface ActivityOverviewInterface {
	/**
	 * Every row, active or not, with `lastReceivedAt` and `unmapped: false`,
	 * then one row per unmapped activity with `unmapped: true` and `waiting`.
	 *
	 * @return array{results: array<int, array<string, mixed>>} The rows.
	 *
	 * @spec openspec/changes/dso-activity-overview/tasks.md#task-2
	 */
	public function rows(): array;
}
