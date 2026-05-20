<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Chain B (openconnector-register-storage) — strangler-fig facade.
 *
 * Translates the legacy openconnector mapper API (integer ids, typed entity returns,
 * find/findAll/createFromArray/updateFromArray/delete) into calls against OpenRegister's
 * ObjectService (uuids, ObjectEntity returns, named-parameter API).
 *
 * Each method:
 *   1. Resolves the register+schema slug pair to OR PKs (cached once per facade lifetime)
 *   2. For integer-id reads, consults an int→uuid cache; on miss, performs a one-shot
 *      `findAll(filters: ['id' => $id])` query, populates the cache, then returns the uuid
 *   3. Delegates the actual CRUD to ObjectService using the verified named-param API
 *      (see openregister/lib/Service/ObjectService.php:573, 1080, 1504)
 *   4. Hydrates the returned ObjectEntity back into the openconnector typed entity
 *      (Source, Job, etc.) via that entity's existing setFields()/hydrate() pattern
 *
 * This facade exists ONLY during the chain-B → chain-C transition window. Chain C
 * deletes it. Local ADR-012 documents the strangler-fig phasing; chain C's quality
 * gate prevents accidental re-introduction (REQ "Quality gate blocks ObjectMapperFacade
 * re-introduction").
 *
 * Cross-ref: openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md REQ-006
 */

namespace OCA\OpenConnector\Service\Storage;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;

class ObjectMapperFacade
{

    /**
     * Per-(register, schema, intId) → uuid cache.
     *
     * Shape: `[$registerSlug.':'.$schemaSlug => ['42' => 'uuid-string', ...], ...]`
     * Invalidated whole-bucket on any write (createFromArray / updateFromArray / delete)
     * for the affected register+schema. Per spec REQ-006 scenario "cache invalidation
     * after a write": simpler than selective drop, and writes are rare relative to reads.
     */
    private array $intIdToUuidCache = [];


    public function __construct(
        private readonly ObjectService $objectService,
        private readonly LoggerInterface $logger
    ) {

    }


    /**
     * Look up a domain object by its LEGACY integer id.
     *
     * @template T of Entity
     * @param  string         $registerSlug Register slug (e.g. 'openconnector')
     * @param  string         $schemaSlug   Schema slug (e.g. 'source')
     * @param  int            $id           Legacy integer id (pre-chain-B PK)
     * @param  class-string<T> $entityClass  Openconnector typed entity FQCN (e.g. Source::class)
     * @return T
     * @throws DoesNotExistException
     */
    public function find(string $registerSlug, string $schemaSlug, int $id, string $entityClass): Entity
    {
        $uuid = $this->resolveIntIdToUuid($registerSlug, $schemaSlug, $id);
        return $this->findByUuid($registerSlug, $schemaSlug, $uuid, $entityClass);
    }


    /**
     * Look up a domain object by its OR uuid.
     *
     * @template T of Entity
     * @param  string          $registerSlug
     * @param  string          $schemaSlug
     * @param  string          $uuid         OR canonical uuid
     * @param  class-string<T> $entityClass  Openconnector typed entity FQCN
     * @return T
     * @throws DoesNotExistException
     */
    public function findByUuid(string $registerSlug, string $schemaSlug, string $uuid, string $entityClass): Entity
    {
        $orObject = $this->objectService->find(
            id: $uuid,
            register: $registerSlug,
            schema: $schemaSlug
        );
        if ($orObject === null) {
            throw new DoesNotExistException(sprintf('%s:%s uuid=%s not found', $registerSlug, $schemaSlug, $uuid));
        }
        return $this->hydrate($orObject, $entityClass);
    }


    /**
     * Look up a domain object by its slug.
     *
     * @template T of Entity
     * @param  string          $registerSlug
     * @param  string          $schemaSlug
     * @param  string          $slug         Slug column value
     * @param  class-string<T> $entityClass
     * @return T
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findBySlug(string $registerSlug, string $schemaSlug, string $slug, string $entityClass): Entity
    {
        $matches = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register' => $registerSlug,
                    'schema'   => $schemaSlug,
                    'slug'     => $slug,
                ],
                'limit' => 2,
            ]
        );
        $rows = (array) ($matches['results'] ?? $matches);
        if ($rows === []) {
            throw new DoesNotExistException(sprintf('%s:%s slug=%s not found', $registerSlug, $schemaSlug, $slug));
        }
        if (count($rows) > 1) {
            throw new MultipleObjectsReturnedException(sprintf('%s:%s slug=%s returned %d rows', $registerSlug, $schemaSlug, $slug, count($rows)));
        }
        return $this->hydrate($rows[0], $entityClass);
    }


    /**
     * Find all objects in a register+schema, optionally paginated/filtered/sorted.
     *
     * @template T of Entity
     * @param  string          $registerSlug
     * @param  string          $schemaSlug
     * @param  class-string<T> $entityClass
     * @param  int|null        $limit
     * @param  int|null        $offset
     * @param  array           $filters       Additional filter pairs merged into ObjectService config
     * @param  string|null     $search        Full-text search term
     * @param  array|null      $sort          Sort spec passed through to ObjectService
     * @return T[]
     */
    public function findAll(
        string $registerSlug,
        string $schemaSlug,
        string $entityClass,
        ?int $limit = null,
        ?int $offset = null,
        array $filters = [],
        ?string $search = null,
        ?array $sort = null
    ): array {
        $config = [
            'filters' => array_merge(
                [
                    'register' => $registerSlug,
                    'schema'   => $schemaSlug,
                ],
                $filters
            ),
        ];
        if ($limit !== null) {
            $config['limit'] = $limit;
        }
        if ($offset !== null) {
            $config['offset'] = $offset;
        }
        if ($search !== null && $search !== '') {
            $config['search'] = $search;
        }
        if ($sort !== null) {
            $config['sort'] = $sort;
        }

        $matches = $this->objectService->findAll(config: $config);
        $rows = (array) ($matches['results'] ?? $matches);
        return array_map(fn ($row): Entity => $this->hydrate($row, $entityClass), $rows);
    }


    /**
     * Create a new OR object from an array, returning the hydrated typed entity.
     *
     * @template T of Entity
     * @param  string          $registerSlug
     * @param  string          $schemaSlug
     * @param  array           $data         User-supplied fields
     * @param  class-string<T> $entityClass
     * @return T
     */
    public function createFromArray(string $registerSlug, string $schemaSlug, array $data, string $entityClass): Entity
    {
        $this->invalidateIntIdCache($registerSlug, $schemaSlug);
        $orObject = $this->objectService->saveObject(
            object: $data,
            register: $registerSlug,
            schema: $schemaSlug
        );
        return $this->hydrate($orObject, $entityClass);
    }


    /**
     * Update an existing OR object by uuid.
     *
     * @template T of Entity
     * @param  string          $registerSlug
     * @param  string          $schemaSlug
     * @param  string          $uuid         Object uuid to update
     * @param  array           $data         Partial or full field set
     * @param  class-string<T> $entityClass
     * @return T
     */
    public function updateFromArray(string $registerSlug, string $schemaSlug, string $uuid, array $data, string $entityClass): Entity
    {
        $this->invalidateIntIdCache($registerSlug, $schemaSlug);
        $orObject = $this->objectService->saveObject(
            object: $data,
            register: $registerSlug,
            schema: $schemaSlug,
            uuid: $uuid
        );
        return $this->hydrate($orObject, $entityClass);
    }


    /**
     * Delete an object by uuid.
     *
     * @param string $registerSlug
     * @param string $schemaSlug
     * @param string $uuid
     */
    public function delete(string $registerSlug, string $schemaSlug, string $uuid): void
    {
        $this->invalidateIntIdCache($registerSlug, $schemaSlug);
        $this->objectService->deleteObject(uuid: $uuid);
    }


    /**
     * Translate a legacy integer id into an OR uuid via a cached lookup.
     *
     * @param  string $registerSlug
     * @param  string $schemaSlug
     * @param  int    $id           Legacy integer id
     * @return string                Resolved uuid
     * @throws DoesNotExistException
     */
    private function resolveIntIdToUuid(string $registerSlug, string $schemaSlug, int $id): string
    {
        $cacheKey = $registerSlug . ':' . $schemaSlug;
        if (isset($this->intIdToUuidCache[$cacheKey][(string) $id])) {
            return $this->intIdToUuidCache[$cacheKey][(string) $id];
        }

        $matches = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register' => $registerSlug,
                    'schema'   => $schemaSlug,
                    'id'       => $id,
                ],
                'limit' => 1,
            ]
        );
        $rows = (array) ($matches['results'] ?? $matches);
        if ($rows === []) {
            throw new DoesNotExistException(sprintf(
                '%s:%s integer id=%d has no matching OR object — chain-B migration may not have run yet, see openconnector.storage_migrated flag',
                $registerSlug,
                $schemaSlug,
                $id
            ));
        }

        $first = $rows[0];
        $uuid = $first instanceof ObjectEntity ? $first->getUuid() : (string) ($first['uuid'] ?? '');
        if ($uuid === '') {
            throw new DoesNotExistException(sprintf('%s:%s id=%d row has no uuid', $registerSlug, $schemaSlug, $id));
        }

        if (!isset($this->intIdToUuidCache[$cacheKey])) {
            $this->intIdToUuidCache[$cacheKey] = [];
        }
        $this->intIdToUuidCache[$cacheKey][(string) $id] = $uuid;
        return $uuid;
    }


    /**
     * Clear the int-id→uuid cache bucket for a given register+schema.
     * Called after any write (create/update/delete) per REQ-006.
     */
    private function invalidateIntIdCache(string $registerSlug, string $schemaSlug): void
    {
        $cacheKey = $registerSlug . ':' . $schemaSlug;
        unset($this->intIdToUuidCache[$cacheKey]);
    }


    /**
     * Hydrate an OR ObjectEntity (or its array form) into an openconnector typed entity.
     *
     * The openconnector entities extend `\OCP\AppFramework\Db\Entity`, which provides
     * the magic `setX($value)` setters. We populate them via a `setFields(array)`
     * pattern: iterate every key in the OR object's JSON body and call the matching
     * setter when it exists. Numeric `id` is set from OR's integer-id column if
     * present, otherwise left null (post-chain-C: always null).
     *
     * @template T of Entity
     * @param  ObjectEntity|array $orObject     OR object as returned by ObjectService
     * @param  class-string<T>    $entityClass
     * @return T
     */
    private function hydrate(ObjectEntity|array $orObject, string $entityClass): Entity
    {
        $entity = new $entityClass();
        $payload = $orObject instanceof ObjectEntity ? $orObject->getObject() : ($orObject['object'] ?? $orObject);
        if (!is_array($payload)) {
            $payload = [];
        }

        // The uuid lives on the OR row itself, not always in the json body — surface it first.
        $uuid = $orObject instanceof ObjectEntity ? $orObject->getUuid() : ($orObject['uuid'] ?? null);
        if ($uuid !== null && method_exists($entity, 'setUuid')) {
            $entity->setUuid($uuid);
        }

        foreach ($payload as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($entity, $setter)) {
                try {
                    $entity->$setter($value);
                } catch (\Throwable $e) {
                    $this->logger->debug(sprintf(
                        'ObjectMapperFacade::hydrate(%s) — setter %s rejected value (%s); skipping field',
                        $entityClass,
                        $setter,
                        $e->getMessage()
                    ));
                }
            }
        }

        return $entity;
    }


}
