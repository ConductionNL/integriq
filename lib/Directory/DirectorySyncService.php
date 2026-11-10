<?php

/**
 * Integriq Directory Sync Service.
 *
 * Runs one directory connection: reads it, works out what would change, refuses
 * when a refusal is the right answer, and only then writes through
 * `IGroupManager`. Nextcloud keeps the accounts. Integriq keeps the connection,
 * the mapping, the schedule and the record of what each run changed.
 *
 * The unit of work is a membership, not a login. A sync that only adds is a
 * sync that hides a leaver, so a membership the directory dropped is removed,
 * and what that account still holds is reported before anyone has to go looking
 * for it.
 *
 * The guards are the synchronization engine's own, not a second copy of them:
 * the fetch-completeness gate, the deletion-ratio threshold and its minimum
 * population all come from `SynchronizationService`, and a guarded run
 * dispatches the same `SynchronizationDeletionGuardedEvent` an ordinary sync
 * does.
 *
 * @category Directory
 * @package  OCA\Integriq\Directory
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Directory;

use OCA\Integriq\Event\SynchronizationDeletionGuardedEvent;
use OCA\Integriq\Exception\DirectorySyncRefusalException;
use OCA\Integriq\Service\SynchronizationLogServiceInterface;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs a directory connection and records what it changed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */
class DirectorySyncService implements DirectorySyncServiceInterface {

	/**
	 * Guard reason: the directory did not answer completely.
	 *
	 * @var string
	 */
	public const GUARD_FETCH_INCOMPLETE = 'fetch_incomplete';

	/**
	 * Guard reason: the removals crossed the configured deletion ratio.
	 *
	 * @var string
	 */
	public const GUARD_RATIO_EXCEEDED = 'ratio_threshold_exceeded';

	/**
	 * Constructor.
	 *
	 * @param DirectorySource $directorySource Reads the connection.
	 * @param GroupMappingResolver $mappingResolver Resolves the declared mapping.
	 * @param OpenWorkReporter $openWorkReporter Asks consumers what a leaver still holds.
	 * @param SynchronizationLogServiceInterface $logService Writes the run record.
	 * @param IGroupManager $groupManager Nextcloud's group model.
	 * @param IUserManager $userManager Nextcloud's account model.
	 * @param IEventDispatcher $eventDispatcher Dispatches the guard event.
	 * @param IL10N $l10n Translations, so a refusal reads as a sentence.
	 * @param LoggerInterface $logger Logger for per-item failures.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function __construct(
		private readonly DirectorySource $directorySource,
		private readonly GroupMappingResolver $mappingResolver,
		private readonly OpenWorkReporter $openWorkReporter,
		private readonly SynchronizationLogServiceInterface $logService,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run every enabled directory connection.
	 *
	 * @param boolean $dryRun Whether this is a preview that writes no membership.
	 *
	 * @return array<int,array<string,mixed>> One run record per connection.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function runAll(bool $dryRun = false): array {
		$records = [];

		foreach ($this->directorySource->findConnections() as $connection) {
			if (($connection->getObject()['isEnabled'] ?? true) === false) {
				continue;
			}

			$records[] = $this->run(connection: $connection, dryRun: $dryRun);
		}

		return $records;

	}//end runAll()

	/**
	 * Run one directory connection.
	 *
	 * @param ObjectEntity $connection The directory connection Source.
	 * @param boolean $dryRun Whether this is a preview that writes no membership.
	 * @param boolean $confirmRemovals Whether an administrator confirmed a previously guarded run.
	 *
	 * @return array<string,mixed> The run record.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function run(ObjectEntity $connection, bool $dryRun = false, bool $confirmRemovals = false): array {
		$run = new DirectorySyncRun(connection: $connection, startedAt: microtime(true));
		$run->dryRun = $dryRun;
		$run->confirmRemovals = $confirmRemovals;

		try {
			$snapshot = $this->directorySource->read(connection: $connection);
			$createdGroups = $this->mappingResolver->ensureTargets(configuration: $run->configuration(), dryRun: $dryRun);
		} catch (DirectorySyncRefusalException $refusal) {
			return $this->finish(run: $run, record: $this->refusalRecord(refusal: $refusal, dryRun: $dryRun));
		}

		$run->plan = $this->plan(snapshot: $snapshot, configuration: $run->configuration());
		$guard = $this->guard(run: $run, snapshot: $snapshot);

		$record = $this->baseRecord(run: $run, snapshot: $snapshot);
		$record['groupsCreated'] = $createdGroups;
		$record['guard'] = $guard;

		if ($guard !== null) {
			return $this->finish(run: $run, record: $this->heldBack(record: $record));
		}

		return $this->finish(run: $run, record: $this->applyPlan(run: $run, record: $record));

	}//end run()

	/**
	 * Mark a guarded run: stopped BEFORE writing, so the counts describe what
	 * the run would have done, and nothing was changed.
	 *
	 * @param array<string,mixed> $record The run record.
	 *
	 * @return array<string,mixed> The guarded record.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
	 */
	private function heldBack(array $record): array {
		$record['status'] = 'guarded';
		$record['membershipsAdded'] = 0;
		$record['membershipsRemoved'] = 0;

		return $record;

	}//end heldBack()

	/**
	 * Apply the plan unless the run is a preview, then report the open work.
	 *
	 * @param DirectorySyncRun    $run    The run.
	 * @param array<string,mixed> $record The run record.
	 *
	 * @return array<string,mixed> The successful record.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	private function applyPlan(DirectorySyncRun $run, array $record): array {
		if ($run->dryRun === false) {
			$applied = $this->apply(plan: $run->plan);
			$record['membershipsAdded'] = $applied['added'];
			$record['membershipsRemoved'] = $applied['removed'];
			$record['failures'] = array_merge($record['failures'], $applied['failures']);
		}

		$record['openWork'] = $this->openWork(plan: $run->plan, configuration: $run->configuration());
		$record['status'] = 'success';

		return $record;

	}//end applyPlan()

	/**
	 * Work out what a run would change, without changing anything.
	 *
	 * @param DirectorySnapshot $snapshot What the directory answered.
	 * @param array<string,mixed> $configuration The connection configuration.
	 *
	 * @return array<string,mixed> The plan: additions, removals, managed groups and current totals.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
	 */
	public function plan(DirectorySnapshot $snapshot, array $configuration): array {
		$managedGroups = $this->mappingResolver->managedGroups(configuration: $configuration);
		$desired = $this->desiredMemberships(snapshot: $snapshot, configuration: $configuration);
		$current = $this->currentMemberships(managedGroups: $managedGroups);

		$additions = [];
		$removals = [];
		$currentTotal = 0;

		foreach ($managedGroups as $groupId) {
			$currentMembers = ($current[$groupId] ?? []);
			$desiredMembers = ($desired[$groupId] ?? []);
			$currentTotal += count($currentMembers);

			foreach (array_diff($desiredMembers, $currentMembers) as $userId) {
				$additions[] = ['userId' => $userId, 'group' => $groupId];
			}

			foreach (array_diff($currentMembers, $desiredMembers) as $userId) {
				$removals[] = ['userId' => $userId, 'group' => $groupId];
			}
		}

		return [
			'managedGroups' => $managedGroups,
			'additions' => $additions,
			'removals' => $removals,
			'currentTotal' => $currentTotal,
			'failures' => $snapshot->getFailures(),
		];

	}//end plan()

	/**
	 * The memberships the directory says should exist, keyed by Nextcloud group.
	 *
	 * @param DirectorySnapshot $snapshot What the directory answered.
	 * @param array<string,mixed> $configuration The connection configuration.
	 *
	 * @return array<string,array<int,string>> Account ids per Nextcloud group id.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	private function desiredMemberships(DirectorySnapshot $snapshot, array $configuration): array {
		$desired = [];

		foreach ($snapshot->getEntries() as $entry) {
			if ($entry->isActive() === false) {
				// An inactive account keeps no membership. Its account is left
				// alone: deactivation is the SCIM path's job, never a delete.
				continue;
			}

			foreach ($this->mappingResolver->resolve(entry: $entry, configuration: $configuration) as $groupId) {
				$desired[$groupId][] = $entry->getUserId();
			}
		}

		foreach ($desired as $groupId => $members) {
			$desired[$groupId] = array_values(array_unique($members));
		}

		return $desired;

	}//end desiredMemberships()

	/**
	 * The memberships Nextcloud holds today for the managed groups.
	 *
	 * @param array<int,string> $managedGroups The Nextcloud group ids the mapping owns.
	 *
	 * @return array<string,array<int,string>> Account ids per Nextcloud group id.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	private function currentMemberships(array $managedGroups): array {
		$current = [];

		foreach ($managedGroups as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				$current[$groupId] = [];
				continue;
			}

			$members = [];
			foreach ($group->getUsers() as $user) {
				$members[] = $user->getUID();
			}

			$current[$groupId] = $members;
		}

		return $current;

	}//end currentMemberships()

	/**
	 * Whether this run must stop before writing, and why.
	 *
	 * @param DirectorySyncRun  $run      The run, with its plan.
	 * @param DirectorySnapshot $snapshot What the directory answered.
	 *
	 * @return array<string,mixed>|null The guard, or null when the run may proceed.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
	 */
	private function guard(DirectorySyncRun $run, DirectorySnapshot $snapshot): ?array {
		if (count($run->plan['removals']) === 0) {
			return null;
		}

		// An explicit confirmation is an administrator taking the decision the
		// guard exists to hand them. It is recorded on the run.
		if ($run->confirmRemovals === true) {
			return null;
		}

		if ($snapshot->isComplete() === false) {
			return $this->incompleteReadGuard(run: $run);
		}

		return $this->ratioGuard(run: $run);

	}//end guard()

	/**
	 * Guard a run whose directory read did not complete.
	 *
	 * @param DirectorySyncRun $run The run, with its plan.
	 *
	 * @return array<string,mixed> The guard record.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
	 */
	private function incompleteReadGuard(DirectorySyncRun $run): array {
		$message = $this->l10n->t('The directory did not answer completely, so nothing was removed. Run it again, or confirm the removals to continue.');

		return $this->guarded(
			event: new SynchronizationDeletionGuardedEvent(
				synchronizationId: (string)$run->connection->getUuid(),
				reason: self::GUARD_FETCH_INCOMPLETE,
				ratio: null,
				threshold: null,
				candidateCount: count($run->plan['removals']),
				totalContracts: (int)$run->plan['currentTotal']
			),
			message: $message
		);

	}//end incompleteReadGuard()

	/**
	 * Guard a complete run whose removals cross the connection's ratio.
	 *
	 * @param DirectorySyncRun $run The run, with its plan.
	 *
	 * @return array<string,mixed>|null The guard record, or null when the run may proceed.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
	 */
	private function ratioGuard(DirectorySyncRun $run): ?array {
		$total = (int)$run->plan['currentTotal'];
		if ($total < SynchronizationServiceInterface::MIN_CONTRACTS_FOR_DELETION_RATIO_GUARD) {
			// A percentage computed from a handful of memberships is not a
			// signal, which is the engine's own reasoning for this floor.
			return null;
		}

		$threshold = (float)($run->configuration()['deletionRatioThreshold'] ?? SynchronizationServiceInterface::DEFAULT_DELETION_RATIO_THRESHOLD);
		$removals = count($run->plan['removals']);
		$ratio = ($removals / $total);

		if ($ratio <= $threshold) {
			return null;
		}

		$message = $this->l10n->t(
			'This run would remove %1$s of %2$s memberships, over the %3$s limit for this connection. Nothing was removed. Confirm the removals to continue.',
			[(string)$removals, (string)$total, $this->formatRatio(ratio: $threshold)]
		);

		return $this->guarded(
			event: new SynchronizationDeletionGuardedEvent(
				synchronizationId: (string)$run->connection->getUuid(),
				reason: self::GUARD_RATIO_EXCEEDED,
				ratio: $ratio,
				threshold: $threshold,
				candidateCount: $removals,
				totalContracts: $total
			),
			message: $message
		);

	}//end ratioGuard()

	/**
	 * Dispatch the engine's guard event and record the guarded run.
	 *
	 * @param SynchronizationDeletionGuardedEvent $event   The guard event.
	 * @param string                              $message The sentence an administrator reads.
	 *
	 * @return array<string,mixed> The guard record.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
	 */
	private function guarded(SynchronizationDeletionGuardedEvent $event, string $message): array {
		$this->eventDispatcher->dispatchTyped($event);

		return [
			'reason' => $event->getReason(),
			'message' => $message,
			'ratio' => $event->getRatio(),
			'threshold' => $event->getThreshold(),
			'heldBack' => $event->getCandidateCount(),
			'resumable' => true,
		];

	}//end guarded()

	/**
	 * Apply the plan, isolating each membership write.
	 *
	 * One failing item does not stop the run: it is captured with its reason
	 * and the rest of the plan is applied.
	 *
	 * @param array<string,mixed> $plan The plan.
	 *
	 * @return array<string,mixed> Counts applied and the per-item failures.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	private function apply(array $plan): array {
		$added = 0;
		$removed = 0;
		$failures = [];

		foreach ($plan['additions'] as $item) {
			$failure = $this->write(item: $item, add: true);
			if ($failure === null) {
				$added++;
				continue;
			}

			$failures[] = $failure;
		}

		foreach ($plan['removals'] as $item) {
			$failure = $this->write(item: $item, add: false);
			if ($failure === null) {
				$removed++;
				continue;
			}

			$failures[] = $failure;
		}

		return ['added' => $added, 'removed' => $removed, 'failures' => $failures];

	}//end apply()

	/**
	 * Write one membership, capturing its failure rather than raising it.
	 *
	 * @param array<string,mixed> $item The membership to write.
	 * @param boolean             $add  Whether to add (true) or remove (false).
	 *
	 * @return array<string,mixed>|null The failure, or null when the membership was written.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	private function write(array $item, bool $add): ?array {
		$userId = (string)$item['userId'];
		$groupId = (string)$item['group'];

		try {
			return $this->writeMembership(item: $item, add: $add);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[DirectorySyncService] membership write failed for ' . $userId . ' in ' . $groupId . ': ' . $exception->getMessage(),
				['exception' => $exception]
			);

			return ['userId' => $userId, 'group' => $groupId, 'reason' => $exception->getMessage()];
		}

	}//end write()

	/**
	 * Add or remove one membership; a missing account or group is a failure.
	 *
	 * @param array<string,mixed> $item The membership to write.
	 * @param boolean             $add  Whether to add (true) or remove (false).
	 *
	 * @return array<string,mixed>|null The failure, or null when the membership was written.
	 *
	 * @throws Throwable When Nextcloud refuses the write.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	private function writeMembership(array $item, bool $add): ?array {
		$userId = (string)$item['userId'];
		$groupId = (string)$item['group'];
		$group = $this->groupManager->get($groupId);
		$user = $this->userManager->get($userId);

		if ($group === null || $user === null) {
			return [
				'userId' => $userId,
				'group' => $groupId,
				'reason' => $this->l10n->t('The Nextcloud account or group named on this membership does not exist.'),
			];
		}

		if ($add === true) {
			$group->addUser($user);

			return null;
		}

		$group->removeUser($user);

		return null;

	}//end writeMembership()

	/**
	 * What the accounts losing their last managed membership still hold.
	 *
	 * @param array<string,mixed> $plan The plan.
	 * @param array<string,mixed> $configuration The connection configuration.
	 *
	 * @return array<string,array<string,integer|string>> The report, keyed by account id.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-leavers-open-work-is-reported-never-silently-dropped-req-ds-004
	 */
	private function openWork(array $plan, array $configuration): array {
		$expected = (array)($configuration['openWork']['consumers'] ?? []);

		$stillMapped = [];
		foreach ($plan['additions'] as $item) {
			$stillMapped[(string)$item['userId']] = true;
		}

		$report = [];
		foreach ($plan['removals'] as $item) {
			$userId = (string)$item['userId'];
			if (isset($stillMapped[$userId]) === true || isset($report[$userId]) === true) {
				continue;
			}

			$report[$userId] = $this->openWorkReporter->report(userId: $userId, expectedConsumers: $expected);
		}

		return $report;

	}//end openWork()

	/**
	 * The counts every run record carries.
	 *
	 * @param DirectorySyncRun  $run      The run, with its plan.
	 * @param DirectorySnapshot $snapshot What the directory answered.
	 *
	 * @return array<string,mixed> The record.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	private function baseRecord(DirectorySyncRun $run, DirectorySnapshot $snapshot): array {
		$plan = $run->plan;

		return [
			'dryRun' => $run->dryRun,
			'usersRead' => count($snapshot->getEntries()),
			'groupsRead' => count($snapshot->getDirectoryGroups()),
			'managedGroups' => $plan['managedGroups'],
			'additions' => $plan['additions'],
			'removals' => $plan['removals'],
			'membershipsAdded' => 0,
			'membershipsRemoved' => 0,
			'failures' => $plan['failures'],
			'openWork' => [],
			'groupsCreated' => [],
			'guard' => null,
			'status' => 'success',
		];

	}//end baseRecord()

	/**
	 * The record a refusal produces.
	 *
	 * @param DirectorySyncRefusalException $refusal The refusal.
	 * @param boolean $dryRun Whether this is a preview.
	 *
	 * @return array<string,mixed> The record.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-the-directory-to-group-mapping-is-declared-not-coded-req-ds-002
	 */
	private function refusalRecord(DirectorySyncRefusalException $refusal, bool $dryRun): array {
		return [
			'dryRun' => $dryRun,
			'usersRead' => 0,
			'groupsRead' => 0,
			'managedGroups' => [],
			'additions' => [],
			'removals' => [],
			'membershipsAdded' => 0,
			'membershipsRemoved' => 0,
			'failures' => [],
			'openWork' => [],
			'groupsCreated' => [],
			'guard' => null,
			'status' => 'refused',
			'refusal' => array_merge(['message' => $refusal->getMessage()], $refusal->getContext()),
		];

	}//end refusalRecord()

	/**
	 * Write the run record and answer it.
	 *
	 * @param DirectorySyncRun    $run    The run.
	 * @param array<string,mixed> $record The record.
	 *
	 * @return array<string,mixed> The record, with its run id.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	private function finish(DirectorySyncRun $run, array $record): array {
		$connection = $run->connection;
		$record['connection'] = (string)$connection->getUuid();
		$record['connectionName'] = (string)($connection->getObject()['name'] ?? '');
		$record['kind'] = 'directory';

		$message = 'Success';
		if ($record['status'] !== 'success') {
			$message = (string)($record['refusal']['message'] ?? $record['guard']['message'] ?? $record['status']);
		}

		$log = $this->logService->createFromArray(
			object: [
				'message' => $message,
				'synchronizationId' => $record['connection'],
				'result' => $record,
				'test' => $run->dryRun,
				'executionTime' => (int)round(((microtime(true) - $run->startedAt) * 1000)),
			]
		);

		try {
			$log = $this->logService->update(log: $log);
		} catch (Throwable $exception) {
			// A run that happened but could not be written down is still a run:
			// log it and answer, rather than losing the record of the writes.
			$this->logger->error(
				'[DirectorySyncService] could not persist the run record: ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}

		$record['runId'] = ($log->getUuid() ?? '');

		return $record;

	}//end finish()

	/**
	 * A ratio as a percentage an administrator can read.
	 *
	 * @param float $ratio The ratio, 0.0 to 1.0.
	 *
	 * @return string The percentage.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-run-can-be-previewed-and-a-large-removal-is-guarded-req-ds-005
	 */
	private function formatRatio(float $ratio): string {
		return (string)round(($ratio * 100), 1) . '%';

	}//end formatRatio()
}//end class
