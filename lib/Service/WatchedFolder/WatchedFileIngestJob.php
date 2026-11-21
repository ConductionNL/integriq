<?php

/**
 * Integriq Watched File Ingest Job: ingests one file of a watched folder, off the upload's request.
 *
 * @category Service
 * @package  OCA\Integriq\Service\WatchedFolder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\WatchedFolder;

use OCA\Integriq\Service\WatchedFolderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A queued job (beside the service it runs, like FetchFilesJob), added by the file event listener or by the sweep with the
 * file id and the synchronization id (design D6). It is not a TimedJob: the
 * schedule of the sweep is the platform's (REQ-DIC-005).
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */
class WatchedFileIngestJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time    The time factory.
	 * @param WatchedFolderService $service Ingests the file once.
	 * @param LoggerInterface      $logger  Records a dropped or failed job.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly WatchedFolderService $service,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Ingest the file this job was queued for.
	 *
	 * @param mixed $argument `{fileId: int, synchronizationId: string}`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	protected function run($argument): void {
		$fileId            = null;
		$synchronizationId = null;
		if (is_array($argument) === true) {
			$fileId            = ($argument['fileId'] ?? null);
			$synchronizationId = ($argument['synchronizationId'] ?? null);
		}

		if (is_int($fileId) === false || is_string($synchronizationId) === false || $synchronizationId === '') {
			$this->logger->warning('integriq: a watched file job without a file id and a synchronization id was dropped.');
			return;
		}

		try {
			$this->service->ingest(synchronizationId: $synchronizationId, fileId: $fileId);
		} catch (Throwable $e) {
			$this->logger->error('integriq: watched file ' . $fileId . ' could not be ingested: ' . $e->getMessage(), ['exception' => $e]);
		}
	}//end run()
}//end class
