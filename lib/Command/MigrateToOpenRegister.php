<?php

/**
 * OCC command: integriq:migrate-storage.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Operator entrypoint for the chain-B legacy → OpenRegister storage migration.
 * Wraps {@see LegacyToRegisterMigrator::migrateAll()} so admins can run, dry-run,
 * retry per-entity, or verify the migration from the CLI — outside the
 * `occ upgrade` window. The chain-B migration class
 * ({@see \OCA\Integriq\Migration\Version2Date20260908000000}) points
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
 * @package  OCA\Integriq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use InvalidArgumentException;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigratorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrates Integriq legacy tables into OpenRegister storage from the CLI.
 *
 * @spec openspec/specs/openconnector-storage-migration/spec.md
 */
class MigrateToOpenRegister extends Command {

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
	 * @param LegacyToRegisterMigratorInterface $migrator The legacy → OR migrator service.
	 */
	public function __construct(
		private readonly LegacyToRegisterMigratorInterface $migrator,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command name, description, and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/openconnector-storage-migration/spec.md#requirement-migration-class-must-provision-the-register-via-importfromapp
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:migrate-storage')
			->setDescription('Migrate Integriq legacy tables into OpenRegister storage')
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
				'Run a single entity (one of: ' . implode(', ', self::VALID_SLUGS) . ')'
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
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return integer 0 on success; non-zero on validation or migration failure.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);

		$refusal = $this->optionError(input: $input);
		if ($refusal !== null) {
			$io->error($refusal);
			return Command::FAILURE;
		}

		return $this->runChecked(io: $io, input: $input);

	}//end execute()

	/**
	 * Why the options cannot run, if they cannot: an unknown entity, or a batch size that is not a number in range.
	 *
	 * @param InputInterface $input The command input.
	 *
	 * @return string|null The error, or null.
	 */
	private function optionError(InputInterface $input): ?string {
		$entity = $input->getOption('entity');
		if ($entity !== null && in_array($entity, self::VALID_SLUGS, true) === false) {
			return sprintf(
				'Unknown entity "%s". Valid slugs: %s',
				(string)$entity,
				implode(', ', self::VALID_SLUGS)
			);
		}

		return $this->batchSizeError(raw: (string)$input->getOption('batch-size'));

	}//end optionError()

	/**
	 * Why a batch size cannot run, if it cannot.
	 *
	 * @param string $raw The batch-size option as given.
	 *
	 * @return string|null The error, or null for an integer in [100, 100000].
	 */
	private function batchSizeError(string $raw): ?string {
		if (preg_match('/^\d+$/', $raw) !== 1) {
			return sprintf('batch-size must be an integer, got "%s"', $raw);
		}

		$batchSize = (int)$raw;
		if ($batchSize < 100 || $batchSize > 100000) {
			return sprintf('batch-size must be in [100, 100000], got %d', $batchSize);
		}

		return null;

	}//end batchSizeError()

	/**
	 * Verify, or migrate with the checked options.
	 *
	 * @param SymfonyStyle   $io    Styled console I/O.
	 * @param InputInterface $input The checked command input.
	 *
	 * @return integer The exit code.
	 */
	private function runChecked(SymfonyStyle $io, InputInterface $input): int {
		if ($input->getOption('verify-only') === true) {
			return $this->runVerify(io: $io);
		}

		return $this->runMigrate(
			io: $io,
			dryRun: (bool)$input->getOption('dry-run'),
			entity: $this->entitySlug(input: $input),
			batchSize: (int)$input->getOption('batch-size')
		);

	}//end runChecked()

	/**
	 * The single entity the run is limited to.
	 *
	 * @param InputInterface $input The checked command input.
	 *
	 * @return string|null The slug, or null for a full run.
	 */
	private function entitySlug(InputInterface $input): ?string {
		$entity = $input->getOption('entity');
		if ($entity === null) {
			return null;
		}

		return (string)$entity;

	}//end entitySlug()

	/**
	 * Run the migration and render the per-entity summary table.
	 *
	 * @param SymfonyStyle $io Styled console I/O.
	 * @param boolean $dryRun Whether to run without writing.
	 * @param string|null $entity Single-entity slug, or null for a full run.
	 * @param integer $batchSize Batch size.
	 *
	 * @return integer 0 on a clean run; non-zero if any entity reported skips/errors.
	 */
	private function runMigrate(SymfonyStyle $io, bool $dryRun, ?string $entity, int $batchSize): int {
		$this->noteDryRun(io: $io, dryRun: $dryRun);
		try {
			$results = $this->migrator->migrateAll(dryRun: $dryRun, entitySlug: $entity, batchSize: $batchSize);
		} catch (\Throwable $e) {
			return $this->migrationFailed(io: $io, exception: $e);
		}

		$io->table(['entity', 'legacy', 'migrated', 'skipped', 'fkRewrites', 'ms', 'error'], $this->buildResultRows(results: $results));
		if ($this->resultsHaveIssue(results: $results) === true) {
			return $this->notFlipped(io: $io);
		}

		$io->success($this->successMessage(dryRun: $dryRun, entity: $entity));
		return Command::SUCCESS;
	}//end runMigrate()

	/**
	 * Say that a dry run writes nothing.
	 *
	 * @param SymfonyStyle $io     Styled console I/O.
	 * @param boolean      $dryRun Whether this is a dry run.
	 *
	 * @return void
	 */
	private function noteDryRun(SymfonyStyle $io, bool $dryRun): void {
		if ($dryRun === true) {
			$io->note('Dry-run: counting legacy rows only — no data is written and the flag is unchanged.');
		}
	}//end noteDryRun()

	/**
	 * Say why the migration stopped: the migrator's refusal as is, any other failure prefixed.
	 *
	 * @param SymfonyStyle $io        Styled console I/O.
	 * @param \Throwable   $exception The failure.
	 *
	 * @return integer The failure exit code.
	 */
	private function migrationFailed(SymfonyStyle $io, \Throwable $exception): int {
		if ($exception instanceof InvalidArgumentException) {
			$io->error($exception->getMessage());
			return Command::FAILURE;
		}

		// Log-friendly, no stack trace to stdout per ADR-005.
		$io->error('Migration failed: ' . $exception->getMessage());
		return Command::FAILURE;
	}//end migrationFailed()

	/**
	 * Warn that skips or errors kept the storage flag down.
	 *
	 * @param SymfonyStyle $io Styled console I/O.
	 *
	 * @return integer The failure exit code.
	 */
	private function notFlipped(SymfonyStyle $io): int {
		$io->warning(
			'One or more entities reported skips or errors — storage_migrated was NOT flipped.'
			. ' Re-run with --entity=<slug> to retry the affected entity.'
		);
		return Command::FAILURE;
	}//end notFlipped()

	/**
	 * Map the migrator's per-entity result array into console table rows.
	 *
	 * @param array<int, array<string, mixed>> $results Per-entity results.
	 *
	 * @return array<int, array<int, int|string>>
	 */
	private function buildResultRows(array $results): array {
		return array_values(array_map(fn (array $perEntity): array => $this->resultRow(perEntity: $perEntity), $results));
	}//end buildResultRows()

	/**
	 * One entity's result as a console table row.
	 *
	 * @param array<string, mixed> $perEntity The entity's result.
	 *
	 * @return array<int, int|string>
	 */
	private function resultRow(array $perEntity): array {
		return [
			(string)($perEntity['slug'] ?? '?'),
			(int)($perEntity['legacyCount'] ?? 0),
			(int)($perEntity['migratedCount'] ?? 0),
			(int)($perEntity['skipped'] ?? 0),
			(int)($perEntity['fkRewrites'] ?? 0),
			(int)($perEntity['elapsedMs'] ?? 0),
			$this->errorCell(perEntity: $perEntity),
		];
	}//end resultRow()

	/**
	 * An entity's error for the table: the error, or a dash when there is none.
	 *
	 * @param array<string, mixed> $perEntity The entity's result.
	 *
	 * @return string The cell.
	 */
	private function errorCell(array $perEntity): string {
		$error = (string)($perEntity['error'] ?? '');
		if ($error === '') {
			return '-';
		}

		return $error;
	}//end errorCell()

	/**
	 * Whether any entity reported a skip or an error.
	 *
	 * @param array<int, array<string, mixed>> $results Per-entity results.
	 *
	 * @return boolean
	 */
	private function resultsHaveIssue(array $results): bool {
		foreach ($results as $perEntity) {
			if ((int)($perEntity['skipped'] ?? 0) > 0 || (string)($perEntity['error'] ?? '') !== '') {
				return true;
			}
		}

		return false;
	}//end resultsHaveIssue()

	/**
	 * Build the success message for a clean run.
	 *
	 * @param boolean $dryRun Whether this was a dry-run.
	 * @param string|null $entity Single-entity slug, or null for a full run.
	 *
	 * @return string
	 */
	private function successMessage(bool $dryRun, ?string $entity): string {
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
	private function runVerify(SymfonyStyle $io): int {
		try {
			$report = $this->migrator->verifyRowCounts();
		} catch (\Throwable $e) {
			$io->error('Verification failed: ' . $e->getMessage());
			return Command::FAILURE;
		}

		$io->table(['entity', 'legacy', 'register', 'skipped', 'parity'], $this->parityRows(report: $report));

		if ($this->allEqual(report: $report) === true) {
			$io->success('All 15 entities report legacy↔register row-count parity.');
			return Command::SUCCESS;
		}

		$io->error('One or more entities show a row-count mismatch — investigate before draining legacy tables.');
		return Command::FAILURE;
	}//end runVerify()

	/**
	 * The parity report as console table rows.
	 *
	 * @param array<int, array<string, mixed>> $report Per-entity parity.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function parityRows(array $report): array {
		$rows = [];
		foreach ($report as $entry) {
			$parityCell = 'MISMATCH';
			if ($entry['equal'] === true) {
				$parityCell = 'OK';
			}

			$rows[] = [$entry['slug'], $entry['legacy'], $entry['register'], $entry['skipped'], $parityCell];
		}

		return $rows;
	}//end parityRows()

	/**
	 * Whether no entity reports a mismatch.
	 *
	 * @param array<int, array<string, mixed>> $report Per-entity parity.
	 *
	 * @return boolean
	 */
	private function allEqual(array $report): bool {
		foreach ($report as $entry) {
			if ($entry['equal'] === false) {
				return false;
			}
		}

		return true;
	}//end allEqual()
}//end class
