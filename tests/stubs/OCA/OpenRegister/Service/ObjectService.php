<?php

/**
 * Stub for OCA\OpenRegister\Service\ObjectService.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub satisfies PHPUnit mock-builder calls for
 * ObjectService so unit tests can run without a full Nextcloud server.
 *
 * Only the methods actually called by openconnector's lib/ are declared here.
 * PHPUnit's getMockBuilder() + disableOriginalConstructor() will then be able
 * to stub them via ->method('find')->willReturn(...) etc.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Minimal stub for OCA\OpenRegister\Service\ObjectService.
 */
class ObjectService
{
    /**
     * Find a single object by id/uuid.
     *
     * The real OpenRegister ObjectService throws DoesNotExistException when the
     * object is not found. PHPUnit mocks may stub ->willReturn(null) for tests
     * that verify the "not found" path; the nullable return type preserves that
     * compatibility. PHPStan sees the @throws annotation and treats any catch
     * of DoesNotExistException as live.
     *
     * @param  string|int  $id
     * @param  string|null $register
     * @param  string|null $schema
     * @param  bool        $_rbac         Apply RBAC filters when true.
     * @param  bool        $_multitenancy Apply multitenancy filters when true.
     * @return ObjectEntity|null
     *
     * @throws DoesNotExistException When the object is not found.
     */
    public function find($id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntity
    {
        return new ObjectEntity();
    }

    /**
     * Find all objects matching the given config/filters.
     *
     * @param  array $config
     * @return array{results: ObjectEntity[], total: int}
     */
    public function findAll(array $config = []): array
    {
        return ['results' => [], 'total' => 0];
    }

    /**
     * Save (create or update) an object.
     *
     * @param  array|ObjectEntity $object
     * @param  string|null        $register
     * @param  string|null        $schema
     * @param  string|null        $uuid
     * @return ObjectEntity
     */
    public function saveObject($object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity
    {
        return new ObjectEntity();
    }

    /**
     * Delete an object by uuid.
     *
     * @param  string|null $uuid
     * @param  string|null $register
     * @param  string|null $schema
     * @return bool
     */
    public function deleteObject(?string $uuid = null, ?string $register = null, ?string $schema = null): bool
    {
        return true;
    }

    /**
     * Set the active register context (fluent interface).
     *
     * @param  string $register
     * @return static
     */
    public function setRegister(string $register): static
    {
        return $this;
    }

    /**
     * Set the active schema context (fluent interface).
     *
     * @param  string $schema
     * @return static
     */
    public function setSchema(string $schema): static
    {
        return $this;
    }

    /**
     * Lock an object by identifier.
     *
     * @param  string   $identifier Object identifier.
     * @param  string|null $process Lock process identifier.
     * @param  int|null $duration Lock duration in seconds.
     * @return array<string, mixed>
     */
    public function lockObject(string $identifier, ?string $process = null, ?int $duration = null): array
    {
        return ['id' => $identifier, 'process' => $process, 'duration' => $duration];
    }

    /**
     * Unlock an object by identifier.
     *
     * @param  string|int $identifier Object identifier.
     * @return bool
     */
    public function unlockObject(string|int $identifier): bool
    {
        return true;
    }
}
