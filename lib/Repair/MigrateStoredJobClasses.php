<?php

/**
 * Integriq Migrate Stored Job Classes Repair Step.
 *
 * Repair step that rewrites the PHP class names STORED INSIDE job objects
 * across the `openconnector` -> `integriq` app-id rename.
 *
 * WHY THIS EXISTS. A job object carries a `jobClass` property whose value is a
 * fully-qualified PHP class name, and {@see \OCA\Integriq\Service\JobService}
 * resolves it at run time:
 *
 *     $action = $this->containerInterface->get($jobData['jobClass']);
 *
 * That is a RUNTIME LOOKUP against a string that was written into the database
 * long before this rename. Renaming the PHP namespace does not rewrite those
 * rows, so after the rename every stored job asks the container for
 * `OCA\OpenConnector\Action\SynchronizationAction` — a class that no longer
 * exists. This is the same failure shape as the appconfig/preferences rename
 * handled by {@see MigrateAppConfigKeys} and {@see MigrateUserPreferences},
 * one layer out: the store is OpenRegister objects rather than `oc_appconfig`.
 *
 * WHY IT FAILS SILENTLY, WHICH IS WHY IT NEEDS A MIGRATION AND NOT A RELEASE
 * NOTE. The container throw is not caught where it would be logged:
 *   - in `JobService::executeJob()` the `get()` call sits OUTSIDE the `try`
 *     that writes a `job_log`, so no job_log row is written for it;
 *   - in `JobService::run()` (the cron entry point) the per-job isolation
 *     `catch (\Throwable $e)` does `unset($e); continue;` — it DISCARDS the
 *     exception without logging, by design, because it assumes executeJob()
 *     has already logged. For this particular failure that assumption does not
 *     hold.
 * The net effect is that every synchronization stops running while the cron
 * pass reports success and leaves no evidence anywhere. Nobody gets a stack
 * trace saying "you renamed a namespace".
 *
 * SCOPE — MEASURED, NOT ASSUMED. `jobClass` on the `job` schema is the only
 * persisted class reference in this app:
 *   - `adapterClass` in `lib/sources.seed.json` looks like a second one but is
 *     NOT persisted or resolved: that file has zero PHP references anywhere in
 *     `lib/` (a fact this repo already documents in
 *     `lib/Settings/register.d/environments-and-promotion.json`), and
 *     `adapterClass` is not declared on any schema in the register. It is an
 *     orphaned seed file, so there is nothing stored to repair.
 *   - Nextcloud's own `oc_jobs` rows for this app's `<background-jobs>` also
 *     hold class names, but that table is the SERVER's, not this app's, and is
 *     deliberately left to Nextcloud — see the note in the class body.
 *
 * SAFETY. Matches the two sibling rename steps:
 *   - idempotent: a value that already carries the new prefix is skipped, so a
 *     second run is a no-op;
 *   - conservative: ONLY the exact `OCA\OpenConnector\` prefix is rewritten.
 *     Any other value — a third-party class, an already-migrated one, a typo,
 *     an empty string — is left exactly as it is rather than guessed at;
 *   - per-object isolation: one unreadable or unwritable job does not abort the
 *     loop, and every read AND write sits inside the `try`, because this step
 *     also runs under `<install>` where a throwing repair step means the app
 *     never enables and every route goes with it;
 *   - enumeration is EXPLICITLY PAGED. `ObjectService::findAll()` is
 *     server-paged; taking its default page and calling that "all jobs" would
 *     migrate the first page, leave the rest broken, and report success.
 *
 * RBAC. `occ maintenance:repair` / `occ upgrade` / a fresh `occ app:enable`
 * run with no user session, i.e. as the Anonymous principal, which OpenRegister
 * denies object writes to. The whole pass therefore runs inside
 * `SystemOperationContext` — the same escape hatch OpenRegister's own
 * `ConfigurationService::importFromApp()` uses for seed writes, and the one
 * {@see MaterializeCatalogItems} already relies on for exactly this reason.
 *
 * Registered in BOTH `<install>` and `<post-migration>` in `appinfo/info.xml`,
 * placed AFTER `InitializeRegister` so the register and its schemas are
 * guaranteed to exist. On a first install there are no stored jobs, so it is a
 * no-op there.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrite stored `jobClass` values from the openconnector namespace to integriq.
 */
class MigrateStoredJobClasses implements IRepairStep {
	/**
	 * The PHP namespace prefix this app's classes used before the rename.
	 *
	 * Deliberately the OLD namespace — this is the value being searched for in
	 * stored data, not a reference to a class that still exists.
	 *
	 * @var string
	 */
	private const OLD_CLASS_PREFIX = 'OCA\\OpenConnector\\';

	/**
	 * The PHP namespace prefix this app's classes use after the rename.
	 *
	 * @var string
	 */
	private const NEW_CLASS_PREFIX = 'OCA\\Integriq\\';

	/**
	 * The OpenRegister register holding this app's objects.
	 *
	 * FROZEN on the old name on purpose: the register SLUG does not move with
	 * the app id. OpenRegister matches registers by slug, so renaming it would
	 * create a fresh EMPTY register while every existing object stayed behind,
	 * orphaned and silently invisible.
	 *
	 * @var string
	 */
	private const REGISTER = 'openconnector';

	/**
	 * The schema whose objects carry a stored PHP class name.
	 *
	 * @var string
	 */
	private const SCHEMA = 'job';

	/**
	 * The property on that schema holding the stored PHP class name.
	 *
	 * @var string
	 */
	private const CLASS_PROPERTY = 'jobClass';

	/**
	 * How many objects to request per page while enumerating.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Hard cap on pages walked, so a backend that ignores `offset` cannot spin
	 * this step forever during an install.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 1000;

	/**
	 * Constructor for MigrateStoredJobClasses.
	 *
	 * @param ContainerInterface $container The container used to resolve the OR object service
	 * @param LoggerInterface    $logger    Logger for objects that fail to migrate
	 *
	 * @return void
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude One-off openconnector->integriq app-id rename plumbing; it
	 *       returns a human-readable label for `occ maintenance:repair` output
	 *       and adds no behaviour of its own.
	 */
	public function getName(): string {
		return 'Rewrite stored Integriq jobClass values from the openconnector namespace';
	}//end getName()

	/**
	 * Rewrite every stored jobClass that still names the old PHP namespace.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec exclude One-off openconnector->integriq app-id rename plumbing: it
	 *       rewrites a stored class-name string in place and adds no behaviour
	 *       of its own. What a job DOES is specified where it is executed.
	 */
	public function run(IOutput $output): void {
		if (class_exists('\\OCA\\OpenRegister\\Service\\ObjectService') === false) {
			$output->info('Integriq: OpenRegister ObjectService not available, skipping stored jobClass migration.');
			return;
		}

		try {
			$orObjectService = $this->container->get(OrObjectService::class);
		} catch (Throwable $e) {
			$output->warning(
				'MigrateStoredJobClasses: could not resolve the OpenRegister object service; '
				. 'stored jobClass values were NOT migrated.'
			);
			$this->logger->error(
				'Integriq: MigrateStoredJobClasses service resolution failed',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$migrate = function () use ($orObjectService, $output): array {
			return $this->migrateAll(orObjectService: $orObjectService, output: $output);
		};

		if (class_exists('\\OCA\\OpenRegister\\Service\\SystemOperationContext') === true) {
			$counts = \OCA\OpenRegister\Service\SystemOperationContext::run($migrate);
		} else {
			$counts = $migrate();
		}

		$migrated = (int)($counts['migrated'] ?? 0);
		$failed = (int)($counts['failed'] ?? 0);

		if ($migrated === 0 && $failed === 0) {
			$output->info('MigrateStoredJobClasses: no stored jobClass still names the openconnector namespace.');
			return;
		}

		$output->info('MigrateStoredJobClasses: rewrote ' . $migrated . ' stored jobClass value(s).');

		if ($failed > 0) {
			// Surfaced loudly: a job left on the old class name does not error
			// at run time, it silently stops executing (see the class docblock).
			$output->warning(
				'MigrateStoredJobClasses: ' . $failed . ' job(s) could NOT be rewritten and still name the '
				. 'openconnector namespace. Those jobs will not run until their jobClass is corrected.'
			);
		}
	}//end run()

	/**
	 * Walk every stored job, page by page, rewriting stale class names.
	 *
	 * @param OrObjectService $orObjectService The OR object service
	 * @param IOutput         $output          The repair output channel
	 *
	 * @return array{migrated:int,failed:int} Counts for the caller's summary
	 */
	private function migrateAll(OrObjectService $orObjectService, IOutput $output): array {
		$migrated = 0;
		$failed = 0;
		$offset = 0;

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			try {
				$result = $orObjectService->findAll(
					config: [
						'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA],
						'limit' => self::PAGE_SIZE,
						'offset' => $offset,
					]
				);
			} catch (Throwable $e) {
				$output->warning(
					'MigrateStoredJobClasses: could not enumerate stored jobs at offset ' . $offset
					. '; stopping. Some jobClass values may still name the openconnector namespace.'
				);
				$this->logger->error(
					'Integriq: MigrateStoredJobClasses enumeration failed',
					['offset' => $offset, 'exception' => $e->getMessage()]
				);
				break;
			}//end try

			$items = ($result['results'] ?? $result);
			if (is_array($items) === false || count($items) === 0) {
				break;
			}

			foreach ($items as $item) {
				$outcome = $this->migrateOne(item: $item, orObjectService: $orObjectService);
				if ($outcome === true) {
					$migrated++;
				} elseif ($outcome === false) {
					$failed++;
				}
			}

			// A short page is the last page. Guarding on the page size rather
			// than on a reported total keeps this correct even if the backend
			// omits `total`.
			if (count($items) < self::PAGE_SIZE) {
				break;
			}

			$offset += self::PAGE_SIZE;
		}//end for

		return ['migrated' => $migrated, 'failed' => $failed];
	}//end migrateAll()

	/**
	 * Rewrite one job object's stored class name when it names the old namespace.
	 *
	 * @param mixed           $item            The object returned by findAll
	 * @param OrObjectService $orObjectService The OR object service
	 *
	 * @return bool|null True when rewritten, false when the rewrite failed,
	 *                   null when nothing needed doing
	 */
	private function migrateOne(mixed $item, OrObjectService $orObjectService): ?bool {
		if ($item instanceof ObjectEntity === false) {
			return null;
		}

		try {
			$data = $item->getObject();
			if (is_array($data) === false) {
				return null;
			}

			$current = ($data[self::CLASS_PROPERTY] ?? null);
			if (is_string($current) === false || $current === '') {
				return null;
			}

			// Only the exact old prefix is touched. An already-migrated value,
			// a third-party class or anything unrecognised is left alone
			// rather than guessed at.
			if (str_starts_with($current, self::OLD_CLASS_PREFIX) === false) {
				return null;
			}

			$data[self::CLASS_PROPERTY] = self::NEW_CLASS_PREFIX
				. substr($current, strlen(self::OLD_CLASS_PREFIX));

			$orObjectService->saveObject(
				object: $data,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $item->getUuid()
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Integriq: could not rewrite a stored jobClass; the job will not run until it is corrected',
				['uuid' => $item->getUuid(), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end migrateOne()
}//end class
