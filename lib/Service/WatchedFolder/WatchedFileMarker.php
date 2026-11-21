<?php

/**
 * Marks a watched file after its write: moved to a folder, or tagged.
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
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\WatchedFolder;

use OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles\NextcloudFolderFiles;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;

/**
 * `after: move` puts the file in `processedPath` after a safe write and in
 * `failedPath` after a failed one, both inside the watched folder and made
 * when missing. `after: tag` gives it the system tag `integriq-ingested` or
 * `integriq-ingest-failed` instead. A folder below the watched folder is not
 * watched, so a moved file is never picked up again.
 *
 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004
 */
class WatchedFileMarker {

	/**
	 * The tag of a file written safely.
	 *
	 * @var string
	 */
	public const TAG_INGESTED = 'integriq-ingested';

	/**
	 * The tag of a file whose write failed.
	 *
	 * @var string
	 */
	public const TAG_FAILED = 'integriq-ingest-failed';

	/**
	 * Constructor.
	 *
	 * @param NextcloudFolderFiles   $files      The watched folder.
	 * @param ISystemTagManager      $tags       Finds or makes the system tags.
	 * @param ISystemTagObjectMapper $tagMapper  Assigns a tag to the file.
	 */
	public function __construct(
		private readonly NextcloudFolderFiles $files,
		private readonly ISystemTagManager $tags,
		private readonly ISystemTagObjectMapper $tagMapper,
	) {
	}//end __construct()

	/**
	 * Mark a file whose object was saved and whose file was attached.
	 *
	 * @param WatchedFolder $watched The watched folder.
	 * @param File          $file    The file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004
	 */
	public function succeeded(WatchedFolder $watched, File $file): void {
		$this->mark(watched: $watched, file: $file, folder: $watched->processedPath(), tag: self::TAG_INGESTED);
	}//end succeeded()

	/**
	 * Mark a file whose write failed. It is not marked ingested.
	 *
	 * @param WatchedFolder $watched The watched folder.
	 * @param File          $file    The file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004
	 */
	public function failed(WatchedFolder $watched, File $file): void {
		$this->mark(watched: $watched, file: $file, folder: $watched->failedPath(), tag: self::TAG_FAILED);
	}//end failed()

	/**
	 * Move the file into a folder of the watched folder, or tag it, as the synchronization says.
	 *
	 * @param WatchedFolder $watched The watched folder.
	 * @param File          $file    The file.
	 * @param string        $folder  The folder for `after: move`.
	 * @param string        $tag     The tag for `after: tag`.
	 *
	 * @return void
	 */
	private function mark(WatchedFolder $watched, File $file, string $folder, string $tag): void {
		if ($watched->after() === WatchedFolder::AFTER_TAG) {
			$this->tagMapper->assignTags((string)$file->getId(), 'files', [$this->tagId(name: $tag)]);
			return;
		}

		$into = $this->folderIn(root: $this->files->folder(source: $watched->source), name: $folder);
		$name = $file->getName();
		if ($into->nodeExists($name) === true) {
			// A file of the same name was handled before; keep both.
			$name = $file->getId() . '-' . $name;
		}

		$file->move($into->getPath() . '/' . $name);
	}//end mark()

	/**
	 * A folder inside the watched folder, made when it is not there.
	 *
	 * @param Folder $root The watched folder.
	 * @param string $name The folder, relative to it.
	 *
	 * @return Folder
	 */
	private function folderIn(Folder $root, string $name): Folder {
		$name = trim($name, '/');
		if ($root->nodeExists($name) === true) {
			$node = $root->get($name);
			if ($node instanceof Folder) {
				return $node;
			}
		}

		return $root->newFolder($name);
	}//end folderIn()

	/**
	 * The id of a system tag, made when it does not exist yet.
	 *
	 * @param string $name The tag.
	 *
	 * @return string
	 */
	private function tagId(string $name): string {
		try {
			return (string)$this->tags->getTag($name, true, false)->getId();
		} catch (TagNotFoundException) {
			return (string)$this->tags->createTag($name, true, false)->getId();
		}
	}//end tagId()
}//end class
