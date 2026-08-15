<?php
/**
 * OpenConnector — deferred file fetching for one synchronised object.
 *
 * @category Cron
 * @package  OCA\OpenConnector\Cron
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/openconnector
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Cron;

use OCA\OpenConnector\Service\SynchronizationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Fetches one object's files OUTSIDE the synchronization request.
 *
 * WHY THIS EXISTS. `startAsyncFileFetching()` is not async — it calls
 * `executeAsyncFileFetching()` inline, so "fire-and-forget" there means *errors
 * are swallowed*, not *work is deferred*. Every download and every save happens
 * while the caller's HTTP request is still open, which is why files more than
 * DOUBLE a sync's wall clock: measured 7,507 ms with no files against 17,256 ms
 * with 40, even with the fetch window fully parallel.
 *
 * A source that opts into `fileFetchMode: async` enqueues one of these per
 * object instead. The synchronization returns as soon as its objects are
 * written; the files arrive afterwards, on the cron worker.
 *
 * A QueuedJob, not a TimedJob: it runs once and removes itself. Every other job
 * in this app is a TimedJob because they are recurring sweeps — this one is a
 * unit of deferred work, which is exactly what QueuedJob is for.
 *
 * ⚠️ THE FILES ARE NOT THERE YET WHEN THE SYNC FINISHES. Anything that reasons
 * about a missing file — most importantly a deletion sweep — must consult
 * `filesPending` on the run record first. "Not fetched yet" and "no longer in
 * the source" are different facts that look identical from the object.
 *
 * ⚠️ A background job that never runs is indistinguishable from one that
 * succeeded. Verified on this instance before relying on it:
 * `backgroundjobs_mode=cron`, with `oc_jobs.last_run` one minute old.
 */
class FetchFilesJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory           $time                   The time factory.
	 * @param SynchronizationService $synchronizationService The engine that owns the fetch path.
	 * @param LoggerInterface        $logger                 The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SynchronizationService $synchronizationService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}//end __construct()

	/**
	 * Fetch the files this job was queued for.
	 *
	 * @param mixed $argument The queued arguments: config, endpoint, objectId, ruleId.
	 *
	 * @return void
	 */
	protected function run($argument): void {
		if (is_array($argument) === false) {
			$this->logger->warning('[FetchFilesJob] dropped: argument was not an array');

			return;
		}

		$config = ($argument['config'] ?? null);
		$endpoint = ($argument['endpoint'] ?? null);
		$objectId = ($argument['objectId'] ?? null);

		if (is_array($config) === false || $endpoint === null || $objectId === null) {
			$this->logger->warning(
				'[FetchFilesJob] dropped: incomplete arguments (config/endpoint/objectId required)'
			);

			return;
		}

		try {
			$this->synchronizationService->fetchFilesForObject(
				config: $config,
				endpoint: $endpoint,
				objectId: (string)$objectId,
				ruleId: (int)($argument['ruleId'] ?? 0)
			);
		} catch (\Throwable $exception) {
			// A deferred fetch that throws must not take the worker down with
			// it; the next job in the queue is someone else's object.
			$this->logger->error(
				'[FetchFilesJob] deferred file fetch failed for object ' . $objectId . ': '
				. $exception->getMessage(),
				['exception' => $exception]
			);
		}//end try
	}//end run()
}//end class
