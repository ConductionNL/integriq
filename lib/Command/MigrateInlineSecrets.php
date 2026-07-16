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
 * A REAL run is currently FAIL-CLOSED — see {@see runMigrate()}. Executing the
 * migration requires minting organisation-scoped credentials, which cannot be
 * verified (or resolved at runtime) sessionlessly on OpenRegister
 * `origin/development`. Rather than mint credentials that fail closed on every
 * background sync, or silently downgrade to `personal` scope against ADR-064
 * Rule 4, the command refuses and points at the blocker.
 *
 * Flags:
 *   --dry-run   Report what WOULD migrate; writes nothing. (Currently mandatory.)
 *   --json      Emit the machine-readable plan (Phase D's gate).
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

use OCA\OpenConnector\Service\Security\InlineSecretMigrationPlanner;
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
     * Operator-facing explanation of why a real run is refused.
     *
     * @var string
     */
    private const BLOCKER = <<<'TXT'
A real (writing) run is not available yet — it is blocked upstream, by design.

ADR-064 Rule 4 requires a source's credential to be minted at `organisation`
scope (it is infrastructure, not one employee's property). At OpenRegister
HEAD an organisation-scoped credential cannot be resolved without a live user
session: CredentialBrokerService::assertOrganisationMember() denies outright
when userSession->getUser() is null, and the sessionless actingUserId fallback
is deliberately not consulted on that branch. SystemOperationContext does not
help — it bypasses RBAC only, never organisation membership.

That breaks this migration twice over:
  1. occ and repair steps are sessionless, so the mandatory verify step
     ("mint, resolve it back, assert it round-trips") can never pass. ADR-064
     forbids nulling an inline secret without that proof, so an
     organisation-scoped run would be a guaranteed no-op.
  2. openconnector's source calls are mostly sessionless background sync jobs.
     An organisation-scoped credential would fail closed on every one of them —
     trading a stripped secret for a broken integration.

`personal` scope is the only scope that resolves sessionlessly, but ADR-064
Rule 4 forbids it for infrastructure and it couples the source to one
employee's account. Both options are wrong, so this command refuses to guess.

Unblock by giving OpenRegister a sessionless organisation resolution for
trusted in-process callers (the "reserved system identity" ADR-064 Rule 4
already assumes exists), then implement the executor against it.

Meanwhile `--dry-run` is fully functional and writes nothing.
TXT;

    /**
     * Constructor.
     *
     * @param InlineSecretMigrationPlanner $planner The read-only migration planner.
     */
    public function __construct(
        private readonly InlineSecretMigrationPlanner $planner
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
                'Report what WOULD migrate without writing anything. Currently mandatory.'
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

        if ((bool) $input->getOption('dry-run') === false) {
            return $this->runMigrate(io: $io);
        }

        try {
            $plan = $this->planner->planAll(limit: $limit);
        } catch (Throwable $e) {
            // Log-friendly, no stack trace to stdout per ADR-005. The message is
            // not interpolated with any object data.
            $io->error('Could not build the migration plan: '.$e->getMessage());
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json') === true) {
            $output->writeln((string) json_encode($plan, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
            return Command::SUCCESS;
        }

        return $this->renderPlan(io: $io, plan: $plan);
    }//end execute()

    /**
     * Refuse a real run and explain why.
     *
     * Fail closed: never fall back to leaving plaintext silently, and never
     * downgrade the scope to make the run "work".
     *
     * @param SymfonyStyle $io Styled console I/O.
     *
     * @return integer Always Command::FAILURE.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-real-run-fails-closed
     */
    private function runMigrate(SymfonyStyle $io): int
    {
        $io->error('Refusing to migrate: the executor is blocked upstream.');
        $io->writeln(self::BLOCKER);
        $io->newLine();
        $io->note('Re-run with --dry-run to see exactly what would migrate.');
        return Command::FAILURE;
    }//end runMigrate()

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
