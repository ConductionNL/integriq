<?php

/**
 * Stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * OpenRegister is a peer Nextcloud app that is not available in the standalone
 * composer dev-environment. This stub satisfies `use` statements and PHPUnit
 * mock-builder calls for ObjectEntity so unit tests can run without a full
 * Nextcloud server installation.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Stubs
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Minimal stub for OCA\OpenRegister\Db\ObjectEntity.
 *
 * Uses the real Nextcloud Entity base so that magic __call-based getters
 * (getUuid, getObject, etc.) work through the proper Entity path.
 */
class ObjectEntity extends Entity
{
    /** @var string */
    protected $uuid = '';

    /** @var array */
    protected $object = [];

    /**
     * The real OCA\OpenRegister\Db\ObjectEntity declares `name`
     * (`addType(fieldName: 'name', type: 'string')` + `@method string|null getName()`),
     * and production code reads it via the Entity __call getter — e.g.
     * `EndpointService::processSyncRule()`'s debug line calls
     * `$synchronization->getName()`. Omitting it here made the stub drift from
     * the real class, so any test exercising that path died in __call instead
     * of testing the behaviour under test.
     *
     * @var string|null
     */
    protected $name = null;

    /** @var string|null */
    protected $register = null;

    /** @var string|null */
    protected $schema = null;

    /** @var array|null */
    protected $files = null;

    /** @var string|null */
    protected $folder = null;

    /** @var string|null */
    protected $owner = null;

    /**
     * The real OCA\OpenRegister\Db\ObjectEntity declares `organisation`
     * (the owning organisation UUID, an entity column NOT carried inside
     * `object`). InlineSecretMigrationExecutor reads it via the Entity __call
     * getter (`$entity->getOrganisation()`) to mint a source credential at
     * organisation scope, so the stub must declare the property for both the
     * magic getter and setter to resolve.
     *
     * @var string|null
     */
    protected $organisation = null;

    /** @var array|null */
    protected $locked = null;

    /**
     * The real OCA\OpenRegister\Db\ObjectEntity declares `updated`/`created`
     * (`addType(fieldName: 'updated', type: 'datetime')` +
     * `addType(fieldName: 'created', type: 'datetime')`), and production code
     * reads them via the Entity __call getter — e.g.
     * `SynchronizationService::synchronizeContract()`'s no-op-write skip
     * check calls `$sourceTargetMapping->getUpdated()` to decide whether a
     * mapping was edited since the contract was last checked. Omitting these
     * here made the stub drift from the real class, so any test exercising a
     * synchronization with a real (non-null) `sourceTargetMapping`/`source`
     * died in Entity::__call ("updated is not a valid attribute") instead of
     * testing the behaviour under test.
     *
     * @var \DateTime|null
     */
    protected $updated = null;

    /** @var \DateTime|null */
    protected $created = null;

    public function __construct()
    {
        $this->addType('object', 'json');
        $this->addType('files', 'json');
        $this->addType('locked', 'json');
        $this->addType('updated', 'datetime');
        $this->addType('created', 'datetime');
    }

    /**
     * Get the object data array.
     *
     * Declared explicitly (not via Entity __call magic) so that
     * method_exists($entity, 'getObject') returns true in production code
     * that uses method_exists() to branch between ObjectEntity and raw arrays.
     *
     * @return array
     */
    public function getObject(): array
    {
        return is_array($this->object) ? $this->object : [];
    }

    /**
     * Set the object data array.
     *
     * Declared explicitly to pair with getObject().
     *
     * @param  array $object The data array.
     * @return void
     */
    public function setObject(array $object): void
    {
        $this->object = $object;
        $this->markFieldUpdated('object');
    }

    /**
     * Get the uuid of this entity.
     *
     * Declared explicitly (not via Entity __call magic) so that getUuid()
     * is accessible without relying solely on the magic proxy.
     *
     * @return string
     */
    public function getUuid(): string
    {
        return (string) $this->uuid;
    }

    /**
     * Set the uuid of this entity.
     *
     * @param  string $uuid The UUID string.
     * @return void
     */
    public function setUuid(string $uuid): void
    {
        $this->uuid = $uuid;
        $this->markFieldUpdated('uuid');
    }

    public function jsonSerialize(): array
    {
        return array_merge(
            ['uuid' => $this->uuid],
            is_array($this->object) ? $this->object : []
        );
    }
}
