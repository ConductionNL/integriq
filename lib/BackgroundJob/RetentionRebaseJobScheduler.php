<?php

/**
 * Queues a RetentionRebaseJob on the Nextcloud job list.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCP\BackgroundJob\IJobList;

/**
 * Adds the first run of a {@see RetentionRebaseJob} to the job list.
 */
class RetentionRebaseJobScheduler implements RetentionRebaseSchedulerInterface {

	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The Nextcloud job list.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IJobList $jobList,
	) {
	}//end __construct()

	/**
	 * Queue the first run of a rebase over the given log kinds, from the first page.
	 *
	 * @param list<string> $kinds The log kinds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	public function scheduleRebase(array $kinds): void {
		$this->jobList->add(RetentionRebaseJob::class, ['kinds' => $kinds, 'kind' => 0, 'offset' => 0]);
	}//end scheduleRebase()
}//end class
