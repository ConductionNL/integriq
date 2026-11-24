<?php

/**
 * Integriq Watched Folder Listener: queues the ingest of a file that lands in a watched folder.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
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

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\WatchedFolder\WatchedFolderMap;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Node;
use Throwable;

/**
 * The fast path of design D6. On a created or written file it looks the
 * file's folder up in the watched-folder map and queues one ingest job per
 * synchronization that watches it. It never reads the file or writes an
 * object on the upload's request, and it never throws into the upload: a
 * file it misses is picked up by the sweep.
 *
 * Only a file directly in the watched folder counts, the same files the
 * sweep lists; the processed and failed folders below it are not watched.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
 */
class WatchedFolderListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param WatchedFolderMap $folders The watched-folder map and the job queue.
	 */
	public function __construct(
		private readonly WatchedFolderMap $folders,
	) {
	}//end __construct()

	/**
	 * Queue the ingest of a file that landed in a watched folder.
	 *
	 * @param Event $event The node event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003
	 */
	public function handle(Event $event): void {
		if (($event instanceof NodeCreatedEvent) === false && ($event instanceof NodeWrittenEvent) === false) {
			return;
		}

		$node = $event->getNode();
		if (($node instanceof File) === false) {
			return;
		}

		$folderId = $this->folderIdOf(node: $node);
		if ($folderId === null) {
			return;
		}

		foreach ($this->folders->watching(folderId: $folderId) as $synchronizationId) {
			$this->folders->queue(fileId: (int)$node->getId(), synchronizationId: $synchronizationId);
		}
	}//end handle()

	/**
	 * The id of the folder a node is in, or null when it cannot be read.
	 *
	 * @param Node $node The node.
	 *
	 * @return int|null
	 */
	private function folderIdOf(Node $node): ?int {
		try {
			return (int)$node->getParent()->getId();
		} catch (Throwable) {
			return null;
		}
	}//end folderIdOf()
}//end class
