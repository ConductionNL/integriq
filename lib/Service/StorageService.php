<?php

namespace OCA\OpenConnector\Service;

use Exception;
use OC\Files\Node\Node;
use OC\Files\ObjectStore\ObjectStoreStorage;
use OC\Memcache\Memcached;
use OC\Memcache\Redis;
use OCA\DAV\Upload\UploadFolder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\GenericFileException;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\ObjectStore\IObjectStoreMultiPartUpload;
use OCP\Files\Storage\IChunkedFileWrite;
use OCP\Files\StorageInvalidException;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Symfony\Component\Uid\Uuid;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class StorageService
{
    private ICache $cache;

    public const CACHE_KEY = 'openconnector-upload';
    public const UPLOAD_TARGET_PATH = 'upload-target-path';
    public const UPLOAD_TARGET_ID = 'upload-target-id';

    public const NUMBER_OF_PARTS = 'number-of-parts';

    const APP_USER = 'OpenRegister';


    /**
     * Class constructor
     *
     * @param IRootFolder $rootFolder The Nextcloud rootfolder
     * @param IAppConfig $config The configuration of the openconnector application.
     * @param ICacheFactory $cacheFactory The cache factory.
     * @param IUserSession $userSession The user session.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IAppConfig $config,
        ICacheFactory $cacheFactory,
        private readonly IUserManager $userManager,
    ) {
        $this->cache = $cacheFactory->createDistributed(self::CACHE_KEY);
    }

    /**
     * Create partial file upload. This will create the empty target file and a folder for the temporary files.
     *
     * @param string $path The path the target file will be written in.
     * @param string $fileName The filename of the target file.
     * @param int $size The total size of the file once all parts have been uploaded.
     *
     * @return array The file part objects containing order number, size and id.
     * @throws NotFoundException
     * @throws InvalidPathException|NotPermittedException
     */
    public function createUpload(string $path, string $fileName, int $size, ?string $objectId = null): array
    {
        $user = $this->userManager->get(self::APP_USER);
//        $userFolder = $this->rootFolder->getUserFolder(userId: $user ? $user->getUID() : 'Guest');

        $uploadFolder = $this->rootFolder->get($path);

        $partSize = $this->config->getValueInt('openconnector', 'part-size', 1000000);

        $numParts = ceil($size / $partSize);

        $remainingSize = $size;
        $parts = [];

		/**
		 * @var File $target
		 */
        $target = $uploadFolder->newFile($fileName);

        $partsFolder = $uploadFolder->newFolder("{$fileName}_parts");


        for ($i = 0; $i < $numParts; $i++) {
            $partNumber = $i + 1;
            $partUuid   = Uuid::v4();

            $this->cache->set("upload_$partUuid", [
                self::UPLOAD_TARGET_ID => $target->getId(),
                self::UPLOAD_TARGET_PATH => $partsFolder->getPath(),
                self::NUMBER_OF_PARTS => $numParts,
            ]);

            $parts[] = [
                'id'    => $partUuid,
                'size'  => $partSize < $remainingSize ? $partSize : $remainingSize,
                'order' => $partNumber,
                'object' => $objectId,
                "successful" => false
            ];
            $remainingSize -= $partSize;
        }

        return $parts;
    }

    /**
     * Write a file to a specified path.
     *
     * @param string $path The path to write the file to.
     * @param string $fileName The filename of the file to write.
     * @param string $content The content of the file.
     *
     * @return File The resulting file.
     * @throws GenericFileException
     * @throws LockedException
     * @throws NotFoundException
     * @throws NotPermittedException
     */
    public function writeFile(string $path, string $fileName, string $content): File
    {
        $currentUser = $this->userSession->getUser();
        $userFolder = $this->rootFolder->getUserFolder(userId: $currentUser->getUID());

        $uploadFolder = $userFolder->get($path);

        try {
            /** @var File $target */
            $target = $uploadFolder->get($fileName);
            $target->putContent($content);
        } catch (NotFoundException $e) {
            $target = $uploadFolder->newFile($fileName, $content);
        }

        return $target;
    }

    /**
     * Reconcile partial files into one file if all parts of a file are present.
     *
     * @param Node[] $folderContents The contents of the folder containing the partial files.
     * @param File $target The file to write the contents to.
     * @param int $numParts
     *
     * @return bool Whether reconciling the file has been successful.
     * @throws GenericFileException
     * @throws LockedException
     * @throws NotFoundException
     * @throws NotPermittedException
     * @throws InvalidPathException
     */
    private function attemptCloseUpload(array $folderContents, File $target, int $numParts): bool
    {
        $contentFilenames = array_map(function ($node) {
            return $node->getName();
        }, $folderContents);

        $folder = $folderContents[0]->getParent();

        $files = array_combine($contentFilenames, $folderContents);
        ksort($files);

        $contentFilenames = array_filter($contentFilenames,
            function($string) use ($target) {
                $result = preg_match("#^[0-9]+\.part\.{$target->getExtension()}$#", $string);
                return $result !== false && $result > 0;
            }
        );
        asort($contentFilenames);
        $sortedFilenames = array_values($contentFilenames);

        $contentFilenamesWithoutExtensions = array_map(function($filename) use ($target) {
            return intval(str_replace(search: ".part.{$target->getExtension()}", replace: '', subject: $filename));

        }, $contentFilenames);

        if ($contentFilenamesWithoutExtensions !== range(start: 1, end: $numParts)) {
            return false;
        }

        $totalContent = '';
        foreach ($files as $filePart) {
            if ($filePart instanceof File === false) {
                continue;
            }
            $totalContent .= $filePart->getContent();


            $filePart->delete();
        }

        if ($folder->getDirectoryListing() === []) {
            $folder->delete();
        }

        $target->putContent($totalContent);

        return true;
    }

    /**
     * Write a partial file to a temporary file and try to reconcile them if all file parts are uploaded.
     *
     * @param int $partId
     * @param string $partUuid
     * @param string $data
     *
     * @return bool
     * @throws GenericFileException
     * @throws InvalidPathException
     * @throws LockedException
     * @throws NotFoundException
     * @throws NotPermittedException
     */
    public function writePart(int $partId, string $partUuid, string $data): bool
    {
        $partData = $this->cache->get("upload_$partUuid");

        $targetFile  = $this->rootFolder->getUserFolder(self::APP_USER)->getFirstNodeById($partData[self::UPLOAD_TARGET_ID]);
        $partsFolder = $this->rootFolder->get($partData[self::UPLOAD_TARGET_PATH]);
        $numParts    = $partData[self::NUMBER_OF_PARTS];

        if ($partsFolder instanceof Folder === false) {
            throw new NotFoundException('target folder is not a folder');
        }

        if ($targetFile instanceof  File === false) {
            throw new NotFoundException('target file is not a file');
        }
        $partsFolder->newFile("$partId.part.{$targetFile->getExtension()}", $data);

        $this->rootFolder->get($partsFolder->getPath());

        $folderContents = $partsFolder->getDirectoryListing();

        if (count($folderContents) >= $numParts) {
            $this->attemptCloseUpload($folderContents, $targetFile, $numParts);
        }

        return true;
    }
}
