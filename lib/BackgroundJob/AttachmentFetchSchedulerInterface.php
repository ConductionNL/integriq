<?php

/**
 * Scheduling contract of the attachment download: queue the fetch of one request's attachments.
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
 * Queues the download of the attachments of one saved request, for intake code
 * that answers the caller first and leaves the download to a job.
 */
interface AttachmentFetchSchedulerInterface {
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
	public function scheduleFetch(?string $requestUuid, string $actingUserId): void;
}
