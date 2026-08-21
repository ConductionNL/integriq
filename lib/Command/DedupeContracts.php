<?php

/**
 * OCC command: openconnector:contracts:dedupe.
 *
 * Removes the duplicate `SynchronizationContract` rows left behind by the
 * identity defect fixed in #1306/#1307/#1309/#1311. Until that fix,
 * `synchronizeContract()` minted a FRESH uuid on every rerun — a contract
 * loaded back from OpenRegister carries `id`, never `uuid` — so every writer
 * faithfully created a new row instead of updating the existing one. One
 * (synchronization, origin) pair accumulated one contract per run.
 *
 * WHY THIS IS A COMMAND AND NOT ENGINE BEHAVIOUR. `synchronization-engine`
 * REQ-013 is explicit that duplicate contracts are SURFACED, never silently
 * removed, because an automated cleanup can delete the wrong one and the engine
 * would then re-create the object it maps. That requirement governs the ENGINE.
 * This is a deliberate, human-invoked remediation with a dry run by default —
 * the escape hatch REQ-013 implies, not a hole in it.
 *
 * WHICH ROW SURVIVES. The one with the newest `sourceLastChecked`, because that
 * is the row carrying the freshest `originHash`/`targetHash` and therefore the
 * one that lets the skip test hold on the next run. Ties break on uuid so the
 * choice is deterministic and a dry run predicts exactly what an apply does.
 *
 * Usage:
 *   occ openconnector:contracts:dedupe                        # dry run, every synchronization
 *   occ openconnector:contracts:dedupe --apply                # actually delete
 *   occ openconnector:contracts:dedupe --synchronization=<id> # limit to one
 *
 * @category Command
 * @package  OCA\OpenConnector\Command
 *
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Command;

use OCA\OpenConnector\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Collapse duplicate synchronization contracts to one per (synchronization, origin).
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
 */
class DedupeContracts extends Command {

	/**
	 * How many uuids are handed to one bulk delete.
	 *
	 * @var int
	 */
	private const DELETE_BATCH = 500;

	/**
	 * Constructor.
	 *
	 * @param SynchronizationContractService $contracts The contract lifecycle service.
	 * @param OrObjectService                $objects   The OpenRegister object service.
	 */
	public function __construct(
		private readonly SynchronizationContractService $contracts,
		private readonly OrObjectService $objects,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	protected function configure(): void {
		$this->setName(name: 'openconnector:contracts:dedupe')
			->setDescription(
				'Collapse duplicate SynchronizationContract rows to one per '
				. '(synchronization, origin), keeping the newest by sourceLastChecked. '
				. 'Dry run unless --apply is given.'
			)
			->addOption(
				'apply',
				null,
				InputOption::VALUE_NONE,
				'Actually delete the duplicates. Without this flag the command only reports '
				. 'what it would delete.'
			)
			->addOption(
				'synchronization',
				null,
				InputOption::VALUE_REQUIRED,
				'Limit to one synchronization id (default: every synchronization that has contracts).'
			);

	}//end configure()

	/**
	 * Decide which contracts to delete for ONE (synchronization, origin) grouping.
	 *
	 * Pure, so the keep-rule is testable without a database: the caller supplies
	 * the contract payloads and gets back the uuids that should go.
	 *
	 * @param array $contracts Contract payload arrays for a single synchronization.
	 *
	 * @return array{keep: array<string, string>, delete: string[]} Survivors keyed by
	 *         "originId", and the uuids to remove.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	public static function planDeletions(array $contracts): array {
		$byOrigin = [];

		foreach ($contracts as $contract) {
			$origin = (string)(($contract['originId'] ?? ''));
			$uuid = (string)((($contract['uuid'] ?? null) ?? ($contract['id'] ?? '')));

			// A row we cannot identify cannot be deleted safely, and a row with no
			// origin cannot be grouped — leave both alone rather than guess.
			if ($origin === '' || $uuid === '') {
				continue;
			}

			$byOrigin[$origin][] = [
				'uuid' => $uuid,
				'checked' => (string)(($contract['sourceLastChecked'] ?? '')),
			];
		}

		$keep = [];
		$delete = [];

		foreach ($byOrigin as $origin => $rows) {
			// Newest sourceLastChecked wins; uuid breaks ties so a dry run and an
			// apply always choose the same survivor.
			usort(
				$rows,
				static function (array $a, array $b): int {
					$byChecked = strcmp($b['checked'], $a['checked']);
					if ($byChecked !== 0) {
						return $byChecked;
					}

					return strcmp($b['uuid'], $a['uuid']);
				}
			);

			$keep[$origin] = $rows[0]['uuid'];

			foreach (array_slice($rows, 1) as $row) {
				$delete[] = $row['uuid'];
			}
		}

		return ['keep' => $keep, 'delete' => $delete];

	}//end planDeletions()

	/**
	 * Execute the dedupe.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 on success.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$apply = (bool)$input->getOption('apply');

		$synchronizationIds = $this->synchronizationIds(only: $input->getOption('synchronization'));
		if ($synchronizationIds === []) {
			$output->writeln('<comment>No synchronizations with contracts found.</comment>');

			return 0;
		}

		$mode = '<comment>DRY RUN</comment> — nothing will be deleted';
		if ($apply === true) {
			$mode = '<info>Applying</info>';
		}

		$output->writeln(sprintf('%s across %d synchronization(s).', $mode, count($synchronizationIds)));

		$totals = ['scanned' => 0, 'planned' => 0, 'deleted' => 0, 'skipped' => 0, 'affected' => 0];

		foreach ($synchronizationIds as $synchronizationId) {
			$this->dedupeOne(
				synchronizationId: $synchronizationId,
				apply: $apply,
				output: $output,
				totals: $totals
			);
		}

		return $this->summarise(output: $output, apply: $apply, totals: $totals);

	}//end execute()

	/**
	 * Dedupe one synchronization's contracts, accumulating into $totals.
	 *
	 * @param string          $synchronizationId The synchronization to walk.
	 * @param bool            $apply             Whether to actually delete.
	 * @param OutputInterface $output            Console output.
	 * @param array           $totals            Running totals, modified in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	private function dedupeOne(
		string $synchronizationId,
		bool $apply,
		OutputInterface $output,
		array &$totals
	): void {
		$rows = $this->contracts->findAllObjects(filters: ['synchronizationId' => $synchronizationId]);
		// FindAllObjects() returns ObjectEntity[], so an is_array() guard here
		// would be dead code — phpstan is right to call it out.
		$payloads = array_map(static fn ($row): array => $row->jsonSerialize(), $rows);

		$totals['scanned'] += count($payloads);
		$plan = self::planDeletions(contracts: $payloads);
		$doomed = $plan['delete'];

		if ($doomed === []) {
			return;
		}

		$totals['affected']++;
		$totals['planned'] += count($doomed);

		$output->writeln(
			sprintf(
				'  %s: %d contract(s), %d duplicate(s) to remove, %d origin(s) kept',
				$synchronizationId,
				count($payloads),
				count($doomed),
				count($plan['keep'])
			)
		);

		if ($apply === false) {
			return;
		}

		$outcome = $this->deleteBatches(uuids: $doomed);
		$totals['deleted'] += $outcome['deleted'];
		$totals['skipped'] += $outcome['skipped'];

	}//end dedupeOne()

	/**
	 * Delete uuids in batches and report what OpenRegister actually removed.
	 *
	 * @param string[] $uuids The contract uuids to delete.
	 *
	 * @return array{deleted: int, skipped: int} What happened.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	private function deleteBatches(array $uuids): array {
		$deleted = 0;
		$skipped = 0;

		foreach (array_chunk($uuids, self::DELETE_BATCH) as $batch) {
			// `_rbac`/`_multitenancy` default to TRUE and silently FILTER the uuid
			// list, so a run from occ deleted nothing while the command still
			// reported the plan as if it had. This is an admin remediation in a
			// system context — the posture the engine uses for its own bookkeeping.
			$result = $this->objects->deleteObjects(uuids: $batch, _rbac: false, _multitenancy: false);

			// COUNT THE RESULT, NEVER THE PLAN. Reporting the plan is how this
			// command claimed 6485 deletions while the table was untouched.
			$deleted += count(($result['deleted_uuids'] ?? []));
			$skipped += count(($result['skipped_uuids'] ?? []));
		}

		return ['deleted' => $deleted, 'skipped' => $skipped];

	}//end deleteBatches()

	/**
	 * Print the run summary and decide the exit code.
	 *
	 * @param OutputInterface $output Console output.
	 * @param bool            $apply  Whether deletions were attempted.
	 * @param array           $totals The accumulated counts.
	 *
	 * @return int 0 on success, 1 when fewer rows were deleted than planned.
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-a-contract-is-upserted-on-its-own-identity-req-025
	 */
	private function summarise(OutputInterface $output, bool $apply, array $totals): int {
		$output->writeln('');
		$output->writeln(sprintf('Contracts scanned:    %d', $totals['scanned']));
		$output->writeln(sprintf('Synchronizations hit: %d', $totals['affected']));
		$output->writeln(sprintf('Duplicates planned:   %d', $totals['planned']));

		if ($apply === false) {
			if ($totals['planned'] > 0) {
				$output->writeln('');
				$output->writeln('<comment>Re-run with --apply to delete them.</comment>');
			}

			return 0;
		}

		$output->writeln(sprintf('Duplicates DELETED:   %d', $totals['deleted']));
		$output->writeln(sprintf('Skipped by OpenRegister: %d', $totals['skipped']));

		// A command that plans N deletions, deletes 0 and exits 0 is worse than one
		// that fails: it reports the cleanup as done. Fail loudly on any shortfall.
		if ($totals['deleted'] !== $totals['planned']) {
			$output->writeln('');
			$output->writeln(
				sprintf(
					'<error>Planned %d deletions but OpenRegister removed %d. The duplicates are '
					. 'still there — do not treat this run as a cleanup.</error>',
					$totals['planned'],
					$totals['deleted']
				)
			);

			return 1;
		}

		return 0;

	}//end summarise()

	/**
	 * The synchronization ids to walk.
	 *
	 * @param string|null $only A single id to limit to, or null for all.
	 *
	 * @return string[] The ids.
	 */
	private function synchronizationIds(?string $only): array {
		if ($only !== null && $only !== '') {
			return [$only];
		}

		$found = $this->objects->findAll(
			config: ['filters' => ['register' => 'openconnector', 'schema' => 'synchronization']]
		);

		$results = ($found['results'] ?? $found);
		$ids = [];

		foreach ($results as $row) {
			$payload = $row;
			if (is_array($payload) === false) {
				$payload = $row->jsonSerialize();
			}

			$id = (string)((($payload['id'] ?? null) ?? ($payload['uuid'] ?? '')));
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;

	}//end synchronizationIds()
}//end class
