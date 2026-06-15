<?php

/**
 * Stub for OCA\OpenRegister\Service\FileService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal stub for OCA\OpenRegister\Service\FileService.
 */
class FileService
{
    public function getFiles(string $register, string $schema, string $objectId): array
    {
        return [];
    }

    public function storeFile(string $content, string $filename, string $register, string $schema, string $objectId): ?array
    {
        return null;
    }

    public function deleteFile(string $register, string $schema, string $objectId, string $fileId): bool
    {
        return true;
    }
}
