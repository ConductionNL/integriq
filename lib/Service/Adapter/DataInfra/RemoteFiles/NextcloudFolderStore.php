<?php

/**
 * Writes picked-up files into a user's Nextcloud Files.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles
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
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra\RemoteFiles;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * The folder is created when missing. A file whose name is already taken is
 * written under the next free name (`report (2).csv`), never over the existing
 * one: a partner re-sending a file must not erase what was picked up before.
 *
 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
 */
class NextcloudFolderStore implements PickedFileStore {

	/**
	 * Constructor.
	 *
	 * @param IRootFolder $rootFolder Nextcloud's file tree.
	 * @param string      $userId     The user whose Files receive the pickup.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly string $userId,
	) {

	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param string   $folder The folder, relative to the store's root.
	 * @param string   $name   The file name.
	 * @param resource $stream The content, positioned at its start.
	 *
	 * @return string Where the file was written.
	 *
	 * @spec openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002
	 */
	public function store(string $folder, string $name, $stream): string {
		$target = $this->rootFolder->getUserFolder($this->userId);
		foreach (array_filter(explode('/', $folder), fn (string $segment): bool => $segment !== '' && $segment !== '.') as $segment) {
			if ($segment === '..') {
				throw new RemoteFileException('The Files folder of a pickup may not contain "..".');
			}

			$target = $this->child(parent: $target, name: $segment);
		}

		$free = $target->getNonExistingName($name);
		$file = $target->newFile($free, $stream);

		return $file->getPath();
	}//end store()

	/**
	 * A sub folder, created when missing.
	 *
	 * @param Folder $parent The parent.
	 * @param string $name   The sub folder's name.
	 *
	 * @return Folder
	 *
	 * @throws RemoteFileException When the name is taken by a file.
	 */
	private function child(Folder $parent, string $name): Folder {
		if ($parent->nodeExists($name) === false) {
			return $parent->newFolder($name);
		}

		$node = $parent->get($name);
		if ($node instanceof Folder === false) {
			throw new RemoteFileException('"' . $name . '" in the pickup folder is a file, not a folder.');
		}

		return $node;
	}//end child()
}//end class
