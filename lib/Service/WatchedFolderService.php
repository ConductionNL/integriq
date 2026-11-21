<?php

/**
 * Integriq Watched Folder Service: ingests a watched file once, and sweeps the watched folders.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Service\WatchedFolder\WatchedFileMarker;
use OCA\Integriq\Service\WatchedFolder\WatchedFileDelivery;
use OCA\Integriq\Service\WatchedFolder\WatchedFolder;
use OCA\Integriq\Service\WatchedFolder\WatchedFolders;
use OCP\Files\File;
use Throwable;

/**
 * Design D6, once per file. The ingest job asks the synchronization contract
 * keyed `nextcloud-file:{fileId}` first: a contract that exists ends it. A
 * file younger than `stableSeconds` is left for the next sweep. A target that
 * does not resolve stops before the file is read. Otherwise the file is
 * written as a draft, and only after that write is the contract made and the
 * file marked ingested; a failed write marks it failed and makes no contract.
 *
 * The sweep is the safety net behind the file event listener: it lists each
 * watched folder and queues the same job for every file not yet in a contract.
 *
 * A synchronization with `target: intake` hands its files to the app it
 * names instead (sources-sftp-adapter-intake-hand-over). Only an acceptance
 * makes the contract and marks the file; a hand-over nobody accepts leaves
 * it in place, unmarked, and counts as `unclaimed`.
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */
class WatchedFolderService {

	/**
	 * The prefix of a watched file's origin id in its contract.
	 *
	 * @var string
	 */
	public const ORIGIN_PREFIX = 'nextcloud-file:';

	/**
	 * Constructor.
	 *
	 * @param WatchedFolders                          $folders   The watched folders, their files and the job queue.
	 * @param SynchronizationContractServiceInterface $contracts The once-per-file record.
	 * @param WatchedFileDelivery                     $delivery  Writes the draft, or hands the file to an intake app.
	 * @param WatchedFileMarker                       $marker    Moves or tags the file afterwards.
	 */
	public function __construct(
		private readonly WatchedFolders $folders,
		private readonly SynchronizationContractServiceInterface $contracts,
		private readonly WatchedFileDelivery $delivery,
		private readonly WatchedFileMarker $marker,
	) {
	}//end __construct()

	/**
	 * Ingest one file for one synchronization, once.
	 *
	 * @param string   $synchronizationId The synchronization.
	 * @param int      $fileId            The file.
	 * @param int|null $now               The clock, for tests.
	 *
	 * @return string What happened: `unwatched`, `known`, `gone`, `young`, `unresolved`, `failed`, `unclaimed`,
	 *                `handed-over` or `ingested`.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004
	 */
	public function ingest(string $synchronizationId, int $fileId, ?int $now=null): string {
		$watched = $this->folders->byId(synchronizationId: $synchronizationId);
		if ($watched === null) {
			return 'unwatched';
		}

		$contract = $this->contracts->findBySyncAndOrigin(synchronizationId: $synchronizationId, originId: self::ORIGIN_PREFIX . $fileId);
		if ($contract !== null) {
			return 'known';
		}

		$file = $this->folders->fileIn(watched: $watched, fileId: $fileId);
		if ($file === null) {
			return 'gone';
		}

		if ((($now ?? time()) - (int)$file->getMTime()) < $watched->stableSeconds()) {
			return 'young';
		}

		return $this->write(watched: $watched, file: $file, now: ($now ?? time()));
	}//end ingest()

	/**
	 * Queue the ingest of every file in every watched folder that has no contract yet.
	 *
	 * @return array{queued: int, stopped: list<string>} How many jobs were added, and the mapping synchronizations
	 *                                                  stopped before listing their folder because their target does not resolve.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function sweep(): array {
		$result = ['queued' => 0, 'stopped' => []];
		foreach ($this->folders->all() as $watched) {
			if ($watched->intakeApp() === null && $watched->target() === null) {
				$result['stopped'][] = $watched->synchronizationId();
				continue;
			}

			$result['queued'] += $this->sweepOne(watched: $watched);
		}

		return $result;
	}//end sweep()

	/**
	 * Queue the files of one watched folder that have no contract yet.
	 *
	 * @param WatchedFolder $watched The watched folder.
	 *
	 * @return int How many jobs were added.
	 */
	private function sweepOne(WatchedFolder $watched): int {
		$known  = $this->knownOrigins(synchronizationId: $watched->synchronizationId());
		$queued = 0;
		foreach ($this->folders->listed(watched: $watched) as $fileId) {
			if (isset($known[self::ORIGIN_PREFIX . $fileId]) === true) {
				continue;
			}

			if ($this->folders->queue(fileId: $fileId, synchronizationId: $watched->synchronizationId()) === true) {
				$queued++;
			}
		}

		return $queued;
	}//end sweepOne()

	/**
	 * Deliver the file, then make its contract and mark it; or mark it failed; or, unclaimed, leave it.
	 *
	 * @param WatchedFolder $watched The watched folder.
	 * @param File          $file    The file, stable and not yet in a contract.
	 * @param int           $now     The clock.
	 *
	 * @return string `unresolved`, `failed`, `unclaimed`, `handed-over` or `ingested`.
	 */
	private function write(WatchedFolder $watched, File $file, int $now): string {
		if ($watched->intakeApp() === null && $watched->target() === null) {
			return 'unresolved';
		}

		try {
			$targetId = $this->delivery->deliver(watched: $watched, file: $file);
		} catch (Throwable $e) {
			$this->marker->failed(watched: $watched, file: $file, reason: $e->getMessage());
			return 'failed';
		}

		if ($targetId === null) {
			// No intake app accepted it: the file stays where it is, unmarked, for the next sweep.
			return 'unclaimed';
		}

		$this->contracts->createFromArray(
			object: [
				'synchronizationId' => $watched->synchronizationId(),
				'originId'          => self::ORIGIN_PREFIX . $file->getId(),
				'targetId'          => $targetId,
				'targetLastAction'  => 'create',
				'sourceLastSynced'  => date(DATE_ATOM, $now),
			]
		);
		$this->marker->succeeded(watched: $watched, file: $file);
		if ($watched->intakeApp() !== null) {
			return 'handed-over';
		}

		return 'ingested';
	}//end write()

	/**
	 * The origin ids a synchronization has a contract for.
	 *
	 * @param string $synchronizationId The synchronization.
	 *
	 * @return array<string, true>
	 */
	private function knownOrigins(string $synchronizationId): array {
		$known = [];
		foreach ($this->contracts->findAllObjects(filters: ['synchronizationId' => $synchronizationId]) as $contract) {
			if (is_object($contract) === true && method_exists($contract, 'jsonSerialize') === true) {
				$contract = $contract->jsonSerialize();
			}

			if (is_array($contract) === true && ($contract['synchronizationId'] ?? null) === $synchronizationId) {
				$known[(string)($contract['originId'] ?? '')] = true;
			}
		}

		return $known;
	}//end knownOrigins()
}//end class
