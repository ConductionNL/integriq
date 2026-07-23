<?php
/**
 * OCC command: openconnector:migrate-inline-secrets.
 *
 * Phase C of ocon#151 / ADR-064. Reports which `source` objects still hold an
 * INLINE credential (`apikey`, `secret`, `password`, `jwt`,
 * `authenticationConfig`) and what each would become in the OpenRegister
 * credential broker.
 *
 * `--dry-run` is fully implemented and writes NOTHING. It is:
 *   - the human's sanity check before any real run, and
 *   - the gate Phase D must evaluate: `--json` emits `"clean": true` only when
 *     ZERO sources hold an unmigrated inline secret. Phase D MUST NOT remove the
 *     schema properties while `clean` is false.
 *
 * A REAL run (no `--dry-run`) mints → VERIFIES → nulls, per source per field,
 * through {@see \OCA\OpenConnector\Service\Security\InlineSecretMigrationExecutor}.
 * The Phase-C blocker is resolved: openregister#450 (`actingOrganisationId`) +
 * or#440 (sessionless `mint()`) let this migration mint organisation-scoped
 * credentials AND verify them round-trip without a user session. After a real
 * run the command re-reports the true Phase D gate into appconfig so
 * {@see \OCA\OpenConnector\Repair\RecordInlineSecretMigrationStatus}'s signal
 * stays honest. If the installed broker is too old to mint or to resolve
 * organisation-scoped credentials sessionlessly, the real run fails closed with
 * an upgrade hint and rewrites NOTHING (never plaintext left silently).
 *
 * Flags:
 *   --dry-run   Report what WOULD migrate; writes nothing.
 *   --json      Emit the machine-readable plan / result (Phase D's gate).
 *   --limit=<n> Maximum sources to inspect (default 1000).
 *
 * @category Command
 * @package  OCA\OpenConnector\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Command;

use OCA\OpenConnector\Repair\RecordInlineSecretMigrationStatus;
use OCA\OpenConnector\Service\Security\InlineSecretMigrationExecutor;
use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Reports (and, once unblocked, performs) the inline-secret → credentialRef migration.
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
 */
class MigrateInlineSecrets extends Command
{
    /**
     * Constructor.
     *
     * @param InlineSecretMigrationPlanner  $planner   The read-only migration planner (dry-run + post-run gate).
     * @param InlineSecretMigrationExecutor $executor  The writing executor (mint → verify → null).
     * @param IAppConfig                    $appConfig The appconfig store for the Phase D gate signal.
     */
    public function __construct(
        private readonly InlineSecretMigrationPlanner $planner,
        private readonly InlineSecretMigrationExecutor $executor,
        private readonly IAppConfig $appConfig
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * Configure the command name, description, and options.
     *
     * @return void
     *
     * @spec exclude Symfony console wiring — framework metadata, no domain behavior.
     */
    protected function configure(): void
    {
        $this->setName(name: 'openconnector:migrate-inline-secrets')
            ->setDescription('Report (dry-run) the migration of inline source secrets into the OpenRegister credential broker')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what WOULD migrate without writing anything.'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Emit the machine-readable plan, including the "clean" gate Phase D must check'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of sources to inspect',
                '1000'
            );

    }//end configure()

    /**
     * Execute the command.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return integer 0 on success; non-zero on validation failure or a refused real run.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limitRaw = (string) $input->getOption('limit');
        if (preg_match('/^\d+$/', $limitRaw) !== 1) {
            $io->error(sprintf('limit must be an integer, got "%s"', $limitRaw));
            return Command::FAILURE;
        }

        $limit = (int) $limitRaw;
        if ($limit < 1 || $limit > 100000) {
            $io->error(sprintf('limit must be in [1, 100000], got %d', $limit));
            return Command::FAILURE;
        }

        $json = (bool) $input->getOption('json');

        if ((bool) $input->getOption('dry-run') === false) {
            return $this->runMigrate(io: $io, output: $output, limit: $limit, json: $json);
        }

        try {
            $plan = $this->planner->planAll(limit: $limit);
        } catch (Throwable $e) {
            // Log-friendly, no stack trace to stdout per ADR-005. The message is
            // not interpolated with any object data.
            $io->error('Could not build the migration plan: '.$e->getMessage());
            return Command::FAILURE;
        }

        if ($json === true) {
            $output->writeln((string) json_encode($plan, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
            return Command::SUCCESS;
        }

        return $this->renderPlan(io: $io, plan: $plan);
    }//end execute()

    /**
     * Perform a REAL (writing) run: mint → verify → null, then re-report the gate.
     *
     * Fails closed when the broker is unavailable or too old to migrate safely —
     * nothing is rewritten, and the error carries the upgrade hint. After a
     * successful drive the true post-run Phase D gate is persisted to appconfig
     * so the repair-step signal stays honest.
     *
     * @param SymfonyStyle    $io     Styled console I/O.
     * @param OutputInterface $output Raw console output (for the --json payload).
     * @param integer         $limit  Maximum sources to inspect.
     * @param boolean         $json   Whether to emit the machine-readable result.
     *
     * @return integer Command::SUCCESS on a completed run, Command::FAILURE when refused or if a field failed.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
     */
    private function runMigrate(SymfonyStyle $io, OutputInterface $output, int $limit, bool $json): int
    {
        try {
            $result = $this->executor->migrateAll(limit: $limit);
        } catch (Throwable $e) {
            // Fail closed: broker unavailable/too old, or the run could not start.
            // Nothing was rewritten; the message carries the upgrade hint.
            $io->error('Refusing to migrate: '.$e->getMessage());
            return Command::FAILURE;
        }

        // Persist the TRUE post-run Phase D gate so the repair-step signal stays honest.
        $this->recordPhaseDGate(result: $result);

        if ($json === true) {
            $output->writeln((string) json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
        }

        if ($json === false) {
            $this->renderResult(io: $io, result: $result);
        }

        // A field that failed to migrate is a non-zero exit so an operator/CI notices.
        if ((int) $result['failed'] > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end runMigrate()

    /**
     * Persist the post-run Phase D gate into appconfig (fails closed on a bad shape).
     *
     * Reuses {@see RecordInlineSecretMigrationStatus} keys so a real run and an
     * upgrade repair step write the SAME signal. `clean` is '1' only when the
     * post-run re-plan found zero pending and zero manual-review fields.
     *
     * @param array<string, mixed> $result The executor's result.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-phase-d-gate-signal
     */
    private function recordPhaseDGate(array $result): void
    {
        $postRun = (array) ($result['postRun'] ?? []);
        $clean   = (bool) ($postRun['clean'] ?? false);
        $pending = (int) ($postRun['pending'] ?? 0);
        $manual  = (int) ($postRun['manual'] ?? 0);

        $cleanFlag = '0';
        if ($clean === true) {
            $cleanFlag = '1';
        }

        // Reuse the planner's public app id (the repair step's APP_ID is private);
        // both resolve to 'openconnector', keeping the appconfig target identical.
        $app = InlineSecretMigrationPlanner::APP_ID;
        $this->appConfig->setValueString(app: $app, key: RecordInlineSecretMigrationStatus::KEY_CLEAN, value: $cleanFlag);
        $this->appConfig->setValueString(app: $app, key: RecordInlineSecretMigrationStatus::KEY_PENDING, value: (string) $pending);
        $this->appConfig->setValueString(app: $app, key: RecordInlineSecretMigrationStatus::KEY_MANUAL, value: (string) $manual);
    }//end recordPhaseDGate()

    /**
     * Render the human-readable result of a real run.
     *
     * @param SymfonyStyle         $io     Styled console I/O.
     * @param array<string, mixed> $result The executor's result.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-executor
     */
    private function renderResult(SymfonyStyle $io, array $result): void
    {
        $rows = [];
        foreach ((array) ($result['sources'] ?? []) as $source) {
            foreach ((array) ($source['fields'] ?? []) as $field) {
                $rows[] = [
                    (string) ($source['name'] ?? '?'),
                    (string) ($source['uuid'] ?? '?'),
                    (string) ($field['field'] ?? '?'),
                    (string) ($field['outcome'] ?? '?'),
                    (string) ($field['reason'] ?? '-'),
                ];
            }
        }

        if ($rows !== []) {
            $io->table(['source', 'uuid', 'field', 'outcome', 'reason'], $rows);
        }

        $io->writeln(sprintf('Sources inspected : %d', (int) ($result['totalSources'] ?? 0)));
        $io->writeln(sprintf('Migrated          : %d', (int) ($result['migrated'] ?? 0)));
        $io->writeln(sprintf('Failed            : %d', (int) ($result['failed'] ?? 0)));
        $io->writeln(sprintf('Blocked           : %d', (int) ($result['blocked'] ?? 0)));
        $io->writeln(sprintf('Skipped           : %d', (int) ($result['skipped'] ?? 0)));
        $io->newLine();

        $postRun = (array) ($result['postRun'] ?? []);
        if ((bool) ($postRun['clean'] ?? false) === true) {
            $io->success('Phase D gate: CLEAN — no source holds an inline secret. The schema properties may be removed.');
            return;
        }

        $io->warning(
            sprintf(
                'Phase D gate: NOT CLEAN — %d field(s) still pending, %d need manual review. '
                .'Do NOT remove the schema properties. Blocked sources (no organisation) and '
                .'`authenticationConfig` need a human before Phase D.',
                (int) ($postRun['pending'] ?? 0),
                (int) ($postRun['manual'] ?? 0)
            )
        );
    }//end renderResult()

    /**
     * Render the human-readable plan.
     *
     * @param SymfonyStyle         $io   Styled console I/O.
     * @param array<string, mixed> $plan The planner's output.
     *
     * @return integer Command::SUCCESS.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-inline-secret-migration-plan
     */
    private function renderPlan(SymfonyStyle $io, array $plan): int
    {
        $io->note('Dry-run: nothing is written. Inline secrets are left exactly as they are.');

        $sources = (array) ($plan['sources'] ?? []);
        if ($sources === []) {
            $io->success(
                'No source holds an inline secret. Phase D gate: CLEAN — the schema properties may be removed.'
            );
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($sources as $source) {
            foreach ((array) ($source['fields'] ?? []) as $field) {
                $rows[] = [
                    (string) ($source['name'] ?? '?'),
                    (string) ($source['uuid'] ?? '?'),
                    (string) ($field['field'] ?? '?'),
                    (string) ($field['state'] ?? '?'),
                    (string) ($field['provider'] ?? '-'),
                ];
            }
        }

        $io->table(['source', 'uuid', 'field', 'state', 'would mint as'], $rows);

        $io->writeln(sprintf('Sources inspected : %d', (int) ($plan['totalSources'] ?? 0)));
        $io->writeln(sprintf('Fields to migrate : %d', (int) ($plan['wouldMigrate'] ?? 0)));
        $io->writeln(sprintf('Need manual review: %d', (int) ($plan['needsReview'] ?? 0)));
        $io->newLine();

        if ((int) ($plan['needsReview'] ?? 0) > 0) {
            $io->warning(
                'Some fields cannot be mapped to a single broker provider automatically — '
                .'`authenticationConfig` is an object that may hold several values at once, and every '
                .'inject-only provider stores exactly one opaque secret. These need a human decision; '
                .'the planner will not guess a decomposition.'
            );
        }

        $io->warning('Phase D gate: NOT CLEAN — sources still hold inline secrets. Do NOT remove the schema properties.');
        return Command::SUCCESS;
    }//end renderPlan()
}//end class
