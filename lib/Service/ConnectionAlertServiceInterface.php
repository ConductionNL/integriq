<?php

/**
 * Contract of the ConnectionAlertService.
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

use DateTimeImmutable;

/**
 * Public operations of {@see ConnectionAlertService} that other components call.
 */
interface ConnectionAlertServiceInterface {
	/**
	 * Count every threshold and open or clear alerts.
	 *
	 * @param DateTimeImmutable $now The moment to count back from.
	 *
	 * @return array{opened: int, cleared: int} How many alerts opened and cleared.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
	 */
	public function evaluate(DateTimeImmutable $now): array;
}
