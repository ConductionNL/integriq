<?php

/**
 * Queues an attachment download job on the Nextcloud job list.
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
 * Adds a {@see FetchDsoAttachmentsJob} to the job list.
 */
class AttachmentFetchJobScheduler implements AttachmentFetchSchedulerInterface {
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
	 * Queue the attachment download of one request, run as the given account.
	 *
	 * @param string|null $requestUuid The uuid of the saved request.
	 * @param string $actingUserId The uid the intake acted as.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-the-endpoint-does-not-wait-for-the-bijlagen
	 */
	public function scheduleFetch(?string $requestUuid, string $actingUserId): void {
		$this->jobList->add(
			FetchDsoAttachmentsJob::class,
			['requestUuid' => $requestUuid, 'actingUserId' => $actingUserId]
		);
	}//end scheduleFetch()
}//end class
