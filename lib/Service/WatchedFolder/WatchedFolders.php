<?php

/**
 * The watched folders: which synchronizations watch which Nextcloud folder, and the files in them.
 *
 * @category Service
 * @package  OCA\Integriq\Service\WatchedFolder
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

namespace OCA\Integriq\Service\WatchedFolder;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\NextcloudFolderFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A watched folder is the folder of a `nextcloud-folder` source that a
 * synchronization reads (design D6). This class finds them and the files in
 * them, and queues the ingest of a file; WatchedFolderMap keeps the cached
 * map the file event listener reads.
 *
 * Everything is read in system context: the listener and the job run for
 * whoever uploaded, or for nobody.
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */
class WatchedFolders {

	/**
	 * The source type of a watched folder.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = NextcloudFolderFiles::SOURCE_TYPE;

	/**
	 * Constructor.
	 *
	 * @param ObjectService        $objects OpenRegister, for the synchronizations and their sources.
	 * @param NextcloudFolderFiles $files   The folder of a source.
	 * @param IJobList             $jobs    Where the ingest jobs are queued.
	 * @param LoggerInterface      $logger  Records a folder that cannot be listed.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly NextcloudFolderFiles $files,
		private readonly IJobList $jobs,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every watched folder: each active synchronization on a `nextcloud-folder` source, with that source.
	 *
	 * @return list<WatchedFolder>
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function all(): array {
		$watched = [];
		foreach ($this->find(filters: ['schema' => 'synchronization', 'sourceType' => self::SOURCE_TYPE]) as $entity) {
			$folder = $this->watchedFrom(synchronization: $entity->jsonSerialize());
			if ($folder !== null) {
				$watched[] = $folder;
			}
		}

		return $watched;
	}//end all()

	/**
	 * One watched folder by its synchronization, or null when that synchronization watches no folder.
	 *
	 * @param string $synchronizationId The synchronization.
	 *
	 * @return WatchedFolder|null
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function byId(string $synchronizationId): ?WatchedFolder {
		$entity = $this->findOne(id: $synchronizationId, schema: 'synchronization');
		if ($entity === null) {
			return null;
		}

		return $this->watchedFrom(synchronization: $entity->jsonSerialize());
	}//end byId()

	/**
	 * The folder map: the folder id of each watched folder, to the synchronizations that watch it.
	 *
	 * @return array<int, list<string>>
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function map(): array {
		$map = [];
		foreach ($this->all() as $watched) {
			try {
				$folderId = (int)$this->files->folder(source: $watched->source)->getId();
			} catch (Throwable) {
				// A source whose folder is gone watches nothing; its health probe says why.
				continue;
			}

			$map[$folderId][] = $watched->synchronizationId();
		}

		return $map;
	}//end map()

	/**
	 * The file with this id directly in the watched folder, or null.
	 *
	 * @param WatchedFolder $watched The watched folder.
	 * @param int           $fileId  The file.
	 *
	 * @return File|null
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function fileIn(WatchedFolder $watched, int $fileId): ?File {
		try {
			$folder = $this->files->folder(source: $watched->source);
			$nodes  = $folder->getById($fileId);
		} catch (Throwable) {
			return null;
		}

		foreach ($nodes as $node) {
			if ($node instanceof File && $node->getParent()->getId() === $folder->getId()) {
				return $node;
			}
		}

		return null;
	}//end fileIn()

	/**
	 * The ids of the files in a watched folder whose names match its pattern; none when it cannot be listed.
	 *
	 * @param WatchedFolder $watched The watched folder.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function listed(WatchedFolder $watched): array {
		try {
			$listed = $this->files->listFiles(source: $watched->source, path: '', pattern: $watched->pattern());
		} catch (Throwable $e) {
			$this->logger->warning(
				'integriq: a watched folder could not be listed: ' . $e->getMessage(),
				['synchronization' => $watched->synchronizationId()]
			);
			return [];
		}

		return array_map(static fn (array $entry): int => (int)$entry['fileId'], $listed);
	}//end listed()

	/**
	 * Queue the ingest of one file for one synchronization, once.
	 *
	 * @param int    $fileId            The file.
	 * @param string $synchronizationId The synchronization.
	 *
	 * @return bool True when a job was added, false when it was already queued.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function queue(int $fileId, string $synchronizationId): bool {
		$argument = ['fileId' => $fileId, 'synchronizationId' => $synchronizationId];
		if ($this->jobs->has(WatchedFileIngestJob::class, $argument) === true) {
			return false;
		}

		$this->jobs->add(WatchedFileIngestJob::class, $argument);
		return true;
	}//end queue()

	/**
	 * The watched folder a synchronization describes, or null when it is not an active folder synchronization.
	 *
	 * @param array<string, mixed> $synchronization The synchronization.
	 *
	 * @return WatchedFolder|null
	 */
	private function watchedFrom(array $synchronization): ?WatchedFolder {
		if (($synchronization['sourceType'] ?? '') !== self::SOURCE_TYPE || ($synchronization['status'] ?? '') === 'disabled') {
			return null;
		}

		$source = $this->findOne(id: (string)($synchronization['sourceId'] ?? ''), schema: 'source');
		if ($source === null) {
			return null;
		}

		return new WatchedFolder(synchronization: $synchronization, source: $source->jsonSerialize());
	}//end watchedFrom()

	/**
	 * One object of integriq's register, or null.
	 *
	 * @param string $id     The object id.
	 * @param string $schema The schema.
	 *
	 * @return ObjectEntity|null
	 */
	private function findOne(string $id, string $schema): ?ObjectEntity {
		if ($id === '') {
			return null;
		}

		try {
			// Unscoped: a file event or a queued job runs for whoever uploaded, or for nobody.
			return $this->objects->find(
				id: $id,
				options: new ObjectFindOptions(register: 'integriq', schema: $schema),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return null;
		}
	}//end findOne()

	/**
	 * Objects of integriq's register.
	 *
	 * @param array<string, mixed> $filters The filters, with the schema.
	 *
	 * @return list<ObjectEntity>
	 */
	private function find(array $filters): array {
		try {
			// Unscoped, for the same reason as findOne().
			$found = $this->objects->findAll(
				config: ['filters' => ['register' => 'integriq'] + $filters],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return [];
		}

		$results = ($found['results'] ?? $found);
		if (is_array($results) === false) {
			return [];
		}

		return array_values(array_filter($results, static fn ($row): bool => $row instanceof ObjectEntity));
	}//end find()
}//end class
