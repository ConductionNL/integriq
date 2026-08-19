<?php
/**
 * OpenConnector — live progress for a synchronization run.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/openconnector
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use DateTime;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;

/**
 * Records how far a synchronization run has got, while it is still running.
 *
 * THE PROBLEM. A run was invisible: `synchronization.status` is null on every
 * synchronization, no `execution_trace` row is `running` mid-flight, and
 * `POST /synchronizations/{id}/run` blocks for the whole run — 74.6 s measured
 * for 12 objects with 6 files each. `synchronization_log` cannot help: it is
 * built in memory, persisted once at the end, and its schema is `appendOnly`
 * and `immutable`, so an update is refused outright.
 *
 * THE CONSTRAINT. Progress that slows the run defeats itself. Persisting
 * `currentPage` once per page was a measurable part of the 315.9 ms/page the
 * engine started this programme at, so a record written per object is not an
 * option. Two things keep the cost flat:
 *
 *  1. **A time throttle, not a count throttle.** At most one write per
 *     {@see self::THROTTLE_SECONDS}. A per-N-objects rule writes 200x more on a
 *     20,000-object corpus than on a 100-object one; a time rule writes the same
 *     amount either way, which is the property that makes this safe on the
 *     corpora that matter.
 *  2. **A constant-size record.** Every field is a scalar, so the write cost
 *     does not grow with the run — the reason `execution_trace` was rejected as
 *     the vehicle despite already having a `running` status the UI renders.
 *
 * Saves use the cheap path (`_rbac`, `_multitenancy`, `_validation` off,
 * `silent` on) — the same flags that took a CallLog save from ~117 ms to a
 * fraction of it. A progress row needs none of that machinery.
 *
 * BEST EFFORT, BUT NOT SILENT. A failure to record progress must never fail the
 * run it describes, so every write is guarded. The guard would otherwise create
 * the exact defect this session kept finding — a recorder that quietly stopped
 * working and still looked healthy — so failures are counted in
 * `progressWriteFailures` and reported on the final record.
 */
class SynchronizationRunProgressService {

	/**
	 * The register progress records live in.
	 *
	 * @var string
	 */
	public const REGISTER = 'openconnector';

	/**
	 * The schema progress records live in.
	 *
	 * @var string
	 */
	public const SCHEMA = 'synchronization_run';

	/**
	 * Minimum seconds between two progress writes.
	 *
	 * Deliberately time-based. Two seconds keeps a watching UI responsive while
	 * bounding the writes to ~30/minute no matter how many objects a run moves.
	 *
	 * @var float
	 */
	public const THROTTLE_SECONDS = 2.0;

	/**
	 * The run record's uuid, once started; null when progress is not being
	 * recorded for this run.
	 *
	 * @var string|null
	 */
	private ?string $runUuid = null;

	/**
	 * Monotonic timestamp of the last write, for the throttle.
	 *
	 * @var float
	 */
	private float $lastWrite = 0.0;

	/**
	 * Counters, mirrored here so a throttled write never has to read the row
	 * back before writing it.
	 *
	 * @var array<string, mixed>
	 */
	private array $counters = [];

	/**
	 * How many writes failed and were swallowed.
	 *
	 * @var int
	 */
	private int $failures = 0;

	/**
	 * How many writes were actually issued. Exposed for the control that decides
	 * whether the throttle works: a throttle that silently never fires would
	 * show zero overhead and read as a pass.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * Total milliseconds spent inside progress writes this run.
	 *
	 * THE FEATURE MEASURES ITS OWN COST. "Does this slow the sync?" is the
	 * question that decides whether run-progress should exist at all, and
	 * differencing two whole runs could not answer it on a loaded instance —
	 * the same 60-object corpus varied between 63 s and 103 s, a spread that
	 * swallows any few-percent effect. Recording the time actually spent
	 * writing, on the record itself, answers it exactly and keeps answering it
	 * in production rather than once on a bench.
	 *
	 * @var float
	 */
	private float $writeMillis = 0.0;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService The OpenRegister object service.
	 * @param LoggerInterface $logger        The logger.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Open a run record with status `running`, before any work starts.
	 *
	 * This is the one write that has to happen: without it the run is invisible
	 * for its entire duration, which is the defect being fixed. It is still
	 * guarded — an unavailable register must not stop a synchronization.
	 *
	 * ⚠️ THE OPT-OUT IS THE CALLER'S, AND DELIBERATELY SO. This used to take an
	 * `$enabled` flag and return early on false — a boolean argument whose only
	 * job is to switch the method off, which is the shape PHPMD's
	 * BooleanArgumentFlag names. Not calling a method is a clearer way to not
	 * call a method. Behaviour is unchanged: with no `start()` there is no
	 * `runUuid`, and `tick()`/`finish()` both already no-op on a null one, so
	 * the whole run stays unrecorded exactly as before.
	 *
	 * @param string $synchronizationId The synchronization being run.
	 *
	 * @return void
	 */
	public function start(string $synchronizationId): void {
		$now = (new DateTime())->format('c');

		$this->counters = [
			'synchronizationId' => $synchronizationId,
			'status' => 'running',
			'startedAt' => $now,
			'updatedAt' => $now,
			'found' => 0,
			'processed' => 0,
			'created' => 0,
			'updated' => 0,
			'deleted' => 0,
			'invalid' => 0,
			'currentPage' => 0,
			'filesPending' => 0,
			'progressWriteFailures' => 0,
		];

		$saved = $this->write(object: $this->counters);
		if ($saved !== null) {
			$this->runUuid = $saved;
			// Count the opening write against the throttle so a run whose first
			// page is fast does not immediately write twice.
			$this->lastWrite = microtime(true);
		}
	}//end start()

	/**
	 * Record progress, subject to the throttle.
	 *
	 * Call this as often as is convenient — per page, per object, per batch. The
	 * throttle decides what actually reaches the database, so callers do not
	 * have to reason about write cost at each site.
	 *
	 * @param array $counters Partial counters to merge into the record.
	 *
	 * @return void
	 */
	public function tick(array $counters = []): void {
		if ($this->runUuid === null) {
			return;
		}

		$this->counters = array_merge($this->counters, $counters);

		$elapsed = (microtime(true) - $this->lastWrite);
		if ($elapsed < self::THROTTLE_SECONDS) {
			return;
		}

		$this->counters['updatedAt'] = (new DateTime())->format('c');
		$this->write(object: $this->counters, uuid: $this->runUuid);
		$this->lastWrite = microtime(true);
	}//end tick()

	/**
	 * Close the run with a terminal status. NOT throttled — the final state must
	 * always be written, or a finished run stays `running` forever and every
	 * watcher reads it as hung.
	 *
	 * @param string      $status  One of `success` or `failed`.
	 * @param array       $counters Final counters to merge.
	 * @param string|null $message Terminal message, if any.
	 *
	 * @return void
	 */
	public function finish(string $status, array $counters = [], ?string $message = null): void {
		if ($this->runUuid === null) {
			return;
		}

		$this->counters = array_merge($this->counters, $counters);
		$this->counters['status'] = $status;
		$this->counters['finishedAt'] = (new DateTime())->format('c');
		$this->counters['updatedAt'] = $this->counters['finishedAt'];
		$this->counters['progressWriteFailures'] = $this->failures;
		$this->counters['progressWrites'] = $this->writes;
		$this->counters['progressWriteMillis'] = (int)round($this->writeMillis);

		if ($message !== null) {
			$this->counters['message'] = $message;
		}

		$this->write(object: $this->counters, uuid: $this->runUuid);
		$this->runUuid = null;
	}//end finish()

	/**
	 * How many progress writes were actually issued this run.
	 *
	 * The overhead control needs this: a throttle that never fired would show no
	 * overhead at all and look exactly like a throttle that works.
	 *
	 * @return int The number of issued writes.
	 */
	public function writeCount(): int {
		return $this->writes;
	}//end writeCount()

	/**
	 * Persist the record on the cheap save path.
	 *
	 * @param array       $object The record.
	 * @param string|null $uuid   The record's uuid on update; null creates it.
	 *
	 * @return string|null The record's uuid, or null when the write failed.
	 */
	private function write(array $object, ?string $uuid = null): ?string {
		$started = microtime(true);

		try {
			$this->writes++;

			$saved = $this->objectService->saveObject(
				object: $object,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
				silent: true,
				_validation: false,
			);

			$this->writeMillis += ((microtime(true) - $started) * 1000);

			return $saved->getUuid();
		} catch (\Throwable $exception) {
			// SWALLOWED, BUT COUNTED. Progress must never fail a run; a recorder
			// that quietly stopped working must not read as a healthy one.
			$this->failures++;
			$this->logger->warning(
				'[SynchronizationRunProgressService] progress write failed (run continues): '
				. $exception->getMessage()
			);

			return null;
		}//end try
	}//end write()
}//end class
