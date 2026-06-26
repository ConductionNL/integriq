<?php
/**
 * OCC command: openconnector:migrate-storage.
 *
 * Operator entrypoint for the chain-B legacy → OpenRegister storage migration.
 * Wraps {@see LegacyToRegisterMigrator::migrateAll()} so admins can run, dry-run,
 * retry per-entity, or verify the migration from the CLI — outside the
 * `occ upgrade` window. The chain-B migration class
 * ({@see \OCA\OpenConnector\Migration\Version2Date20260520000001}) points
 * operators here for per-entity retry when a full run reports skips/errors.
 *
 * Flags:
 *   --dry-run         Count legacy rows without writing; flag stays untouched.
 *   --entity=<slug>   Run a single entity (one of the 15 ENTITY_ORDER slugs).
 *   --batch-size=<n>  Override batch size; valid range [100, 100000].
 *   --verify-only     Skip migration; print per-entity legacy↔register row parity.
 *
 * Cross-ref: openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md
 * REQ-008 (OCC command must allow manual re-runnability), Task 15 + Task 17.
 *
 * @category Command
 * @package  OCA\OpenConnector\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Command;

use InvalidArgumentException;
use OCA\OpenConnector\Service\Migration\LegacyToRegisterMigrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrates openconnector legacy tables into OpenRegister storage from the CLI.
 *
 * @spec openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md
 */
class MigrateToOpenRegister extends Command
{

    /**
     * The 15 valid entity slugs, surfaced in error messages for `--entity`.
     *
     * @var array<int, string>
     */
    private const VALID_SLUGS = [
        'source',
        'consumer',
        'endpoint',
        'event',
        'event_subscription',
        'job',
        'mapping',
        'rule',
        'synchronization',
        'synchronization_contract',
        'event_message',
        'call_log',
        'job_log',
        'synchronization_log',
        'synchronization_contract_log',
    ];

    /**
     * Constructor.
     *
     * @param LegacyToRegisterMigrator $migrator The legacy → OR migrator service.
     */
    public function __construct(
        private readonly LegacyToRegisterMigrator $migrator
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * Configure the command name, description, and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'openconnector:migrate-storage')
            ->setDescription('Migrate openconnector legacy tables into OpenRegister storage')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Count legacy rows without writing; storage_migrated flag stays untouched'
            )
            ->addOption(
                'entity',
                null,
                InputOption::VALUE_REQUIRED,
                'Run a single entity (one of: '.implode(', ', self::VALID_SLUGS).')'
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch size for legacy reads (valid range 100..100000)',
                '10000'
            )
            ->addOption(
                'verify-only',
                null,
                InputOption::VALUE_NONE,
                'Skip migration; print per-entity legacy↔register row-count parity'
            );

    }//end configure()

    /**
     * Execute the command.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return integer 0 on success; non-zero on validation or migration failure.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entity = $input->getOption('entity');
        if ($entity !== null && in_array($entity, self::VALID_SLUGS, true) === false) {
            $io->error(
                sprintf(
                    'Unknown entity "%s". Valid slugs: %s',
                    (string) $entity,
                    implode(', ', self::VALID_SLUGS)
                )
            );
            return Command::FAILURE;
        }

        $batchSizeRaw = (string) $input->getOption('batch-size');
        if (preg_match('/^\d+$/', $batchSizeRaw) !== 1) {
            $io->error(sprintf('batch-size must be an integer, got "%s"', $batchSizeRaw));
            return Command::FAILURE;
        }

        $batchSize = (int) $batchSizeRaw;
        if ($batchSize < 100 || $batchSize > 100000) {
            $io->error(sprintf('batch-size must be in [100, 100000], got %d', $batchSize));
            return Command::FAILURE;
        }

        if ($input->getOption('verify-only') === true) {
            return $this->runVerify(io: $io);
        }

        $entitySlug = null;
        if ($entity !== null) {
            $entitySlug = (string) $entity;
        }

        return $this->runMigrate(
            io: $io,
            dryRun: (bool) $input->getOption('dry-run'),
            entity: $entitySlug,
            batchSize: $batchSize
        );

    }//end execute()

    /**
     * Run the migration and render the per-entity summary table.
     *
     * @param SymfonyStyle $io        Styled console I/O.
     * @param boolean      $dryRun    Whether to run without writing.
     * @param string|null  $entity    Single-entity slug, or null for a full run.
     * @param integer      $batchSize Batch size.
     *
     * @return integer 0 on a clean run; non-zero if any entity reported skips/errors.
     */
    private function runMigrate(SymfonyStyle $io, bool $dryRun, ?string $entity, int $batchSize): int
    {
        if ($dryRun === true) {
            $io->note('Dry-run: counting legacy rows only — no data is written and the flag is unchanged.');
        }

        try {
            $results = $this->migrator->migrateAll(dryRun: $dryRun, entitySlug: $entity, batchSize: $batchSize);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            // Log-friendly, no stack trace to stdout per ADR-005.
            $io->error('Migration failed: '.$e->getMessage());
            return Command::FAILURE;
        }

        $rows     = $this->buildResultRows(results: $results);
        $hadIssue = $this->resultsHaveIssue(results: $results);

        $io->table(
            ['entity', 'legacy', 'migrated', 'skipped', 'fkRewrites', 'ms', 'error'],
            $rows
        );

        if ($hadIssue === true) {
            $io->warning(
                'One or more entities reported skips or errors — storage_migrated was NOT flipped.'
                .' Re-run with --entity=<slug> to retry the affected entity.'
            );
            return Command::FAILURE;
        }

        $io->success($this->successMessage(dryRun: $dryRun, entity: $entity));
        return Command::SUCCESS;

    }//end runMigrate()

    /**
     * Map the migrator's per-entity result array into console table rows.
     *
     * @param array<int, array<string, mixed>> $results Per-entity results.
     *
     * @return array<int, array<int, int|string>>
     */
    private function buildResultRows(array $results): array
    {
        $rows = [];
        foreach ($results as $perEntity) {
            $error     = (string) ($perEntity['error'] ?? '');
            $errorCell = '-';
            if ($error !== '') {
                $errorCell = $error;
            }

            $rows[] = [
                (string) ($perEntity['slug'] ?? '?'),
                (int) ($perEntity['legacyCount'] ?? 0),
                (int) ($perEntity['migratedCount'] ?? 0),
                (int) ($perEntity['skipped'] ?? 0),
                (int) ($perEntity['fkRewrites'] ?? 0),
                (int) ($perEntity['elapsedMs'] ?? 0),
                $errorCell,
            ];
        }

        return $rows;

    }//end buildResultRows()

    /**
     * Whether any entity reported a skip or an error.
     *
     * @param array<int, array<string, mixed>> $results Per-entity results.
     *
     * @return boolean
     */
    private function resultsHaveIssue(array $results): bool
    {
        foreach ($results as $perEntity) {
            if ((int) ($perEntity['skipped'] ?? 0) > 0 || (string) ($perEntity['error'] ?? '') !== '') {
                return true;
            }
        }

        return false;

    }//end resultsHaveIssue()

    /**
     * Build the success message for a clean run.
     *
     * @param boolean     $dryRun Whether this was a dry-run.
     * @param string|null $entity Single-entity slug, or null for a full run.
     *
     * @return string
     */
    private function successMessage(bool $dryRun, ?string $entity): string
    {
        if ($dryRun === true) {
            return 'Dry-run complete — legacy row counts above. No data written.';
        }

        if ($entity !== null) {
            return sprintf('Entity "%s" migrated. Run a full migration to flip storage_migrated.', $entity);
        }

        return 'Migration complete — storage_migrated set to true.';

    }//end successMessage()

    /**
     * Render the per-entity legacy↔register parity report.
     *
     * @param SymfonyStyle $io Styled console I/O.
     *
     * @return integer 0 if every entity reports parity; non-zero otherwise.
     */
    private function runVerify(SymfonyStyle $io): int
    {
        try {
            $report = $this->migrator->verifyRowCounts();
        } catch (\Throwable $e) {
            $io->error('Verification failed: '.$e->getMessage());
            return Command::FAILURE;
        }

        $rows     = [];
        $allEqual = true;
        foreach ($report as $entry) {
            $equal = $entry['equal'];
            if ($equal === false) {
                $allEqual = false;
            }

            $parityCell = 'MISMATCH';
            if ($equal === true) {
                $parityCell = 'OK';
            }

            $rows[] = [
                $entry['slug'],
                $entry['legacy'],
                $entry['register'],
                $entry['skipped'],
                $parityCell,
            ];
        }//end foreach

        $io->table(['entity', 'legacy', 'register', 'skipped', 'parity'], $rows);

        if ($allEqual === true) {
            $io->success('All 15 entities report legacy↔register row-count parity.');
            return Command::SUCCESS;
        }

        $io->error('One or more entities show a row-count mismatch — investigate before draining legacy tables.');
        return Command::FAILURE;

    }//end runVerify()
}//end class
