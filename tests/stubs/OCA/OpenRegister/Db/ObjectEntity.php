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

    /** @var array|null */
    protected $locked = null;

    public function __construct()
    {
        $this->addType('object', 'json');
        $this->addType('files', 'json');
        $this->addType('locked', 'json');
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
