<?php
/**
 * OpenConnector — deferred cascade delete of extended views (ADR-078).
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

use OCA\OpenConnector\Service\SourceMappingService;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Deletes the `extendview` objects that belonged to a deleted `view`.
 *
 * ADR-078: {@see \OCA\OpenConnector\EventListener\ViewDeletedEventListener}
 * used to run this cascade inside the user's delete request — one unbounded
 * `findAll()` plus one `delete()` per matching row, all before the delete
 * response was written. Nothing about the cascade can change the outcome of the
 * delete it observes, so it is deferred here and the request returns as soon as
 * the view itself is gone.
 *
 * THE ENTRY CARRIES EVERYTHING THIS JOB NEEDS, ON PURPOSE. The obvious shape
 * would be to re-resolve the deleted view through OpenRegister's
 * `DeferredEntryObjectResolver`, but that resolver returns null for a
 * soft-deleted object by design — a delete cascade re-resolved that way would
 * find nothing and report success, a flawless no-op. So the register id, schema
 * id and `identifier` are captured at dispatch time and travel with the entry.
 *
 * The job is a one-shot {@see ActorForwardedJob} (a `QueuedJob`), so it is
 * removed from the job list once it has run: it can never re-queue itself and
 * starve the rest of the cron queue behind it.
 */
class DeferredViewCascadeJob extends ActorForwardedJob {

	/**
	 * Maximum extended-view rows deleted per entry.
	 *
	 * @var int
	 */
	public const CASCADE_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time          The time factory.
	 * @param IUserSession        $userSession   Session used to re-establish the acting user.
	 * @param IUserManager        $userManager   Resolver for the captured user id.
	 * @param OrganisationService $organisation  Active-organisation resolver (drift logging).
	 * @param LoggerInterface     $logger        The logger.
	 * @param SourceMappingService $objectService Service providing access to the OR object layer.
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		LoggerInterface $logger,
		private readonly SourceMappingService $objectService,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Delete the extended views recorded in each entry.
	 *
	 * @param DeferredListenerContext $context The captured dispatch-time context.
	 *
	 * @return void
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		$openRegister = $this->objectService->getOpenRegisters();
		if ($openRegister === null) {
			// OpenRegister disabled between dispatch and this run. Nothing to
			// cascade onto, and inventing a fallback would be worse than saying
			// so — the entries are dropped and the reason is recorded.
			$this->logger->warning(
				'OpenConnector: extended-view cascade skipped, OpenRegister object service unavailable',
				['entries' => count($context->getEntries())]
			);
			return;
		}

		foreach ($context->getEntries() as $entry) {
			$this->cascadeOne(openRegister: $openRegister, entry: $entry);
		}
	}//end runDeferred()

	/**
	 * Delete every extended view matching one entry.
	 *
	 * @param \OCA\OpenRegister\Service\ObjectService $openRegister The OR object service.
	 * @param array<string, mixed>                    $entry        Entry carrying register, schema and identifier.
	 *
	 * @return void
	 */
	private function cascadeOne(\OCA\OpenRegister\Service\ObjectService $openRegister, array $entry): void {
		$identifier = ($entry['identifier'] ?? '');
		$register   = ($entry['register'] ?? null);
		$schema     = ($entry['schema'] ?? null);
		if (is_string($identifier) === false || $identifier === '' || $register === null || $schema === null) {
			return;
		}

		try {
			$extendedViews = $openRegister->findAll(
				[
					'filters' => [
						'register'   => $register,
						'schema'     => $schema,
						'identifier' => $identifier,
					],
					// Bounded on purpose (ADR-078 fix action 2). An `extendview`
					// set for one view identifier is a handful of rows; a result
					// at the cap means the data is not what this cascade assumes,
					// which is worth a warning rather than a silent full scan.
					'limit'   => self::CASCADE_LIMIT,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'OpenConnector: extended-view cascade lookup failed',
				['identifier' => $identifier, 'exception' => $e->getMessage()]
			);
			return;
		}

		if (count($extendedViews) >= self::CASCADE_LIMIT) {
			$this->logger->warning(
				'OpenConnector: extended-view cascade hit its row cap — some rows may remain',
				['identifier' => $identifier, 'limit' => self::CASCADE_LIMIT]
			);
		}

		$this->deleteRows(
			openRegister: $openRegister,
			rows: $extendedViews,
			identifier: $identifier,
			register: $register,
			schema: $schema
		);
	}//end cascadeOne()

	/**
	 * Delete one batch of extended-view rows, one row at a time.
	 *
	 * Extracted from cascadeOne() because the row loop's guards plus its
	 * per-row try/catch pushed that method through two phpmd ceilings at once
	 * (Cyclomatic 12/10, NPath 260/200) while the lookup half stayed clean —
	 * the same shape S2 recorded on the fleet board: a defensive try/catch is
	 * not complexity-free.
	 *
	 * Per-row containment is deliberate. Delivery is at-least-once, so one
	 * locked or already-removed row must not strand the rest of the batch.
	 *
	 * `$register` and `$schema` are `mixed` ON PURPOSE. They come out of a
	 * JSON-decoded `oc_jobs.argument`, and `DeferredListenerContext` is
	 * explicitly tolerant of a malformed payload so "a poisoned job row
	 * degrades to a logged no-op instead of a crash loop in cron". Declaring
	 * them `string|int` would move that failure to a TypeError on the way IN to
	 * this method, outside the per-row try/catch, and throw it out of the job.
	 * Left `mixed`, an unusable value fails at the `deleteObject()` call inside
	 * the catch and is logged per row, which is the documented behaviour.
	 *
	 * @param \OCA\OpenRegister\Service\ObjectService $openRegister The OR object service.
	 * @param array<int, mixed>                       $rows         Rows returned by findAll().
	 * @param string                                  $identifier   The view identifier, for logging.
	 * @param mixed                                   $register     Register the rows belong to.
	 * @param mixed                                   $schema       Schema the rows belong to.
	 *
	 * @return void
	 */
	private function deleteRows(
		\OCA\OpenRegister\Service\ObjectService $openRegister,
		array $rows,
		string $identifier,
		mixed $register,
		mixed $schema
	): void {
		foreach ($rows as $row) {
			$uuid = $this->deletableUuid(row: $row);
			if ($uuid === null) {
				continue;
			}

			try {
				// Use deleteObject(), NOT delete(). OpenRegister's ObjectService
				// has no delete() method and no __call(), so the previous
				// `$openRegister->delete($entity)` raised
				// `Error: Call to undefined method` on EVERY row — and `Error`
				// implements \Throwable, so the catch below turned the whole
				// cascade into a logged no-op that reported success. Verified
				// against openregister origin/development: the only delete
				// entry points are deleteObject/deleteObjects/
				// deleteObjectsBySchema/deleteObjectsByRegister.
				$openRegister->deleteObject(uuid: $uuid, register: $register, schema: $schema);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'OpenConnector: failed to delete an extended view during cascade',
					['identifier' => $identifier, 'exception' => $e->getMessage()]
				);
			}
		}
	}//end deleteRows()

	/**
	 * The uuid a findAll() row can be deleted by, or null if it has none.
	 *
	 * `findAll()` returns rendered ObjectEntity rows. Anything else is not
	 * something this cascade can address, and passing it on would only surface
	 * as a swallowed TypeError.
	 *
	 * @param mixed $row One row as returned by findAll().
	 *
	 * @return string|null The uuid, or null when the row cannot be addressed.
	 */
	private function deletableUuid(mixed $row): ?string {
		if (($row instanceof ObjectEntity) === false) {
			return null;
		}

		$uuid = $row->getUuid();
		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		return $uuid;
	}//end deletableUuid()
}//end class
