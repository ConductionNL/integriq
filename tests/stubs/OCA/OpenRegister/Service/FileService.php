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
 * called by openconnector's lib/ are declared here.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;

/**
 * Minimal stub for OCA\OpenRegister\Service\FileService.
 */
class FileService
{
    /**
     * Add a new file to an object's folder.
     *
     * @param  ObjectEntity|string  $objectEntity
     * @param  string               $fileName
     * @param  string|resource|null $content
     * @param  bool                 $share
     * @param  array               $tags
     * @param  mixed               $_schema
     * @param  mixed               $_register
     * @param  int|string|null     $registerId
     * @return File
     */
    public function addFile(
        $objectEntity,
        string $fileName,
        mixed $content,
        bool $share = false,
        array $tags = [],
        $_schema = null,
        $_register = null,
        $registerId = null
    ): File {
        throw new \RuntimeException('FileService stub: addFile() has no default implementation — use createMock()->method(\'addFile\')->willReturn(...).');
    }

    /**
     * Save (create or update) a file in an object's folder.
     *
     * The `$content` type is `mixed`, mirroring the real OpenRegister signature
     * rather than narrowing it: `stream-file-content` (#110) widened this
     * parameter to accept a stream RESOURCE as well as a string, so a binary
     * download can be handed straight to the write side without being buffered
     * into a PHP string. A stub declaring `string` here would reject exactly the
     * value production accepts, failing any streamed-save test for the wrong
     * reason. PHP has no `resource` type keyword, so the contract lives in the
     * docblock — same as in `OCA\OpenRegister\Service\FileService`.
     *
     * @param  ObjectEntity         $objectEntity
     * @param  string               $fileName
     * @param  string|resource|null $content
     * @param  bool                 $share
     * @param  array                $tags
     * @return File
     */
    public function saveFile(
        ObjectEntity $objectEntity,
        string $fileName,
        mixed $content,
        bool $share = false,
        array $tags = []
    ): File {
        throw new \RuntimeException('FileService stub: saveFile() has no default implementation — use createMock()->method(\'saveFile\')->willReturn(...).');
    }

    /**
     * Retrieve all files attached to an object.
     *
     * @param  ObjectEntity|string $object
     * @param  bool|null           $sharedFilesOnly
     * @return array
     */
    public function getFiles($object, ?bool $sharedFilesOnly = false): array
    {
        return [];
    }

    /**
     * Copy a file to another object.
     *
     * @param  ObjectEntity $sourceObject
     * @param  int          $fileId
     * @param  ObjectEntity $targetObject
     * @return File
     */
    public function copyFile(ObjectEntity $sourceObject, int $fileId, ObjectEntity $targetObject): File
    {
        throw new \RuntimeException('FileService stub: copyFile() has no default implementation — use createMock()->method(\'copyFile\')->willReturn(...).');
    }

    /**
     * Delete a file.
     *
     * @param  mixed             $file
     * @param  ObjectEntity|null $object
     * @return bool
     */
    public function deleteFile($file, ?ObjectEntity $object = null): bool
    {
        return true;
    }
}
