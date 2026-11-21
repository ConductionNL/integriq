<?php

/**
 * The watched folders: which synchronizations watch which Nextcloud folder.
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

use OCA\Integriq\BackgroundJob\WatchedFileIngestJob;
use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\NextcloudFolderFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\ICache;
use OCP\ICacheFactory;
use Throwable;

/**
 * A watched folder is the folder of a `nextcloud-folder` source that a
 * synchronization reads (design D6). The map from folder id to
 * synchronization ids is cached, so the file event listener answers without
 * reading OpenRegister on every upload; it is rebuilt when the cache entry
 * expires, which is at most a minute after a synchronization changes.
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
	 * The cache key of the folder map.
	 *
	 * @var string
	 */
	private const MAP_KEY = 'watched-folder-map';

	/**
	 * How long the folder map is kept, in seconds.
	 *
	 * @var integer
	 */
	private const MAP_TTL = 60;

	/**
	 * The cache the folder map lives in.
	 *
	 * @var ICache
	 */
	private readonly ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param ObjectService        $objects      OpenRegister, for the synchronizations and their sources.
	 * @param NextcloudFolderFiles $files        The folder of a source.
	 * @param IJobList             $jobs         Where the ingest jobs are queued.
	 * @param ICacheFactory        $cacheFactory The distributed cache, for the folder map.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly NextcloudFolderFiles $files,
		private readonly IJobList $jobs,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed('integriq-watched-folders');
	}//end __construct()

	/**
	 * The synchronizations that watch a folder.
	 *
	 * @param int $folderId The folder's file id.
	 *
	 * @return list<string> The synchronization ids; empty for a folder nobody watches.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function watching(int $folderId): array {
		$map = $this->cache->get(self::MAP_KEY);
		if (is_array($map) === false) {
			$map = $this->buildMap();
			$this->cache->set(self::MAP_KEY, $map, self::MAP_TTL);
		}

		return array_values($map[(string)$folderId] ?? []);
	}//end watching()

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
	 * The folder map: folder id to the synchronizations that watch it.
	 *
	 * @return array<string, list<string>>
	 */
	private function buildMap(): array {
		$map = [];
		foreach ($this->all() as $watched) {
			try {
				$folderId = (string)$this->files->folder(source: $watched->source)->getId();
			} catch (Throwable) {
				// A source whose folder is gone watches nothing; its health probe says why.
				continue;
			}

			$map[$folderId][] = $watched->id();
		}

		return $map;
	}//end buildMap()

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
			$found = $this->objects->findAll(config: ['filters' => ['register' => 'integriq'] + $filters], _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return [];
		}

		$results = ($found['results'] ?? $found);
		return array_values(array_filter(is_array($results) === true ? $results : [], static fn ($row): bool => $row instanceof ObjectEntity));
	}//end find()
}//end class
