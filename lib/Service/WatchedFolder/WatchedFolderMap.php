<?php

/**
 * The cached map of watched folders that the file event listener reads.
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

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * The map from folder id to synchronization ids is cached, so the listener
 * answers an upload without reading OpenRegister each time. It is rebuilt
 * when the cache entry expires, which is at most a minute after a
 * synchronization changes.
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */
class WatchedFolderMap {

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
	 * @param WatchedFolders $folders      The watched folders and the job queue.
	 * @param ICacheFactory  $cacheFactory The distributed cache.
	 */
	public function __construct(
		private readonly WatchedFolders $folders,
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
			$map = $this->folders->map();
			$this->cache->set(self::MAP_KEY, $map, self::MAP_TTL);
		}

		return array_values($map[$folderId] ?? []);
	}//end watching()

	/**
	 * Queue the ingest of one file for one synchronization, once.
	 *
	 * @param int    $fileId            The file.
	 * @param string $synchronizationId The synchronization.
	 *
	 * @return bool True when a job was added.
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function queue(int $fileId, string $synchronizationId): bool {
		return $this->folders->queue(fileId: $fileId, synchronizationId: $synchronizationId);
	}//end queue()
}//end class
