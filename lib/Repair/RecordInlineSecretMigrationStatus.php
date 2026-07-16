<?php

/**
 * OpenConnector — record the inline-secret migration status (Phase C / ADR-064).
 *
 * READ-ONLY BY DESIGN. This step NEVER mints, writes a credentialRef, or nulls an
 * inline secret. It runs the {@see InlineSecretMigrationPlanner} on every upgrade
 * and persists the answer to one question into appconfig:
 *
 *     "Do any `source` objects still hold an inline secret?"
 *
 * That persisted flag is the gate Phase D (the schema-property removal) must
 * check: Phase D MUST NOT remove `apikey`/`secret`/`password`/`jwt`/
 * `authenticationConfig` from the source schema while
 * `openconnector / inline_secrets_clean` is anything but `'1'`.
 *
 * Why a repair step and not the executor: the actual migration is blocked
 * upstream — a `source` credential must be minted at `organisation` scope
 * (ADR-064 Rule 4), and an organisation-scoped credential cannot be resolved (so
 * the mandatory verify step cannot pass, and runtime background sync would fail
 * closed) without a live user session, which a sessionless upgrade/occ run does
 * not have. See {@see \OCA\OpenConnector\Command\MigrateInlineSecrets} and
 * {@see InlineSecretMigrationPlanner::planAll()} for the full reasoning. Until
 * that is unblocked, the safe and useful thing an automatic step can do is
 * REPORT — never rewrite live credentials on an unattended upgrade.
 *
 * Idempotent, never fatal, and a no-op when OpenRegister is absent. No secret
 * value is ever logged or persisted — only counts and field names.
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

use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists the Phase D "zero unmigrated inline secrets" gate to appconfig.
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-gate-signal
 */
class RecordInlineSecretMigrationStatus implements IRepairStep
{

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
     * @param IAppConfig         $appConfig The appconfig store for the gate signal.
     * @param LoggerInterface    $logger    Secret-free logging only.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     *
     * @spec exclude Framework IRepairStep metadata accessor; no domain behavior.
     */
    public function getName(): string
    {
        return 'Record whether any OpenConnector source still holds an inline secret (Phase D gate)';
    }//end getName()

    /**
     * Compute and persist the migration status. NEVER writes to any source.
     *
     * @param IOutput $output The output interface.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-gate-signal
     */
    public function run(IOutput $output): void
    {
        if (class_exists('\\'.self::OR_OBJECT_SERVICE) === false) {
            $output->info('OpenConnector: OpenRegister not available; skipping inline-secret status check.');
            return;
        }

        try {
            $objectService = $this->container->get(self::OR_OBJECT_SERVICE);
            $planner       = new InlineSecretMigrationPlanner(
                objectService: $objectService,
                logger: $this->logger
            );

            $plan = $planner->planAll();

            $clean     = (bool) ($plan['clean'] ?? false);
            $pending   = (int) ($plan['wouldMigrate'] ?? 0);
            $manual    = (int) ($plan['needsReview'] ?? 0);
            $cleanFlag = '0';
            if ($clean === true) {
                $cleanFlag = '1';
            }

            $this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_CLEAN, value: $cleanFlag);
            $this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_PENDING, value: (string) $pending);
            $this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_MANUAL, value: (string) $manual);

            if ($clean === true) {
                $output->info('OpenConnector: no source holds an inline secret — Phase D gate is CLEAN.');
                return;
            }

            // Secret-free: counts and field names only, never a value.
            $output->warning(
                sprintf(
                    'OpenConnector: %d inline source secret(s) awaiting migration, %d need manual review. '
                    .'Phase D must NOT remove the schema properties. Run '
                    .'`occ openconnector:migrate-inline-secrets --dry-run` for the per-source breakdown.',
                    $pending,
                    $manual
                )
            );
        } catch (Throwable $e) {
            // Never fatal. A failure here must not brick an upgrade — but it MUST
            // fail the gate closed: an unknown status is not a clean status.
            $this->appConfig->setValueString(app: self::APP_ID, key: self::KEY_CLEAN, value: '0');
            $output->warning('OpenConnector: could not compute inline-secret migration status: '.$e->getMessage());
            $this->logger->error(
                '[openconnector] RecordInlineSecretMigrationStatus failed; Phase D gate set to NOT-clean',
                ['errorClass' => get_class($e)]
            );
        }//end try
    }//end run()
}//end class
