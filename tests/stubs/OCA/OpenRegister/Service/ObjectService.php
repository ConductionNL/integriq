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

/**
 * Minimal stub for OCA\OpenRegister\Service\ObjectService.
 */
class ObjectService
{
    /**
     * Find a single object by id/uuid.
     *
     * Returns null when no matching object exists (triggers DoesNotExistException
     * in calling code). Nullable return is required so PHPUnit can stub this with
     * ->willReturn(null) in "not found" test scenarios.
     *
     * @param  string|int  $id
     * @param  string|null $register
     * @param  string|null $schema
     * @param  bool        $_rbac         Apply RBAC filters when true.
     * @param  bool        $_multitenancy Apply multitenancy filters when true.
     * @return ObjectEntity|null
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
}
