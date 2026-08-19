<?php

/**
 * A Job's `interval` expressed as a five-field cron, or refused.
 *
 * Split out of {@see \OCA\OpenConnector\Service\JobToFlowGenerator} because it
 * is the one part of that translation with a right answer independent of flows:
 * "which second-counts is a wall-clock cron the same cadence as?". Keeping it
 * here means the table can be read, and tested, without a flow document in
 * sight.
 *
 * A cron field is absolute wall-clock, so `*\/7` fires at :00 :07 … :56 and then
 * jumps back to :00 — a four-minute gap once an hour. Only a step that divides
 * its unit evenly is a uniform cadence, which is why this is an explicit table
 * rather than a computed `*\/N`: the arithmetic that would produce it is exactly
 * the arithmetic that gets the boundary wrong.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

/**
 * Translates a job interval in seconds into a cron expression.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
final class JobIntervalCron {

	/**
	 * Job intervals (seconds) that a five-field cron expresses EXACTLY.
	 *
	 * @var array<int, string>
	 */
	private const CRON_FOR_INTERVAL = [
		60 => '* * * * *',
		120 => '*/2 * * * *',
		180 => '*/3 * * * *',
		240 => '*/4 * * * *',
		300 => '*/5 * * * *',
		360 => '*/6 * * * *',
		600 => '*/10 * * * *',
		720 => '*/12 * * * *',
		900 => '*/15 * * * *',
		1200 => '*/20 * * * *',
		1800 => '*/30 * * * *',
		3600 => '0 * * * *',
		7200 => '0 */2 * * *',
		10800 => '0 */3 * * *',
		14400 => '0 */4 * * *',
		21600 => '0 */6 * * *',
		28800 => '0 */8 * * *',
		43200 => '0 */12 * * *',
		86400 => '0 0 * * *',
	];

	/**
	 * The interval, in whole seconds.
	 *
	 * @param mixed $interval The interval as the job record stores it.
	 *
	 * @return integer The interval; 0 or less when there is none usable.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function secondsOf(mixed $interval): int {
		if (is_numeric($interval) === false) {
			return 0;
		}

		return (int)$interval;

	}//end secondsOf()

	/**
	 * The cron expression for one interval, or null when there is none.
	 *
	 * @param integer $seconds The interval in whole seconds.
	 *
	 * @return string|null The five-field cron, or null when a cron cannot say it.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function cronFor(int $seconds): ?string {
		return (self::CRON_FOR_INTERVAL[$seconds] ?? null);

	}//end cronFor()

	/**
	 * Every interval a cron can express, for naming them in a refusal.
	 *
	 * @return array<int, integer> The intervals, in seconds, ascending.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function expressibleIntervals(): array {
		return array_keys(self::CRON_FOR_INTERVAL);

	}//end expressibleIntervals()
}//end class
