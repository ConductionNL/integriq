<?php

/**
 * Runs a file pickup as a synchronization.
 *
 * A synchronization whose `sourceType` is `sftp` or `ftps` does not fetch
 * objects: it picks the source's new files up into Nextcloud Files with
 * {@see FilePickup}. This class is the bridge between the synchronization
 * engine and that pickup. It remembers which version of each file it has
 * stored in one synchronization contract per file (`originId` the file name,
 * `originHash` its modification time and size), so a file fetched once is not
 * fetched again, and a file the partner sends again with new content is.
 *
 * The files land in the Files of the synchronization's owner, never of a user
 * the configuration names: a synchronization cannot write into somebody
 * else's Files.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles;

use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCP\Files\IRootFolder;

/**
 * One pickup run of a synchronization, with its seen files kept in contracts.
 */
class FilePickupSynchronization {

	/**
	 * The synchronization source types that are a file pickup.
	 */
	public const SOURCE_TYPES = ['sftp', 'ftps'];

	/**
	 * The pickup targets this run can write to.
	 */
	public const TARGETS = ['files'];

	/**
	 * Constructor.
	 *
	 * @param FilePickup                              $pickup     The pickup itself.
	 * @param RemoteFileClient                        $client     The remote file operations, for a test run's listing.
	 * @param SynchronizationContractServiceInterface $contracts  Where the seen file versions are kept.
	 * @param IRootFolder                             $rootFolder Nextcloud Files, for the owner's folder.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FilePickup $pickup,
		private readonly RemoteFileClient $client,
		private readonly SynchronizationContractServiceInterface $contracts,
		private readonly IRootFolder $rootFolder,
	) {

	}//end __construct()

	/**
	 * Whether a synchronization is a file pickup.
	 *
	 * @param array $synchronization The synchronization.
	 *
	 * @return boolean True for an `sftp` or `ftps` source type.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public static function handles(array $synchronization): bool {
		return in_array(($synchronization['sourceType'] ?? null), self::SOURCE_TYPES, true);

	}//end handles()

	/**
	 * Pick the source's new files up, or, in a test run, list what would be picked up.
	 *
	 * @param array   $synchronization The synchronization, with `id` and `@self.owner`.
	 * @param array   $source          The source it reads.
	 * @param boolean $isTest          True to list the new files without fetching, storing or archiving anything.
	 * @param integer|null $now        The current time, for the stability window; null for now.
	 *
	 * @return array{found: int, created: int, updated: int, skipped: int, invalid: int, files: array<string, list<array<string, mixed>>>}
	 *
	 * @throws RemoteFileException When the synchronization or its configuration cannot be run.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function run(array $synchronization, array $source, bool $isTest=false, ?int $now=null): array {
		$synchronizationId = (string)($synchronization['id'] ?? $synchronization['uuid'] ?? '');
		if ($synchronizationId === '') {
			throw new RemoteFileException('A file pickup needs a saved synchronization: its contracts remember the files already picked up.');
		}

		$config = $synchronization['sourceConfig'] ?? [];
		if (is_array($config) === false) {
			throw new RemoteFileException('sourceConfig must be an object.');
		}

		$target = (string)($config['target'] ?? 'files');
		if (in_array($target, self::TARGETS, true) === false) {
			throw new RemoteFileException('sourceConfig.target "' . $target . '" is not supported; a pickup stores files ("files").');
		}

		$known = $this->knownFiles(synchronizationId: $synchronizationId);
		$seen  = array_map(static fn (array $contract): string => (string)($contract['originHash'] ?? ''), $known);

		if ($isTest === true) {
			return $this->listNew(source: $source, config: $config, seen: $seen, now: ($now ?? time()));
		}

		$owner = (string)($synchronization['@self']['owner'] ?? '');
		if ($owner === '') {
			throw new RemoteFileException('A file pickup stores into the Files of the synchronization\'s owner, and this synchronization has no owner.');
		}

		$result = $this->pickup->run(
			source: $source,
			config: $config,
			store: new NextcloudFolderStore(rootFolder: $this->rootFolder, userId: $owner),
			seen: $seen,
			now: $now
		);

		$created = 0;
		$updated = 0;
		foreach ($result['picked'] as $picked) {
			$name     = (string)$picked['name'];
			$contract = [
				'synchronizationId' => $synchronizationId,
				'originId'          => $name,
				'originHash'        => (string)($result['seen'][$name] ?? ''),
				'targetId'          => (string)$picked['storedAt'],
				'targetLastAction'  => 'create',
				'sourceLastSynced'  => date(DATE_ATOM, ($now ?? time())),
			];
			if (isset($known[$name]['id']) === true) {
				// The partner sent the file again with new content; it was stored beside the first copy.
				$contract['targetLastAction'] = 'update';
				$this->contracts->updateFromArray(id: $known[$name]['id'], object: $contract);
				$updated++;
				continue;
			}

			$this->contracts->createFromArray(object: $contract);
			$created++;
		}

		return [
			'found'   => (count($result['picked']) + count($result['failed']) + count($result['skipped'])),
			'created' => $created,
			'updated' => $updated,
			'skipped' => count($result['skipped']),
			'invalid' => count($result['failed']),
			'files'   => ['picked' => $result['picked'], 'failed' => $result['failed'], 'skipped' => $result['skipped']],
		];

	}//end run()

	/**
	 * The contracts of a synchronization, by file name.
	 *
	 * @param string $synchronizationId The synchronization.
	 *
	 * @return array<string, array<string, mixed>> The contract per file name.
	 */
	private function knownFiles(string $synchronizationId): array {
		$known = [];
		foreach ($this->contracts->findAllObjects(filters: ['synchronizationId' => $synchronizationId]) as $contract) {
			if (is_object($contract) === true && method_exists($contract, 'jsonSerialize') === true) {
				$contract = $contract->jsonSerialize();
			}

			if (is_array($contract) === false || ($contract['synchronizationId'] ?? null) !== $synchronizationId) {
				continue;
			}

			$name = (string)($contract['originId'] ?? '');
			if ($name !== '') {
				$known[$name] = $contract;
			}
		}

		return $known;

	}//end knownFiles()

	/**
	 * A test run: the files the next run would pick up, with nothing fetched or changed.
	 *
	 * @param array                 $source The source.
	 * @param array                 $config The pickup configuration.
	 * @param array<string, string> $seen   The stored version per file name.
	 * @param integer               $now    The current time.
	 *
	 * @return array{found: int, created: int, updated: int, skipped: int, invalid: int, files: array<string, list<array<string, mixed>>>}
	 */
	private function listNew(array $source, array $config, array $seen, int $now): array {
		$stableSeconds = max(0, (int)($config['stableSeconds'] ?? 60));
		$new           = [];
		$skipped       = [];
		$files         = $this->client->listFiles(source: $source, path: (string)($config['path'] ?? ''), pattern: (string)($config['pattern'] ?? '*'));
		foreach ($files as $file) {
			if (($seen[$file->name] ?? null) === FilePickup::versionOf(file: $file)) {
				continue;
			}

			if ($file->modifiedAt !== null && $file->modifiedAt > $now - $stableSeconds) {
				$skipped[] = ['name' => $file->name, 'reason' => 'modified less than ' . $stableSeconds . ' seconds ago'];
				continue;
			}

			$new[] = $file->toArray();
		}

		return [
			'found'   => (count($new) + count($skipped)),
			'created' => 0,
			'updated' => 0,
			'skipped' => count($skipped),
			'invalid' => 0,
			'files'   => ['new' => $new, 'skipped' => $skipped],
		];

	}//end listNew()
}//end class
