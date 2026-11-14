<?php

/**
 * Scheduling contract of the CloudEvent fan-out: queue one stored event for processing.
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
 * Queues the fan-out of one stored CloudEvent, for the event code that answers
 * the write first and leaves delivery to a background job.
 */
interface EventFanOutSchedulerInterface {
	/**
	 * Queue the processing of one stored CloudEvent.
	 *
	 * @param string $eventId The uuid of the stored CloudEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stop-cloudevent-recursion/specs/events/spec.md#requirement-event-fan-out-shall-not-run-inside-the-originating-write-request
	 */
	public function scheduleFanOut(string $eventId): void;
}
