<?php

namespace OCA\OpenConnector\Db;

use OCA\OpenConnector\Db\Source;
use OCA\OpenConnector\Service\Storage\ObjectMapperFacade;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Flag-gated mapper for Source entities.
 *
 * - When `openconnector.storage_migrated` IAppConfig flag is 'true' (set by
 *   chain-B post-migration), every public method delegates to
 *   {@see ObjectMapperFacade} which reads from OR-backed
 *   oc_openregister_objects.
 * - When the flag is unset/'false' (legacy state), the existing QBMapper SQL
 *   path against oc_openconnector_sources is used.
 *
 * Chain C deletes this entire class along with the facade. Local ADR-012
 * documents the strangler-fig phasing.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class SourceMapper extends QBMapper
{

    private const REGISTER_SLUG = 'openconnector';

    private const SCHEMA_SLUG = 'source';

    private readonly bool $useFacade;


    public function __construct(
        IDBConnection $db,
        IAppConfig $appConfig,
        private readonly ObjectMapperFacade $facade
    ) {
        parent::__construct($db, 'openconnector_sources');
        $this->useFacade = $appConfig->getAppValueString('storage_migrated', 'false') === 'true';
    }//end __construct()


    /**
     * Find a source by ID, UUID, or slug.
     *
     * @param  int|string $id The ID, UUID, or slug of the source to find
     * @return Source
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function find(int|string $id): Source
    {
        if ($this->useFacade === true) {
            if (is_string($id) && ctype_digit($id) === false) {
                // Non-numeric string: treat as uuid or slug.
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1) {
                    return $this->facade->findByUuid(self::REGISTER_SLUG, self::SCHEMA_SLUG, $id, Source::class);
                }
                return $this->facade->findBySlug(self::REGISTER_SLUG, self::SCHEMA_SLUG, $id, Source::class);
            }
            return $this->facade->find(self::REGISTER_SLUG, self::SCHEMA_SLUG, (int) $id, Source::class);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('openconnector_sources');

        if (is_string($id) && ctype_digit($id) === false) {
            $qb->where(
                $qb->expr()->orX(
                    $qb->expr()->eq('uuid', $qb->createNamedParameter($id)),
                    $qb->expr()->eq('slug', $qb->createNamedParameter($id)),
                    $qb->expr()->eq('id', $qb->createNamedParameter($id))
                )
            );
            return $this->findEntity(query: $qb);
        }

        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity(query: $qb);
    }//end find()


    /**
     * Find all sources matching the given criteria.
     *
     * @param  int|null                    $limit            Maximum number of results to return
     * @param  int|null                    $offset           Number of results to skip
     * @param  array<string,mixed>         $filters          Array of field => value pairs to filter by
     * @param  array<string>               $searchConditions Array of search conditions to apply
     * @param  array<string,mixed>         $searchParams     Array of parameters for the search conditions
     * @param  array<string,array<string>> $ids              Array of IDs to search for, keyed by type ('id', 'uuid', or 'slug')
     * @return array<Source> Array of Source entities
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[],
        ?array $ids=[]
    ): array {
        if ($this->useFacade === true) {
            // searchConditions/searchParams from the legacy SQL-fragment style
            // are not portable to OR's filter API; rely on $filters + $ids for
            // the facade path. Callers that depended on raw SQL searchConditions
            // should be rewritten when chain C deletes this layer.
            return $this->facade->findAll(
                registerSlug: self::REGISTER_SLUG,
                schemaSlug: self::SCHEMA_SLUG,
                entityClass: Source::class,
                limit: $limit,
                offset: $offset,
                filters: array_merge($filters ?? [], $this->flattenIdFilters($ids ?? []))
            );
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openconnector_sources')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (!empty($ids)) {
            $idConditions = [];
            if (!empty($ids['id'])) {
                $idConditions[] = $qb->expr()->in('id', $qb->createNamedParameter($ids['id'], IQueryBuilder::PARAM_INT_ARRAY));
            }
            if (!empty($ids['uuid'])) {
                $idConditions[] = $qb->expr()->in('uuid', $qb->createNamedParameter($ids['uuid'], IQueryBuilder::PARAM_STR_ARRAY));
            }
            if (!empty($ids['slug'])) {
                $idConditions[] = $qb->expr()->in('slug', $qb->createNamedParameter($ids['slug'], IQueryBuilder::PARAM_STR_ARRAY));
            }
            if (!empty($idConditions)) {
                $qb->andWhere($qb->expr()->orX(...$idConditions));
            }
        }

        foreach ($filters as $filter => $value) {
            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull($filter));
                continue;
            }
            if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull($filter));
                continue;
            }
            $qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
        }

        if (empty($searchConditions) === false) {
            $qb->andWhere('('.implode(' OR ', $searchConditions).')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

        return $this->findEntities(query: $qb);
    }//end findAll()


    public function createFromArray(array $object): Source
    {
        if ($this->useFacade === true) {
            if (empty($object['uuid']) === true) {
                $object['uuid'] = (string) Uuid::v4();
            }
            if (empty($object['version']) === true) {
                $object['version'] = '0.0.1';
            }
            return $this->facade->createFromArray(self::REGISTER_SLUG, self::SCHEMA_SLUG, $object, Source::class);
        }

        $obj = new Source();
        $obj->hydrate($object);

        if ($obj->getUuid() === null) {
            $obj->setUuid(Uuid::v4());
        }
        if (empty($obj->getVersion()) === true) {
            $obj->setVersion('0.0.1');
        }
        return $this->insert(entity: $obj);
    }//end createFromArray()


    public function updateFromArray(int $id, array $object): Source
    {
        if ($this->useFacade === true) {
            $existing = $this->facade->find(self::REGISTER_SLUG, self::SCHEMA_SLUG, $id, Source::class);
            // Bump version semantics preserved from legacy behaviour
            if (empty($existing->getVersion()) === true) {
                $object['version'] = '0.0.1';
            } elseif (empty($object['version']) === true) {
                $version = explode('.', $existing->getVersion());
                if (isset($version[2]) === true) {
                    $version[2] = (int) $version[2] + 1;
                    $object['version'] = implode('.', $version);
                }
            }
            return $this->facade->updateFromArray(
                registerSlug: self::REGISTER_SLUG,
                schemaSlug: self::SCHEMA_SLUG,
                uuid: $existing->getUuid(),
                data: $object,
                entityClass: Source::class
            );
        }

        $obj = $this->find($id);
        if (empty($obj->getVersion()) === true) {
            $object['version'] = '0.0.1';
        } elseif (empty($object['version']) === true) {
            $version = explode('.', $obj->getVersion());
            if (isset($version[2]) === true) {
                $version[2] = (int) $version[2] + 1;
                $object['version'] = implode('.', $version);
            }
        }
        $obj->hydrate($object);
        return $this->update($obj);
    }//end updateFromArray()


    /**
     * Translate the legacy [$ids] structure into facade-compatible filter pairs.
     */
    private function flattenIdFilters(array $ids): array
    {
        $out = [];
        if (!empty($ids['id'])) {
            $out['id'] = $ids['id'];
        }
        if (!empty($ids['uuid'])) {
            $out['uuid'] = $ids['uuid'];
        }
        if (!empty($ids['slug'])) {
            $out['slug'] = $ids['slug'];
        }
        return $out;
    }


    /**
     * Get the total count of all sources.
     */
    public function getTotalCallCount(): int
    {
        if ($this->useFacade === true) {
            // findAll without limit and just count — accept the inefficiency for the
            // transition window; chain C deletes this method.
            return count($this->facade->findAll(self::REGISTER_SLUG, self::SCHEMA_SLUG, Source::class));
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as count'))
            ->from('openconnector_sources');
        $result = $qb->execute();
        $row = $result->fetch();
        return (int) $row['count'];
    }//end getTotalCallCount()


    /**
     * Find or create a source based on the location.
     */
    public function findOrCreateByLocation(string $location, array $defaultData=[]): Source
    {
        if ($this->useFacade === true) {
            $matches = $this->facade->findAll(
                registerSlug: self::REGISTER_SLUG,
                schemaSlug: self::SCHEMA_SLUG,
                entityClass: Source::class,
                limit: 1,
                filters: ['location' => $location]
            );
            if ($matches !== []) {
                return $matches[0];
            }
            $sourceData = array_merge(
                ['location' => $location, 'name' => basename($location), 'type' => 'api', 'enabled' => true],
                $defaultData
            );
            return $this->createFromArray($sourceData);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openconnector_sources')
            ->where($qb->expr()->eq('location', $qb->createNamedParameter($location)));

        try {
            return $this->findEntity(query: $qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            $sourceData = array_merge(
                ['location' => $location, 'name' => basename($location), 'type' => 'api', 'enabled' => true],
                $defaultData
            );
            return $this->createFromArray($sourceData);
        }
    }//end findOrCreateByLocation()


    /**
     * Find all sources that belong to a specific configuration.
     */
    public function findByConfiguration(string $configurationId): array
    {
        if ($this->useFacade === true) {
            return $this->facade->findAll(
                registerSlug: self::REGISTER_SLUG,
                schemaSlug: self::SCHEMA_SLUG,
                entityClass: Source::class,
                filters: ['configurations' => $configurationId]
            );
        }

        $sql = 'SELECT * FROM `'.$this->getTableName().'` WHERE JSON_CONTAINS(configurations, ?)';
        return $this->findEntities($sql, [$configurationId]);
    }//end findByConfiguration()


    /**
     * Get all source ID → slug mappings.
     */
    public function getIdToSlugMap(): array
    {
        if ($this->useFacade === true) {
            $sources = $this->facade->findAll(self::REGISTER_SLUG, self::SCHEMA_SLUG, Source::class);
            $map = [];
            foreach ($sources as $s) {
                $map[(string) $s->getId()] = (string) $s->getSlug();
            }
            return $map;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')->from($this->getTableName());
        $result = $qb->executeQuery();
        $mappings = [];
        while ($row = $result->fetch()) {
            $mappings[$row['id']] = $row['slug'];
        }
        return $mappings;
    }//end getIdToSlugMap()


    /**
     * Get all source slug → ID mappings.
     */
    public function getSlugToIdMap(): array
    {
        if ($this->useFacade === true) {
            $sources = $this->facade->findAll(self::REGISTER_SLUG, self::SCHEMA_SLUG, Source::class);
            $map = [];
            foreach ($sources as $s) {
                $map[(string) $s->getSlug()] = (string) $s->getId();
            }
            return $map;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')->from($this->getTableName());
        $result = $qb->executeQuery();
        $mappings = [];
        while ($row = $result->fetch()) {
            $mappings[$row['slug']] = $row['id'];
        }
        return $mappings;
    }//end getSlugToIdMap()


}//end class
