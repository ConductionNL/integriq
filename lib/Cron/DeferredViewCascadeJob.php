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

		foreach ($extendedViews as $extendedView) {
			try {
				$openRegister->delete($extendedView);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'OpenConnector: failed to delete an extended view during cascade',
					['identifier' => $identifier, 'exception' => $e->getMessage()]
				);
			}
		}
	}//end cascadeOne()
}//end class
