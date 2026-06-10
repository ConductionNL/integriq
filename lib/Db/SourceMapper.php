<?php
/**
 * OpenConnector Source mapper (OpenRegister-backed adapter).
 *
 * Post OpenRegister-cutover the `openconnector_sources` table was dropped. This
 * mapper is no longer a QBMapper: it is a thin adapter over
 * `\OCA\OpenRegister\Service\ObjectService` (register `openconnector`, schema
 * `source`). It keeps the original public surface (find / findAll / createFromArray
 * / updateFromArray / findOrCreateByLocation / slug maps) so the existing call
 * sites in the engine keep working, but every read/write now flows through the
 * OpenRegister object API and returns hydrated `Source` value objects.
 *
 * @category Db
 * @package  OCA\OpenConnector\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Db;

use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Uid\Uuid;

/**
 * Class SourceMapper
 *
 * OpenRegister-backed adapter for source objects.
 *
 * @package OCA\OpenConnector\Db
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SourceMapper
{

    /**
     * The OpenRegister register sources live in.
     *
     * @var string
     */
    private const REGISTER = 'openconnector';

    /**
     * The OpenRegister schema for source objects.
     *
     * @var string
     */
    private const SCHEMA = 'source';

    /**
     * Constructor.
     *
     * @param OrObjectService $orObjectService The OpenRegister object service.
     */
    public function __construct(
        private readonly OrObjectService $orObjectService
    ) {

    }//end __construct()

    /**
     * Find a source by ID, UUID, or slug.
     *
     * @param int|string $id The ID, UUID, or slug of the source to find.
     *
     * @return Source The hydrated source value object.
     *
     * @throws DoesNotExistException When no source matches the identifier.
     */
    public function find(int | string $id): Source
    {
        $object = $this->orObjectService->find(
            id: (string) $id,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        if ($object === null) {
            throw new DoesNotExistException('The source you are looking for does not exist');
        }

        return (new Source())->hydrate($object->jsonSerialize());

    }//end find()

    /**
     * Find the raw OpenRegister object for a source by ID/UUID/slug.
     *
     * The migrated `CallService::call()` consumes the OpenRegister `ObjectEntity`
     * directly (it reads the source body via `->getObject()`), so the engine needs
     * the raw object — not the hydrated `Source` value object — when invoking it.
     *
     * @param int|string $id The ID, UUID, or slug of the source to find.
     *
     * @return \OCA\OpenRegister\Db\ObjectEntity The OpenRegister source object.
     *
     * @throws DoesNotExistException When no source matches the identifier.
     */
    public function findObject(int | string $id): \OCA\OpenRegister\Db\ObjectEntity
    {
        $object = $this->orObjectService->find(
            id: (string) $id,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        if ($object === null) {
            throw new DoesNotExistException('The source you are looking for does not exist');
        }

        return $object;

    }//end findObject()

    /**
     * Find all sources matching the given criteria.
     *
     * @param int|null   $limit            Maximum number of results to return.
     * @param int|null   $offset           Number of results to skip.
     * @param array|null $filters          Array of field => value pairs to filter by.
     * @param array|null $searchConditions Unused (kept for signature compatibility).
     * @param array|null $searchParams     Unused (kept for signature compatibility).
     * @param array|null $ids              Array of IDs to search for, keyed by type.
     *
     * @return array<Source> Array of Source value objects.
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[],
        ?array $ids=[]
    ): array {
        $objects = $this->searchObjects(filters: ($filters ?? []), limit: $limit, offset: $offset, ids: $ids);

        return array_map(
            fn ($object): Source => (new Source())->hydrate($object->jsonSerialize()),
            $objects
        );

    }//end findAll()

    /**
     * Create a new source from array data.
     *
     * @param array $object Array of source data.
     *
     * @return Source The created source value object.
     */
    public function createFromArray(array $object): Source
    {
        // Set uuid if not provided.
        if (empty($object['uuid']) === true) {
            $object['uuid'] = (string) Uuid::v4();
        }

        // Set default version.
        if (empty($object['version']) === true) {
            $object['version'] = '0.0.1';
        }

        // OpenRegister owns identity via the uuid; a stray int `id` breaks its
        // upsert probe (trim($object['id'])).
        unset($object['id']);

        $saved = $this->orObjectService->saveObject(
            object: $object,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        return (new Source())->hydrate($saved->jsonSerialize());

    }//end createFromArray()

    /**
     * Update an existing source from array data.
     *
     * @param int|string $id     ID/UUID of the source to update.
     * @param array      $object Array of updated source data.
     *
     * @return Source The updated source value object.
     *
     * @throws DoesNotExistException When the source does not exist.
     */
    public function updateFromArray(int | string $id, array $object): Source
    {
        $existing = $this->find($id);

        // Bump the patch version when the caller did not supply one.
        if (empty($existing->getVersion()) === true) {
            $object['version'] = '0.0.1';
        } else if (empty($object['version']) === true) {
            $version = explode('.', $existing->getVersion());
            if (isset($version[2]) === true) {
                $version[2]        = ((int) $version[2] + 1);
                $object['version'] = implode('.', $version);
            }
        }

        $merged = array_merge($existing->jsonSerialize(), $object);
        unset($merged['id']);

        $saved = $this->orObjectService->saveObject(
            object: $merged,
            register: self::REGISTER,
            schema: self::SCHEMA,
            uuid: (string) ($existing->getUuid() ?? $id)
        );

        return (new Source())->hydrate($saved->jsonSerialize());

    }//end updateFromArray()

    /**
     * Get the total count of all sources.
     *
     * @return int The total number of source objects.
     */
    public function getTotalCallCount(): int
    {
        return count($this->searchObjects());

    }//end getTotalCallCount()

    /**
     * Find or create a source based on the location.
     *
     * @param string $location    The location to search for.
     * @param array  $defaultData Additional data to use when creating a new source.
     *
     * @return Source The found or newly created source.
     */
    public function findOrCreateByLocation(string $location, array $defaultData=[]): Source
    {
        $matches = $this->searchObjects(filters: ['location' => $location], limit: 1);

        if (empty($matches) === false) {
            return (new Source())->hydrate($matches[0]->jsonSerialize());
        }

        $sourceData = array_merge(
            [
                'location' => $location,
                'name'     => basename($location),
                'type'     => 'api',
                'enabled'  => true,
            ],
            $defaultData
        );

        return $this->createFromArray($sourceData);

    }//end findOrCreateByLocation()

    /**
     * Find all sources that belong to a specific configuration.
     *
     * @param string $configurationId The ID of the configuration to find sources for.
     *
     * @return array<Source> Array of Source value objects.
     */
    public function findByConfiguration(string $configurationId): array
    {
        return array_values(
            array_filter(
                $this->findAll(),
                static function (Source $source) use ($configurationId): bool {
                    return in_array($configurationId, (array) $source->jsonSerialize()['configurations'], true) === true;
                }
            )
        );

    }//end findByConfiguration()

    /**
     * Get all source ID to slug mappings.
     *
     * @return array<string,string> Array mapping source IDs to their slugs.
     */
    public function getIdToSlugMap(): array
    {
        $mappings = [];
        foreach ($this->findAll() as $source) {
            $data                        = $source->jsonSerialize();
            $mappings[(string) $data['id']] = $source->getSlug();
        }

        return $mappings;

    }//end getIdToSlugMap()

    /**
     * Get all source slug to ID mappings.
     *
     * @return array<string,string> Array mapping source slugs to their IDs.
     */
    public function getSlugToIdMap(): array
    {
        $mappings = [];
        foreach ($this->findAll() as $source) {
            $data                              = $source->jsonSerialize();
            $mappings[$source->getSlug()] = (string) $data['id'];
        }

        return $mappings;

    }//end getSlugToIdMap()

    /**
     * Run an OpenRegister object search scoped to the source register/schema.
     *
     * @param array    $filters Field filters keyed by source property.
     * @param int|null $limit   Optional result limit.
     * @param int|null $offset  Optional result offset.
     * @param array    $ids     Optional id/uuid/slug filter buckets.
     *
     * @return array<\OCA\OpenRegister\Db\ObjectEntity> The matched OpenRegister objects.
     */
    private function searchObjects(array $filters=[], ?int $limit=null, ?int $offset=null, ?array $ids=[]): array
    {
        $config = [
            'filters' => array_merge(
                ['register' => self::REGISTER, 'schema' => self::SCHEMA],
                $filters
            ),
        ];

        if ($limit !== null) {
            $config['limit'] = $limit;
        }

        if ($offset !== null) {
            $config['offset'] = $offset;
        }

        if (empty($ids) === false) {
            $flat = array_merge(($ids['id'] ?? []), ($ids['uuid'] ?? []), ($ids['slug'] ?? []));
            if (empty($flat) === false) {
                $config['ids'] = array_values(array_unique($flat));
            }
        }

        $matches = $this->orObjectService->findAll(config: $config);

        return array_values(($matches['results'] ?? $matches));

    }//end searchObjects()
}//end class
