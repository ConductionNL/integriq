<?php
/**
 * OCC command: openconnector:authentication-config (ocon#232, follow-up to ocon#151).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS COMMAND IS FOR
 * ─────────────────────────────────────────────────────────────────────────────
 * `source.authenticationConfig` is VESTIGIAL: no PHP code authenticates from it.
 * ocon#151 parked it as `needs-manual-review` on the assumption it would later be
 * migrated into the credential broker. ocon#232 establishes that a migration would
 * be POINTLESS — nothing would ever resolve the minted ref — because the field lost
 * its last reader in commit b6470597 (2024-11-19), which moved
 * {@see \OCA\OpenConnector\Twig\AuthenticationRuntime} from
 * `$source->getAuthenticationConfig()` to `$source->getConfiguration()` +
 * `authentication.*` without migrating the data or updating the docs. The full
 * evidence is on {@see \OCA\OpenConnector\Service\Security\AuthenticationConfigAuditor}.
 *
 * So the correct treatment is REMOVAL, not migration. This command is the operator's
 * two-step path to it:
 *
 *   occ openconnector:authentication-config                  # AUDIT — read-only, the default
 *   occ openconnector:authentication-config --json           # ...machine-readable
 *   occ openconnector:authentication-config --remove-authentication-config
 *   occ openconnector:authentication-config --drop-schema-property
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THE REMOVAL LIVES HERE AND NOT IN THE PHASE-D REPAIR STEP
 * ─────────────────────────────────────────────────────────────────────────────
 * {@see \OCA\OpenConnector\Repair\RemoveMigratedSourceSecretFields} performs the
 * equivalent Phase-D cleanup for the FOUR auto-migratable fields, and it is a
 * REPAIR step — it runs unattended on every app upgrade. That is safe there,
 * because those four were MIGRATED first: their values live on in the broker, so
 * dropping the schema property loses nothing.
 *
 * `authenticationConfig` is different in kind: it is not migrated anywhere, so
 * clearing it is a genuine DELETE of the only copy. This change therefore
 * deliberately does NOT extend that repair step, and does NOT add an
 * appconfig-opt-in branch to it either — even though an appconfig gate was the
 * obvious option. The reason: an appconfig flag PERSISTS. Set once by an operator,
 * it would sit in the database forever and re-arm the deletion on every subsequent
 * upgrade — so a source that legitimately re-acquired an `authenticationConfig`
 * afterwards (a restored backup, a re-imported configuration, an operator following
 * the still-current docs) would have it silently deleted by an unattended repair
 * run, with no human in the loop. A command-only gate has no persistent trigger:
 * every deletion requires a human to type the flag, at a moment of their choosing,
 * against a fleet they just audited. Deletions should require intent every time,
 * not once.
 *
 * Consequently this command is NOT reachable from `occ maintenance:repair` or an
 * app upgrade: it is registered in `appinfo/info.xml` under `<commands>`, never
 * `<repair-steps>`, and it implements no `IRepairStep`.
 *
 * NOTE: `authenticationConfig` deliberately REMAINS declared in
 * `lib/Settings/openconnector_register.json`. OpenRegister's `Schema::hydrate()`
 * applies `properties` via `setProperties()` — a wholesale REPLACE — so a
 * version-bumping register import would PRUNE any property absent from that JSON,
 * fleet-wide and UNGATED. The property is dropped only per-instance, here, behind
 * an explicit flag.
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

use OCA\OpenConnector\Service\Security\AuthenticationConfigAuditor;
use OCA\OpenConnector\Service\Security\AuthenticationConfigRemover;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Audits (default) and, behind an explicit flag, removes the vestigial authenticationConfig.
 *
 * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
 */
class AuthenticationConfig extends Command
{

    /**
     * The explicit opt-in flag that authorises the DELETE. Nothing writes without it.
     *
     * @var string
     */
    public const FLAG_REMOVE = 'remove-authentication-config';

    /**
     * The explicit opt-in flag that drops the schema property.
     *
     * @var string
     */
    public const FLAG_DROP_SCHEMA = 'drop-schema-property';

    /**
     * FQCN of OpenRegister's SchemaMapper, resolved lazily (mirrors the Phase-D repair step).
     *
     * @var string
     */
    private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

    /**
     * The source schema slug.
     *
     * @var string
     */
    private const SCHEMA_SLUG = 'source';

    /**
     * Constructor.
     *
     * @param AuthenticationConfigAuditor $auditor   The read-only audit.
     * @param AuthenticationConfigRemover $remover   The gated removal.
     * @param ContainerInterface          $container The DI container (lazy OpenRegister SchemaMapper).
     */
    public function __construct(
        private readonly AuthenticationConfigAuditor $auditor,
        private readonly AuthenticationConfigRemover $remover,
        private readonly ContainerInterface $container
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
        $this->setName(name: 'openconnector:authentication-config')
            ->setDescription(
                'Audit the vestigial source authenticationConfig field; optionally remove it (explicit opt-in)'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Emit the machine-readable report'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of sources to inspect',
                '1000'
            )
            ->addOption(
                self::FLAG_REMOVE,
                null,
                InputOption::VALUE_NONE,
                'DELETES the authenticationConfig value from every source that holds one. '
                .'Explicit opt-in; without this flag the command only audits.'
            )
            ->addOption(
                self::FLAG_DROP_SCHEMA,
                null,
                InputOption::VALUE_NONE,
                'Drops the authenticationConfig property from the live source schema. '
                .'Only permitted once every source is clear.'
            );

    }//end configure()

    /**
     * Execute the command — audit by default; write only under an explicit flag.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return integer 0 on success; non-zero on a validation failure or a refused run.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
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

        // ── THE GATE ─────────────────────────────────────────────────────────
        // Only these two explicit flags reach a writing path. The default action
        // is the read-only audit. A repair step / upgrade cannot reach any of it:
        // this is a Command, registered under <commands>, not <repair-steps>.
        if ((bool) $input->getOption(self::FLAG_DROP_SCHEMA) === true) {
            return $this->runDropSchemaProperty(io: $io, limit: $limit);
        }

        if ((bool) $input->getOption(self::FLAG_REMOVE) === true) {
            return $this->runRemove(io: $io, output: $output, limit: $limit, json: $json);
        }

        return $this->runAudit(io: $io, output: $output, limit: $limit, json: $json);
    }//end execute()

    /**
     * Run the read-only audit. Writes NOTHING.
     *
     * @param SymfonyStyle    $io     Styled console I/O.
     * @param OutputInterface $output Raw console output (for the --json payload).
     * @param integer         $limit  Maximum sources to inspect.
     * @param boolean         $json   Whether to emit the machine-readable report.
     *
     * @return integer Command::SUCCESS, or Command::FAILURE when the audit could not run.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
     */
    private function runAudit(SymfonyStyle $io, OutputInterface $output, int $limit, bool $json): int
    {
        try {
            $report = $this->auditor->auditAll(limit: $limit);
        } catch (Throwable $e) {
            $io->error('Could not audit authenticationConfig: '.$e->getMessage());
            return Command::FAILURE;
        }

        if ($json === true) {
            $output->writeln((string) json_encode($report, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
            return Command::SUCCESS;
        }

        $io->note('Audit only: nothing is written. Values are NEVER printed — key names, shapes and fingerprints only.');
        $this->renderAudit(io: $io, report: $report);
        return Command::SUCCESS;
    }//end runAudit()

    /**
     * Render the human-readable audit.
     *
     * @param SymfonyStyle         $io     Styled console I/O.
     * @param array<string, mixed> $report The auditor's report.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
     */
    private function renderAudit(SymfonyStyle $io, array $report): void
    {
        $rows = [];
        foreach ((array) ($report['sources'] ?? []) as $source) {
            $shapes = [];
            foreach ((array) ($source['shapes'] ?? []) as $key => $shape) {
                $hint = (string) ($shape['shape'] ?? '?');
                if (($shape['fingerprint'] ?? null) !== null) {
                    $hint .= ' #'.(string) $shape['fingerprint'];
                }

                $shapes[] = $key.': '.$hint;
            }

            $referenced = 'no';
            if (($source['referenced'] ?? false) === true) {
                $referenced = 'YES -> '.implode(', ', (array) ($source['references'] ?? []));
            }

            $rows[] = [
                (string) ($source['name'] ?? '?'),
                (string) ($source['uuid'] ?? '?'),
                (string) ($source['state'] ?? '?'),
                implode(', ', (array) ($source['keys'] ?? [])),
                implode(' | ', $shapes),
                $referenced,
            ];
        }//end foreach

        if ($rows !== []) {
            $io->table(['source', 'uuid', 'state', 'keys (names only)', 'value shapes', 'twig-referenced'], $rows);
        }

        $io->writeln(sprintf('Sources inspected : %d', (int) ($report['totalSources'] ?? 0)));
        $io->writeln(sprintf('Hold a value      : %d', (int) ($report['holdValue'] ?? 0)));
        $io->writeln(sprintf('Already clear     : %d', (int) ($report['clear'] ?? 0)));
        $io->writeln(sprintf('Unreadable        : %d', (int) ($report['unreadable'] ?? 0)));
        $io->writeln(sprintf('Twig-referenced   : %d', (int) ($report['referenced'] ?? 0)));
        $io->newLine();

        if ((int) ($report['referenced'] ?? 0) > 0) {
            $io->warning(
                'Some sources reference `source.authenticationConfig` from a Twig template in their '
                .'`configuration`. CallService renders those templates against the RAW source, so those '
                .'references DO resolve to live secrets today. Removal will REFUSE these sources. '
                .'Re-point them at `configuration.authentication` first.'
            );
        }

        if ((bool) ($report['schemaPropertyRemovable'] ?? false) === true) {
            $io->success(
                'Every source is clear and nothing references the field. '
                .'The schema property may be dropped with --'.self::FLAG_DROP_SCHEMA.'.'
            );
            return;
        }

        $io->writeln(
            'Next: review the keys above, then run with --'.self::FLAG_REMOVE.' to DELETE these values. '
            .'authenticationConfig is vestigial (no code authenticates from it) — see ocon#232.'
        );
    }//end renderAudit()

    /**
     * Run the gated removal. Reached ONLY via the explicit flag.
     *
     * @param SymfonyStyle    $io     Styled console I/O.
     * @param OutputInterface $output Raw console output (for the --json payload).
     * @param integer         $limit  Maximum sources to inspect.
     * @param boolean         $json   Whether to emit the machine-readable result.
     *
     * @return integer Command::SUCCESS, or Command::FAILURE when a source failed.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-removal
     */
    private function runRemove(SymfonyStyle $io, OutputInterface $output, int $limit, bool $json): int
    {
        try {
            // `optIn: true` is the remover's OWN independent gate — it throws without it.
            $result = $this->remover->removeAll(limit: $limit, optIn: true);
        } catch (Throwable $e) {
            $io->error('Refusing to remove authenticationConfig: '.$e->getMessage());
            return Command::FAILURE;
        }

        if ($json === true) {
            $output->writeln((string) json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
        }

        if ($json === false) {
            $rows = [];
            foreach ((array) ($result['sources'] ?? []) as $source) {
                $rows[] = [
                    (string) ($source['name'] ?? '?'),
                    (string) ($source['uuid'] ?? '?'),
                    (string) ($source['outcome'] ?? '?'),
                    (string) ($source['reason'] ?? '-'),
                ];
            }

            if ($rows !== []) {
                $io->table(['source', 'uuid', 'outcome', 'reason'], $rows);
            }

            $io->writeln(sprintf('Removed : %d', (int) ($result['removed'] ?? 0)));
            $io->writeln(sprintf('Skipped : %d (already clear)', (int) ($result['skipped'] ?? 0)));
            $io->writeln(sprintf('Blocked : %d (a Twig template still references the field)', (int) ($result['blocked'] ?? 0)));
            $io->writeln(sprintf('Failed  : %d', (int) ($result['failed'] ?? 0)));
        }

        if ((int) ($result['failed'] ?? 0) > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end runRemove()

    /**
     * Drop `authenticationConfig` from the LIVE source schema — gated on a clean audit.
     *
     * Per-instance only. The register JSON keeps declaring the property on purpose
     * (see this class's docblock).
     *
     * @param SymfonyStyle $io    Styled console I/O.
     * @param integer      $limit Maximum sources to inspect for the gate.
     *
     * @return integer Command::SUCCESS, or Command::FAILURE when refused.
     *
     * @spec openspec/changes/migrate-inline-secrets-to-broker/specs/source-credential-custody/spec.md#requirement-authentication-config-removal
     */
    private function runDropSchemaProperty(SymfonyStyle $io, int $limit): int
    {
        try {
            $report = $this->auditor->auditAll(limit: $limit);
        } catch (Throwable $e) {
            $io->error('Could not audit authenticationConfig: '.$e->getMessage());
            return Command::FAILURE;
        }

        // Fail closed: never drop the property while a source still holds a value,
        // a Twig template still references it, or a source could not be read at all.
        if ((bool) ($report['schemaPropertyRemovable'] ?? false) === false) {
            $io->error(
                sprintf(
                    'Refusing to drop the schema property: %d source(s) still hold a value, '
                    .'%d still reference it from a Twig template, %d could not be read. '
                    .'Run the audit, then --%s first.',
                    (int) ($report['holdValue'] ?? 0),
                    (int) ($report['referenced'] ?? 0),
                    (int) ($report['unreadable'] ?? 0),
                    self::FLAG_REMOVE
                )
            );
            return Command::FAILURE;
        }

        try {
            $schemaMapper = $this->container->get(self::SCHEMA_MAPPER);
            $schema       = $schemaMapper->find(self::SCHEMA_SLUG);
            $properties   = $schema->getProperties();
            if (is_array($properties) === false) {
                $io->error('Could not read the live source schema properties; nothing was changed.');
                return Command::FAILURE;
            }

            if (array_key_exists(AuthenticationConfigAuditor::FIELD, $properties) === false) {
                $io->success('authenticationConfig is already absent from the live source schema; nothing to do.');
                return Command::SUCCESS;
            }

            unset($properties[AuthenticationConfigAuditor::FIELD]);
            $schema->setProperties($properties);
            $schemaMapper->update($schema);
        } catch (Throwable $e) {
            $io->error('Could not drop the schema property: '.$e->getMessage());
            return Command::FAILURE;
        }//end try

        $io->success(
            'Dropped authenticationConfig from the live source schema. '
            .'It remains declared in openconnector_register.json on purpose — removing it there would prune '
            .'the property fleet-wide on the next version-bumping import (ocon#232).'
        );
        return Command::SUCCESS;
    }//end runDropSchemaProperty()
}//end class
