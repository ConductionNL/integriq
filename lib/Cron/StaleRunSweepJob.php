<?php
/**
 * Integriq — close out synchronization runs whose process died.
 *
 * @category Cron
 * @package  OCA\Integriq\Cron
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/integriq
 */

declare(strict_types=1);

namespace OCA\Integriq\Cron;

use DateTime;
use OCA\Integriq\Service\SynchronizationRunProgressService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Marks abandoned `running` synchronization runs as `failed`.
 *
 * A run record is opened with `running` and closed by the engine at the end. If
 * the process dies in between — a killed worker, a PHP fatal, a request timeout,
 * a container restart — nothing ever closes it, and the record says `running`
 * for ever. Observed immediately: 7 of the first 16 records on this instance
 * were stuck that way after runs were interrupted.
 *
 * That is worse than no record at all. A permanently-`running` row is
 * indistinguishable from a genuinely live one, so the page built to answer "is
 * my sync still going?" answers it wrongly — the same class of defect as a rule
 * that logs success while writing nothing.
 *
 * THE DISCRIMINATOR IS `updatedAt`, NOT `status`. A run that keeps calling
 * {@see SynchronizationRunProgressService::tick()} refreshes `updatedAt`; an
 * abandoned one stops. The stale threshold is therefore expressed as a large
 * multiple of {@see SynchronizationRunProgressService::THROTTLE_SECONDS}, so a
 * run that is merely slow BETWEEN units of work is not mistaken for a dead one.
 *
 * ⚠️ That is write recency, NOT liveness, and the difference is load-bearing.
 * `THROTTLE_SECONDS` is a CEILING on write frequency, not a floor on refresh:
 * `tick()` is caller-driven and returns early when less than two seconds have
 * elapsed, but nothing advances `updatedAt` while no call is made. A run blocked
 * INSIDE a single unit of work — one enormous object, a hung upstream call —
 * therefore stops advancing `updatedAt` and will be closed as `failed` after
 * {@see self::STALE_AFTER_SECONDS}, while its process is still alive. An earlier
 * version of this comment claimed such a run "is never mistaken for a dead one";
 * that is only true while the engine keeps ticking. See the spec's REQ-006 notes.
 *
 * @spec openspec/specs/job-scheduling/spec.md#requirement-abandoned-synchronization-runs-are-swept-to-a-terminal-state-req-006
 */
class StaleRunSweepJob extends TimedJob {

	/**
	 * How long a run may go without recording progress before it is presumed
	 * dead, in seconds.
	 *
	 * 30 minutes: hundreds of times the 2-second progress throttle, so this can
	 * only fire on a run that has genuinely stopped writing rather than one that
	 * is merely slow. Closing a live run's record early would be worse than
	 * leaving a dead one open.
	 *
	 * @var int
	 */
	public const STALE_AFTER_SECONDS = 1800;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time          The time factory.
	 * @param OrObjectService $objectService The OpenRegister object service.
	 * @param LoggerInterface $logger        The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly OrObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Every five minutes: frequent enough that a dead run is not shown as
		// live for long, cheap enough to be irrelevant — it reads only the
		// handful of records still marked `running`.
		$this->setInterval(seconds: 300);
	}//end __construct()

	/**
	 * Sweep abandoned runs.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/job-scheduling/spec.md#requirement-abandoned-synchronization-runs-are-swept-to-a-terminal-state-req-006
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$argument` is Nextcloud's own
	 *   `TimedJob::run()` signature (OCP\BackgroundJob\Job::run($argument)); it is
	 *   the job-argument slot, and this job takes none. Dropping the parameter
	 *   would not satisfy the parent's contract, so the only honest options are
	 *   this annotation or a fake use of the value.
	 */
	protected function run($argument): void {
		try {
			// The findAll() method takes ONE `$config` array — register and
			// schema are filters inside it, not named arguments. Passing them
			// as named arguments is a fatal TypeError, not a soft failure.
			$result = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => SynchronizationRunProgressService::REGISTER,
						'schema' => SynchronizationRunProgressService::SCHEMA,
						'status' => 'running',
					],
				],
				_rbac: false,
				_multitenancy: false,
			);
			$running = ($result['results'] ?? $result);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				'[StaleRunSweepJob] could not read running synchronization runs: ' . $exception->getMessage()
			);

			return;
		}

		$now = time();
		$closed = 0;

		foreach ($running as $entity) {
			$object = $entity->getObject();

			// Fall back to startedAt: a run killed before its first tick has no
			// updatedAt at all, and those are exactly the ones that hang around.
			$last = ($object['updatedAt'] ?? $object['startedAt'] ?? null);
			$lastAt = null;
			if ($last !== null) {
				$lastAt = $this->toTimestamp(value: $last);
			}

			// No timestamp we can trust — use the record's own created stamp
			// rather than either closing it blindly or leaving it for ever.
			if ($lastAt === null) {
				$lastAt = $this->toTimestamp(value: $entity->getCreated());
			}

			// Still nothing usable: leave it alone and say so. Closing a record
			// whose age cannot be established would be guessing.
			if ($lastAt === null) {
				$this->logger->warning(
					'[StaleRunSweepJob] run ' . $entity->getUuid()
					. ' has no usable timestamp; not closing it.'
				);
				continue;
			}

			if (($now - (int)$lastAt) < self::STALE_AFTER_SECONDS) {
				continue;
			}

			$object['status'] = 'failed';
			$object['finishedAt'] = (new DateTime())->format('c');
			$object['message'] = 'Abandoned: no progress recorded for over '
				. (int)(self::STALE_AFTER_SECONDS / 60) . ' minutes; the run\'s process is presumed dead. '
				. 'This record was closed by StaleRunSweepJob, NOT by the run itself, so its counters are '
				. 'the last ones observed rather than a final tally.';

			try {
				$this->objectService->saveObject(
					object: $object,
					register: SynchronizationRunProgressService::REGISTER,
					schema: SynchronizationRunProgressService::SCHEMA,
					uuid: $entity->getUuid(),
					_rbac: false,
					_multitenancy: false,
					silent: true,
					_validation: false,
				);
				$closed++;
			} catch (\Throwable $exception) {
				$this->logger->warning(
					'[StaleRunSweepJob] could not close abandoned run ' . $entity->getUuid()
					. ': ' . $exception->getMessage()
				);
			}//end try
		}//end foreach

		if ($closed > 0) {
			$this->logger->info('[StaleRunSweepJob] closed ' . $closed . ' abandoned synchronization run(s).');
		}
	}//end run()

	/**
	 * Coerce a stored timestamp to a unix time, whatever shape it arrives in.
	 *
	 * ⚠️ `ObjectEntity::getCreated()` returns a **DateTime**, and the first
	 * version of this job did `strtotime((string) $entity->getCreated())`. That
	 * is a FATAL — "Object of class DateTime could not be converted to string" —
	 * and it aborted the whole sweep: the job closed the first record it saw
	 * (which happened to carry a valid timestamp), then hit one with a null
	 * `updatedAt`, died, and never reached the remaining seven. From outside it
	 * looked like two separate bugs — a broken null-fallback AND records with
	 * good timestamps being skipped — when it was one crash partway through a
	 * loop.
	 *
	 * @param mixed $value A DateTimeInterface, a parseable string, or anything else.
	 *
	 * @return int|null The unix timestamp, or null when nothing usable was given.
	 */
	private function toTimestamp(mixed $value): ?int {
		if ($value instanceof \DateTimeInterface) {
			return $value->getTimestamp();
		}

		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && $value !== '') {
			$parsed = strtotime($value);
			if ($parsed !== false) {
				return $parsed;
			}
		}

		return null;
	}//end toTimestamp()
}//end class
