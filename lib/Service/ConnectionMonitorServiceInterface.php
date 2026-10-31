<?php

/**
 * Contract of the ConnectionMonitorService.
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
 * Public operations of {@see ConnectionMonitorService} that other components call.
 */
interface ConnectionMonitorServiceInterface {
	/**
	 * The monitor report for one source, by uuid or slug.
	 *
	 * @param string            $sourceRef The source's uuid or slug.
	 * @param DateTimeImmutable $now       The end of the reporting period.
	 *
	 * @return array<string, mixed>|null The report, or null when no such source exists.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-how-it-performed-in-the-last-24-hours-req-crun-006
	 */
	public function report(string $sourceRef, DateTimeImmutable $now): ?array;
}
