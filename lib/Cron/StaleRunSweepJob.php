<?php
/**
 * OpenConnector — close out synchronization runs whose process died.
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

use OCA\OpenConnector\Service\SynchronizationRunProgressService;
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
 * THE DISCRIMINATOR IS `updatedAt`, NOT `status`. A live run refreshes
 * `updatedAt` at least every {@see SynchronizationRunProgressService::THROTTLE_SECONDS}
 * seconds; an abandoned one stops. The stale threshold is therefore expressed as
 * a large MULTIPLE of the throttle, so a merely slow run — one stuck on a single
 * enormous object, or throttled by an upstream — is never mistaken for a dead
 * one.
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
		parent::__construct($time);
		// Every five minutes: frequent enough that a dead run is not shown as
		// live for long, cheap enough to be irrelevant — it reads only the
		// handful of records still marked `running`.
		$this->setInterval(300);
	}//end __construct()

	/**
	 * Sweep abandoned runs.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 */
	protected function run($argument): void {
		try {
			// findAll() takes ONE `$config` array — register and schema are
			// filters inside it, not named arguments. Passing them as named
			// arguments is a fatal TypeError, not a soft failure.
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
			$lastAt = ($last === null) ? null : strtotime((string)$last);

			// No timestamp we can trust — use the record's own created stamp
			// rather than either closing it blindly or leaving it for ever.
			if ($lastAt === false || $lastAt === null) {
				$lastAt = strtotime((string)($entity->getCreated() ?? 'now'));
			}

			if (($now - (int)$lastAt) < self::STALE_AFTER_SECONDS) {
				continue;
			}

			$object['status'] = 'failed';
			$object['finishedAt'] = (new \DateTime())->format('c');
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
}//end class
