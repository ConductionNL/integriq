<?php

/**
 * Stub for OCA\OpenRegister\Service\FileService.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub satisfies PHPUnit mock-builder calls for
 * FileService so unit tests can run without a full Nextcloud server.
 *
 * CORRECTED (open-formulieren-intake, 2026-07-14): the previous stub declared
 * `storeFile()`/`getFiles(register, schema, objectId)`/`deleteFile(register,
 * schema, objectId, fileId)`, none of which match the real OpenRegister
 * `lib/Service/FileService.php` at HEAD. `grep`-verified no existing test
 * stubbed the stale method names before this fix. Only the methods actually
 * called by integriq's lib/ are declared here.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Stubs
 * @license  EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileTransfer;
use OCA\OpenRegister\Service\File\FileUpload;
use OCP\Files\File;

/**
 * Minimal stub for OCA\OpenRegister\Service\FileService.
 */
class FileService {
	/**
	 * Add a new file to an object's folder (signature as OpenRegister #9067).
	 *
	 * @param FileTarget $target The object (or its id) and its register.
	 * @param FileUpload $upload The file name, content, share toggle and tags.
	 * @return File
	 */
	public function addFile(FileTarget $target, FileUpload $upload): File {
		throw new \RuntimeException('FileService stub: addFile() has no default implementation, use createMock()->method(\'addFile\')->willReturn(...).');
	}

	/**
	 * Save (create or update) a file in an object's folder (signature as OpenRegister #9067).
	 *
	 * The upload's content may be a string or a readable stream resource.
	 *
	 * @param ObjectEntity $objectEntity The object.
	 * @param FileUpload $upload The file name, content, share toggle and tags.
	 * @return File
	 */
	public function saveFile(ObjectEntity $objectEntity, FileUpload $upload): File {
		throw new \RuntimeException('FileService stub: saveFile() has no default implementation, use createMock()->method(\'saveFile\')->willReturn(...).');
	}

	/**
	 * Retrieve all files attached to an object.
	 *
	 * @param ObjectEntity|string $object
	 * @param bool|null $sharedFilesOnly
	 * @return array
	 */
	public function getFiles($object, ?bool $sharedFilesOnly = false): array {
		return [];
	}

	/**
	 * Copy a file to another object (signature as OpenRegister FileService::copyAttachedFile()).
	 *
	 * @param FileTransfer $transfer The source object, file id and target object.
	 * @return File
	 */
	public function copyAttachedFile(FileTransfer $transfer): File {
		throw new \RuntimeException('FileService stub: copyAttachedFile() has no default implementation, stub it with createMock().');
	}

	/**
	 * The folder of an object (signature as OpenRegister FileService::getObjectFolder()).
	 *
	 * @param ObjectEntity|string $objectEntity The object.
	 * @param int|string|null $registerId The register id.
	 * @return \OCP\Files\Folder|null
	 */
	public function getObjectFolder(ObjectEntity|string $objectEntity, int|string|null $registerId = null): ?\OCP\Files\Folder {
		return null;
	}

	/**
	 * One file of an object (signature as OpenRegister FileService::getFile()).
	 *
	 * @param ObjectEntity|string|null $object The object.
	 * @param string|int $file The file name or id.
	 * @return File|null
	 */
	public function getFile(ObjectEntity|string|null $object = null, string|int $file = ''): ?File {
		return null;
	}

	/**
	 * Delete a file.
	 *
	 * @param mixed $file
	 * @param ObjectEntity|null $object
	 * @return bool
	 */
	public function deleteFile($file, ?ObjectEntity $object = null): bool {
		return true;
	}
}
