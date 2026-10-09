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

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\Dso\DsoAttachmentFetcher;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCP\IUser;
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
 * Cron runs it without a user. It acts as the account that stored the
 * verzoek: the `actingUserId` it was queued with, or, for a job queued before
 * that existed, the account of the request's dso-stam consumer. Never as no
 * user, never under runAsSystem(). Without a usable account it writes nothing.
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#requirement-bijlagen-download-and-storage-req-dso-005
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-the-stam-intake-acts-as-the-dso-connections-account-req-dso-070
 */
class FetchDsoAttachmentsJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param DsoAttachmentFetcher $fetcher Downloads and stores the bijlagen.
	 * @param DsoConnection $connection Resolves the acting account and runs as it.
	 * @param DsoConnectionAlerts $alerts Tells the admins when no account is usable.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DsoAttachmentFetcher $fetcher,
		private readonly DsoConnection $connection,
		private readonly DsoConnectionAlerts $alerts,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Fetch the bijlagen of the request this job was queued for.
	 *
	 * @param mixed $argument The queued argument: `['requestUuid' => string, 'actingUserId' => string]`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#scenario-a-rerun-finishes-what-a-crash-left
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-3
	 */
	protected function run($argument): void {
		$requestUuid = '';
		$actingUserId = '';
		if (is_array($argument) === true) {
			$requestUuid = (string)($argument['requestUuid'] ?? '');
			$actingUserId = (string)($argument['actingUserId'] ?? '');
		}

		if ($requestUuid === '') {
			$this->logger->warning('[FetchDsoAttachmentsJob] dropped: no requestUuid in the argument');

			return;
		}

		$account = $this->resolveAccount(requestUuid: $requestUuid, actingUserId: $actingUserId);
		if ($account === null) {
			return;
		}

		try {
			$this->connection->runAs(
				$account,
				fn (): ?array => $this->fetcher->fetchPending(requestUuid: $requestUuid)
			);
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

	/**
	 * The account to act as, or null after logging and alerting why there is none.
	 *
	 * @param string $requestUuid  The dso_verzoek uuid, for the log.
	 * @param string $actingUserId The uid the job was queued with; empty for a legacy job.
	 *
	 * @return IUser|null The account.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-the-attachment-job-refuses-a-vanished-account
	 */
	private function resolveAccount(string $requestUuid, string $actingUserId): ?IUser {
		try {
			if ($actingUserId === '') {
				// Queued before the job carried its uid: the request's consumer
				// is the one dso-stam consumer, so its account is the one that
				// stored the request.
				$actingUserId = (string)($this->connection->findConsumer()?->getObject()['userId'] ?? '');
			}

			return $this->connection->resolveAccount(userId: $actingUserId);
		} catch (DsoConnectionUnavailableException $exception) {
			$this->logger->error(
				'[FetchDsoAttachmentsJob] bijlagen of verzoek ' . $requestUuid . ' not downloaded: account "'
				. $actingUserId . '" is not usable (' . $exception->getReason() . '); the entries stay pending',
				['verzoek' => $requestUuid, 'account' => $actingUserId, 'reason' => $exception->getReason()]
			);
			$this->alerts->notify(reason: DsoConnectionAlerts::REASON_JOB_ACCOUNT);

			return null;
		}

	}//end resolveAccount()
}//end class
