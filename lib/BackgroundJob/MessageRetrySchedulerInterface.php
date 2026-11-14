<?php

/**
 * Scheduling contract of an outbound message retry: run the retry job for one message later.
 *
 * @category Contract
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

/**
 * Schedules the retry of one outbound message, for the sender that decides a
 * retry is due and leaves the job that resends it to this component.
 */
interface MessageRetrySchedulerInterface {
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
	public function scheduleRetry(string $messageId, int $runAfter): void;
}
