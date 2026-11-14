<?php

/**
 * Queues the outbound message retry job on the Nextcloud job list.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCP\BackgroundJob\IJobList;

/**
 * Schedules a {@see StufZdsRetryJob} on the job list.
 */
class MessageRetryJobScheduler implements MessageRetrySchedulerInterface {
	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The background job list.
	 */
	public function __construct(
		private readonly IJobList $jobList,
	) {
	}//end __construct()

	/**
	 * Schedule the retry job of one message to run no earlier than the given time.
	 *
	 * @param string $messageId The uuid of the message row.
	 * @param int $runAfter The unix time the retry may run from.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function scheduleRetry(string $messageId, int $runAfter): void {
		$this->jobList->scheduleAfter(StufZdsRetryJob::class, $runAfter, ['messageId' => $messageId]);
	}//end scheduleRetry()
}//end class
