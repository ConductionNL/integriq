<?php

/**
 * OpenConnector — migrate inline source secrets, then record the Phase D gate
 * (Phase C / ADR-064; ocon#151).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE EXECUTOR IS NOW UNBLOCKED — this step RUNS the migration.
 * ─────────────────────────────────────────────────────────────────────────────
 * This step originally only PLANNED: at the time an organisation-scoped `source`
 * credential (ADR-064 Rule 4) could not be resolved without a live user session,
 * so a sessionless upgrade could not perform the mandatory verify step and the
 * safe thing was to REPORT only. openregister#450 (the sessionless
 * `actingOrganisationId` assertion on `resolveInjectable()`) and the `_rbac: false`
 * sessionless `mint()` (openregister#440) removed that blocker, so this step now
 * EXECUTES {@see \OCA\OpenConnector\Service\Security\InlineSecretMigrationExecutor}
 * — for every `source` it mints a broker credential, VERIFIES the secret
 * round-trips, and only then writes the `{credentialRef}` placeholder and nulls
 * the inline value — and THEN records the status.
 *
 * The execution is fail-closed and NON-FATAL: a source with no organisation, an
 * old/absent broker, a mint/verify/save failure — each leaves the inline value
 * COMPLETELY INTACT (the source keeps working) and keeps the gate closed. A total
 * executor failure (e.g. the broker cannot be resolved) is caught and swallowed so
 * it can never brick an `occ upgrade`; the recording pass then reflects the true,
 * still-dirty state.
 *
 * After execution it runs the {@see InlineSecretMigrationPlanner} and persists the
 * answer to one question into appconfig:
 *
 *     "Do any `source` objects still hold an inline secret?"
 *
 * That persisted flag is one signal Phase D
 * ({@see \OCA\OpenConnector\Repair\RemoveMigratedSourceSecretFields}) observes.
 * Phase D itself re-derives its OWN four-field gate from a fresh raw scan (it does
 * not trust this flag alone), and it EXCLUDES `authenticationConfig` (manual
 * review) — so an unmigrated auth-config keeps THIS flag `'0'` but does not block
 * removal of the four auto-migratable fields.
 *
 * Idempotent (already-migrated sources are skipped from a fresh raw read), never
 * fatal, and a no-op when OpenRegister is absent. No secret value is ever logged
 * or persisted — only counts, field names and provider ids.
 *
 * @category Repair
 * @package  OCA\OpenConnector\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Repair;

use OCA\OpenConnector\Service\Security\InlineSecretMigrationExecutor;
use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs the inline-secret migration, then persists the Phase D gate to appconfig.
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-gate-signal
 */
class RecordInlineSecretMigrationStatus implements IRepairStep {

	/**
	 * FQCN of OpenRegister's ObjectService — resolved lazily so OpenConnector
	 * still boots (and this step is a clean no-op) without OpenRegister.
	 *
	 * @var string
	 */
	private const OR_OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Appconfig app id.
	 *
	 * @var string
	 */
	private const APP_ID = 'openconnector';

	/**
	 * Appconfig key: '1' when NO source holds an unmigrated inline secret.
	 * Phase D's gate — it may remove the schema properties only when this is '1'.
	 *
	 * @var string
	 */
	public const KEY_CLEAN = 'inline_secrets_clean';

	/**
	 * Appconfig key: the count of inline secret fields still awaiting migration
	 * (auto-mappable). Diagnostic; not the gate.
	 *
	 * @var string
	 */
	public const KEY_PENDING = 'inline_secrets_pending';

	/**
	 * Appconfig key: the count of inline secret fields needing manual review
	 * (e.g. `authenticationConfig`). Also blocks Phase D.
	 *
	 * @var string
	 */
	public const KEY_MANUAL = 'inline_secrets_manual_review';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (to resolve OR lazily).
	 * @param IAppConfig $appConfig The appconfig store for the gate signal.
	 * @param LoggerInterface $logger Secret-free logging only.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude Framework IRepairStep metadata accessor; no domain behavior.
	 */
	public function getName(): string {
		return 'Migrate OpenConnector source inline secrets to the broker, then record the Phase D gate';
	}//end getName()

	/**
	 * Run the migration (mint → verify → null), then compute and persist the gate.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-gate-signal
	 */
	public function run(IOutput $output): void {
		if (class_exists('\\' . self::OR_OBJECT_SERVICE) === false) {
			$output->info('OpenConnector: OpenRegister not available; skipping inline-secret migration and status check.');
			return;
		}

		try {
			$objectService = $this->container->get(self::OR_OBJECT_SERVICE);
			$planner = new InlineSecretMigrationPlanner(
				objectService: $objectService,
				logger: $this->logger
			);

			// PHASE C EXECUTION (now unblocked): mint → verify → write ref → null.
			// Non-fatal and fail-closed — a failure here leaves every inline secret
			// intact and the recording pass below then reflects the still-dirty state.
			$this->runMigration(objectService: $objectService, planner: $planner, output: $output);

			$plan = $planner->planAll();

			$clean = (bool)$plan['clean'];
			$pending = (int)$plan['wouldMigrate'];
			$manual = (int)$plan['needsReview'];
			$cleanFlag = '0';
			if ($clean === true) {
				$cleanFlag = '1';
			}

			$this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_CLEAN, value: $cleanFlag);
			$this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_PENDING, value: (string)$pending);
			$this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_MANUAL, value: (string)$manual);

			if ($clean === true) {
				$output->info('OpenConnector: no source holds an inline secret — Phase D gate is CLEAN.');
				return;
			}

			// Secret-free: counts and field names only, never a value.
			$output->warning(
				sprintf(
					'OpenConnector: %d inline source secret(s) awaiting migration, %d need manual review. '
					. 'Phase D must NOT remove the schema properties. Run '
					. '`occ openconnector:migrate-inline-secrets --dry-run` for the per-source breakdown.',
					$pending,
					$manual
				)
			);
		} catch (Throwable $e) {
			// Never fatal. A failure here must not brick an upgrade — but it MUST
			// fail the gate closed: an unknown status is not a clean status.
			$this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_CLEAN, value: '0');
			$output->warning('OpenConnector: could not compute inline-secret migration status: ' . $e->getMessage());
			$this->logger->error(
				'[openconnector] RecordInlineSecretMigrationStatus failed; Phase D gate set to NOT-clean',
				['errorClass' => get_class($e)]
			);
		}//end try
	}//end run()

	/**
	 * Execute the inline-secret migration. Fail-closed and NON-FATAL.
	 *
	 * A total executor failure (an absent or too-old broker, a container that
	 * cannot resolve the broker) is caught here and swallowed so it can never brick
	 * an `occ upgrade`. The subsequent recording pass re-reads the true state, so a
	 * migration that could not run simply keeps the gate closed — no inline secret
	 * is ever nulled without a verified round-trip (the executor's own contract).
	 *
	 * Secret-free: only the migration COUNTS and the broker error's class/message
	 * (never a secret) are surfaced.
	 *
	 * @param OrObjectService $objectService The OpenRegister object service.
	 * @param InlineSecretMigrationPlanner $planner The read-safe planner (reused by the executor).
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
	 */
	protected function runMigration(OrObjectService $objectService, InlineSecretMigrationPlanner $planner, IOutput $output): void {
		try {
			$executor = $this->makeExecutor(objectService: $objectService, planner: $planner);
			$result = $executor->migrateAll();

			// Counts only — never a secret.
			$output->info(
				sprintf(
					'OpenConnector: inline-secret migration ran — %d migrated, %d blocked, %d failed, %d skipped.',
					(int)$result['migrated'],
					(int)$result['blocked'],
					(int)$result['failed'],
					(int)$result['skipped']
				)
			);
		} catch (Throwable $e) {
			// A blocked/old/absent broker (or any executor-level failure) must NOT
			// abort the upgrade and must NOT record a clean gate. The recording pass
			// will observe the still-dirty state and keep Phase D closed.
			$output->warning('OpenConnector: inline-secret migration could not run: ' . $e->getMessage());
			$this->logger->warning(
				'[openconnector] RecordInlineSecretMigrationStatus: executor did not run; inline secrets left intact',
				['errorClass' => get_class($e)]
			);
		}//end try
	}//end runMigration()

	/**
	 * Build the migration executor (a protected seam so tests can inject a double).
	 *
	 * @param OrObjectService $objectService The OpenRegister object service.
	 * @param InlineSecretMigrationPlanner $planner The read-safe planner reused for classification + the post-run gate.
	 *
	 * @return InlineSecretMigrationExecutor The executor.
	 *
	 * @spec exclude Construction seam — no domain behavior (overridden in tests).
	 */
	protected function makeExecutor(OrObjectService $objectService, InlineSecretMigrationPlanner $planner): InlineSecretMigrationExecutor {
		return new InlineSecretMigrationExecutor(
			objectService: $objectService,
			planner: $planner,
			logger: $this->logger
		);
	}//end makeExecutor()
}//end class
