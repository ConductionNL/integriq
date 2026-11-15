<?php

/**
 * Scheduling contract of the retention rebase: queue the job that applies the current retention to existing logs.
 *
 * @category Contract
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
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

/**
 * Queues a retention rebase, for the settings service that decides one is due
 * and leaves the job that runs it to this component.
 */
interface RetentionRebaseSchedulerInterface {
	/**
	 * Queue the first run of a rebase over the given log kinds.
	 *
	 * @param list<string> $kinds The log kinds, keys of RetentionSettings::REBASE_SCHEMAS.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	public function scheduleRebase(array $kinds): void;
}//end interface
