<?php

/**
 * Integriq storage service.
 *
 * Handles multi-part file uploads to Nextcloud's file storage backend. Splits
 * incoming content into cache-tracked parts and reconciles them into the final
 * target file once all parts have arrived.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\GenericFileException;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserManager;
use OCP\Lock\LockedException;
use Symfony\Component\Uid\Uuid;

/**
 * Multi-part upload reconciliation backed by Nextcloud file storage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class StorageService {

	/**
	 * Distributed cache used to track upload parts between requests.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Cache key prefix for in-progress uploads.
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'integriq-upload';

	/**
	 * Cache field for the target file path of an upload.
	 *
	 * @var string
	 */
	public const UPLOAD_TARGET_PATH = 'upload-target-path';

	/**
	 * Cache field for the target file id of an upload.
	 *
	 * @var string
	 */
	public const UPLOAD_TARGET_ID = 'upload-target-id';

	/**
	 * Cache field for the total number of parts in an upload.
	 *
	 * @var string
	 */
	public const NUMBER_OF_PARTS = 'number-of-parts';

	/**
	 * System user under which Integriq reads/writes files.
	 *
	 * @var string
	 */
	public const APP_USER = 'OpenRegister';

	/**
	 * Class constructor.
	 *
	 * @param IRootFolder $rootFolder The Nextcloud rootfolder.
	 * @param IAppConfig $config The configuration of the integriq application.
	 * @param ICacheFactory $cacheFactory The cache factory.
	 * @param IUserManager $userManager Used to resolve the Integriq system user.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly IAppConfig $config,
		ICacheFactory $cacheFactory,
		private readonly IUserManager $userManager,
	) {
		$this->cache = $cacheFactory->createDistributed(self::CACHE_KEY);

	}//end __construct()

	/**
	 * Create partial file upload.
	 *
	 * This will create the empty target file and a folder for the temporary
	 * files.
	 *
	 * @param string $path The path the target file will be written in.
	 * @param string $fileName The filename of the target file.
	 * @param int $size The total size of the file once all parts have been uploaded.
	 * @param string|null $objectId Optional reference to the OR object the upload belongs to.
	 *
	 * @return array The file part objects containing order number, size and id.
	 *
	 * @throws NotFoundException When the target path does not exist.
	 * @throws InvalidPathException When the target path is invalid.
	 * @throws NotPermittedException When the target path is not writable.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	public function createUpload(string $path, string $fileName, int $size, ?string $objectId = null): array {
		$this->userManager->get(self::APP_USER);
		$uploadFolder = $this->rootFolder->get($path);
		$partSize = $this->config->getValueInt('integriq', 'part-size', 1000000);
		$numParts = ceil($size / $partSize);

		$plan = new UploadPlan(target: $uploadFolder->newFile($fileName), partsFolder: $uploadFolder->newFolder("{$fileName}_parts"));
		$plan->numParts = $numParts;
		$plan->objectId = $objectId;

		$parts = [];
		foreach ($this->partSizes(size: $size, partSize: $partSize) as $i => $reportedPartSize) {
			$parts[] = $this->registerPart(plan: $plan, part: ['size' => $reportedPartSize, 'order' => ($i + 1)]);
		}

		return $parts;
	}//end createUpload()

	/**
	 * The size each part reports: the part size, and what remains for the last one.
	 *
	 * @param int $size     The total size of the file.
	 * @param int $partSize The configured part size.
	 *
	 * @return list<int> One size per part, in order.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	private function partSizes(int $size, int $partSize): array {
		$numParts = ceil($size / $partSize);
		$remainingSize = $size;
		$sizes = [];
		for ($i = 0; $i < $numParts; $i++) {
			$reportedPartSize = $partSize;
			if ($partSize >= $remainingSize) {
				$reportedPartSize = $remainingSize;
			}

			$sizes[] = $reportedPartSize;
			$remainingSize -= $partSize;
		}

		return $sizes;
	}//end partSizes()

	/**
	 * Cache where one part goes and describe it for the client.
	 *
	 * @param UploadPlan               $plan The upload.
	 * @param array{size:int,order:int} $part The part's reported size and order number.
	 *
	 * @return array The file part object: id, size, order, object and whether it was written.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	private function registerPart(UploadPlan $plan, array $part): array {
		$partUuid = Uuid::v4();
		$this->cache->set("upload_$partUuid", $this->cacheEntry(plan: $plan));

		return [
			'id' => $partUuid,
			'size' => $part['size'],
			'order' => $part['order'],
			'object' => $plan->objectId,
			'successful' => false,
		];
	}//end registerPart()

	/**
	 * What the cache keeps for every part of an upload.
	 *
	 * @param UploadPlan $plan The upload.
	 *
	 * @return array The target file id, the parts folder path and the number of parts.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	private function cacheEntry(UploadPlan $plan): array {
		return [
			self::UPLOAD_TARGET_ID => $plan->target->getId(),
			self::UPLOAD_TARGET_PATH => $plan->partsFolder->getPath(),
			self::NUMBER_OF_PARTS => $plan->numParts,
		];
	}//end cacheEntry()

	/**
	 * Write a file to a specified path.
	 *
	 * @param string $path The path to write the file to.
	 * @param string $fileName The filename of the file to write.
	 * @param string $content The content of the file.
	 *
	 * @return File The resulting file.
	 *
	 * @throws GenericFileException When the underlying storage fails.
	 * @throws LockedException When the target file is locked.
	 * @throws NotFoundException When the path does not exist.
	 * @throws NotPermittedException When the path is not writable.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	public function writeFile(string $path, string $fileName, string $content): File {
		$currentUser = $this->userSession->getUser();
		$userFolder = $this->rootFolder->getUserFolder(userId: $currentUser->getUID());

		$uploadFolder = $userFolder->get($path);

		try {
			/*
			 * @var File $target
			 */

			$target = $uploadFolder->get($fileName);
			$target->putContent($content);
		} catch (NotFoundException $e) {
			$target = $uploadFolder->newFile($fileName, $content);
		}

		return $target;
	}//end writeFile()

	/**
	 * Reconcile partial files into one file if all parts of a file are present.
	 *
	 * @param \OCP\Files\Node[] $folderContents The contents of the folder containing the partial files.
	 * @param File $target The file to write the contents to.
	 * @param int $numParts Total number of parts expected.
	 *
	 * @return bool Whether reconciling the file has been successful.
	 *
	 * @throws GenericFileException When the underlying storage fails.
	 * @throws LockedException When the target file is locked.
	 * @throws NotFoundException When a part file does not exist.
	 * @throws NotPermittedException When the storage is not writable.
	 * @throws InvalidPathException When a part path is invalid.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	private function attemptCloseUpload(array $folderContents, File $target, int $numParts): bool {
		$folder = $folderContents[0]->getParent();
		if ($this->partNumbers(folderContents: $folderContents, target: $target) !== range(start: 1, end: $numParts)) {
			return false;
		}

		$totalContent = $this->takeContents(folderContents: $folderContents);
		if ($folder->getDirectoryListing() === []) {
			$folder->delete();
		}

		$target->putContent($totalContent);

		return true;
	}//end attemptCloseUpload()

	/**
	 * The part numbers of the part files in the folder.
	 *
	 * The numbers are in name order and keep their listing keys, so they only
	 * equal 1..n when the listing already holds exactly those parts in order.
	 *
	 * @param \OCP\Files\Node[] $folderContents The contents of the folder containing the partial files.
	 * @param File               $target         The target file; its extension names the parts.
	 *
	 * @return array<int,int> Listing key => part number.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	private function partNumbers(array $folderContents, File $target): array {
		$partNames = $this->partNames(folderContents: $folderContents, target: $target);
		asort($partNames);

		return array_map(
			function ($filename) use ($target) {
				return intval(str_replace(search: ".part.{$target->getExtension()}", replace: '', subject: $filename));
			},
			$partNames
		);
	}//end partNumbers()

	/**
	 * The names in the folder that are part files of the target.
	 *
	 * @param \OCP\Files\Node[] $folderContents The contents of the folder containing the partial files.
	 * @param File               $target         The target file; its extension names the parts.
	 *
	 * @return array<int,string> Listing key => `<n>.part.<extension>` name.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	private function partNames(array $folderContents, File $target): array {
		return array_filter(
			$this->namesOf(folderContents: $folderContents),
			function ($string) use ($target) {
				$result = preg_match("#^[0-9]+\.part\.{$target->getExtension()}$#", $string);
				return $result !== false && $result > 0;
			}
		);
	}//end partNames()

	/**
	 * Read every file in the folder, in name order, and delete it.
	 *
	 * @param \OCP\Files\Node[] $folderContents The contents of the folder containing the partial files.
	 *
	 * @return string The files' contents, joined.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	private function takeContents(array $folderContents): string {
		$files = array_combine($this->namesOf(folderContents: $folderContents), $folderContents);
		ksort($files);

		$totalContent = '';
		foreach ($files as $filePart) {
			if ($filePart instanceof File === false) {
				continue;
			}

			$totalContent .= $filePart->getContent();
			$filePart->delete();
		}

		return $totalContent;
	}//end takeContents()

	/**
	 * The names of the nodes, by listing key.
	 *
	 * @param \OCP\Files\Node[] $folderContents The nodes.
	 *
	 * @return array<int,string> The names.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	private function namesOf(array $folderContents): array {
		return array_map(
			function ($node) {
				return $node->getName();
			},
			$folderContents
		);
	}//end namesOf()

	/**
	 * Write a partial file to a temporary file and try to reconcile them if all file parts are uploaded.
	 *
	 * @param int $partId Order number of the part being written.
	 * @param string $partUuid Cache key of the part being written.
	 * @param string $data Raw part data.
	 *
	 * @return bool True when the part was written successfully.
	 *
	 * @throws GenericFileException When the underlying storage fails.
	 * @throws InvalidPathException When the part path is invalid.
	 * @throws LockedException When the target file is locked.
	 * @throws NotFoundException When the part folder or target does not exist.
	 * @throws NotPermittedException When the storage is not writable.
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	public function writePart(int $partId, string $partUuid, string $data): bool {
		$partData = $this->cache->get("upload_$partUuid");

		$targetFile = $this->rootFolder->getUserFolder(self::APP_USER)->getFirstNodeById($partData[self::UPLOAD_TARGET_ID]);
		$partsFolder = $this->rootFolder->get($partData[self::UPLOAD_TARGET_PATH]);
		$numParts = $partData[self::NUMBER_OF_PARTS];

		$this->assertUploadNodes(partsFolder: $partsFolder, targetFile: $targetFile);
		$partsFolder->newFile("$partId.part.{$targetFile->getExtension()}", $data);

		$this->rootFolder->get($partsFolder->getPath());

		$folderContents = $partsFolder->getDirectoryListing();

		if (count($folderContents) >= $numParts) {
			$this->attemptCloseUpload(folderContents: $folderContents, target: $targetFile, numParts: $numParts);
		}

		return true;
	}//end writePart()

	/**
	 * Refuse a part whose cached folder or target is not a folder and a file.
	 *
	 * @param mixed $partsFolder The node at the parts folder path.
	 * @param mixed $targetFile  The node with the target file id.
	 *
	 * @return void
	 *
	 * @throws NotFoundException When either is not what the upload created.
	 *
	 * @phpstan-assert Folder $partsFolder
	 * @phpstan-assert File $targetFile
	 *
	 * @spec openspec/specs/storage-uploads/spec.md
	 */
	private function assertUploadNodes(mixed $partsFolder, mixed $targetFile): void {
		if ($partsFolder instanceof Folder === false) {
			throw new NotFoundException('target folder is not a folder');
		}

		if ($targetFile instanceof File === false) {
			throw new NotFoundException('target file is not a file');
		}
	}//end assertUploadNodes()
}//end class
