<?php

/**
 * Integriq: download the bijlagen of one DSO verzoek after intake.
 *
 * @category Cron
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#requirement-bijlagen-download-and-storage-req-dso-005
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches one dso_verzoek's bijlagen OUTSIDE the STAM request.
 *
 * {@see \OCA\Integriq\Service\DsoIngestService::ingest()} queues one of these
 * per request that has bijlagen, so the STAM endpoint answers 202 straight
 * after the request is saved and the downloads run on the cron worker.
 *
 * A QueuedJob, like {@see FetchFilesJob}: it runs once and removes itself.
 * Running it again for the same request is safe, because the fetcher only
 * touches entries that are not `stored`.
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#requirement-bijlagen-download-and-storage-req-dso-005
 */
class FetchDsoAttachmentsJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param DsoAttachmentFetcher $fetcher Downloads and stores the bijlagen.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DsoAttachmentFetcher $fetcher,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Fetch the bijlagen of the request this job was queued for.
	 *
	 * @param mixed $argument The queued argument: `['requestUuid' => string]`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-a-rerun-finishes-what-a-crash-left
	 */
	protected function run($argument): void {
		$requestUuid = '';
		if (is_array($argument) === true) {
			$requestUuid = (string)($argument['requestUuid'] ?? '');
		}

		if ($requestUuid === '') {
			$this->logger->warning('[FetchDsoAttachmentsJob] dropped: no requestUuid in the argument');

			return;
		}

		try {
			$this->fetcher->fetchPending(requestUuid: $requestUuid);
		} catch (Throwable $exception) {
			// A job that throws must not take the worker down with it; the
			// entries it did not reach stay pending for a rerun.
			$this->logger->error(
				'[FetchDsoAttachmentsJob] bijlage download failed for verzoek ' . $requestUuid . ': '
				. $exception->getMessage(),
				['exception' => $exception]
			);
		}
	}//end run()
}//end class
