<?php
/**
 * OpenConnector Mapping mapper (OpenRegister-backed adapter).
 *
 * Post OpenRegister-cutover the `openconnector_mappings` table was dropped. This
 * mapper is no longer a QBMapper: it is a thin adapter over
 * `\OCA\OpenRegister\Service\ObjectService` (register `openconnector`, schema
 * `mapping`). It keeps the original public surface (find / findByRef / findAll /
 * createFromArray / updateFromArray / slug maps) so the existing call sites in
 * the engine keep working, but every read/write now flows through the
 * OpenRegister object API and returns hydrated `Mapping` value objects.
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
 * Class MappingMapper
 *
 * OpenRegister-backed adapter for mapping objects.
 *
 * @package OCA\OpenConnector\Db
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class MappingMapper
{

    /**
     * The OpenRegister register mappings live in.
     *
     * @var string
     */
    private const REGISTER = 'openconnector';

    /**
     * The OpenRegister schema for mapping objects.
     *
     * @var string
     */
    private const SCHEMA = 'mapping';

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
     * Find a mapping by ID, UUID, or slug.
     *
     * @param int|string $id The ID, UUID, or slug of the mapping to find.
     *
     * @return Mapping The hydrated mapping value object.
     *
     * @throws DoesNotExistException When no mapping matches the identifier.
     */
    public function find(int | string $id): Mapping
    {
        $object = $this->orObjectService->find(
            id: (string) $id,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        if ($object === null) {
            throw new DoesNotExistException('The mapping you are looking for does not exist');
        }

        return (new Mapping())->hydrate($object->jsonSerialize());

    }//end find()

    /**
     * Find all mappings carrying the given reference.
     *
     * @param string $reference The reference to match.
     *
     * @return array<Mapping> Array of Mapping value objects.
     */
    public function findByRef(string $reference): array
    {
        return $this->findAll(filters: ['reference' => $reference]);

    }//end findByRef()

    /**
     * Find all mappings matching the given criteria.
     *
     * @param int|null   $limit            Maximum number of results to return.
     * @param int|null   $offset           Number of results to skip.
     * @param array|null $filters          Array of field => value pairs to filter by.
     * @param array|null $searchConditions Unused (kept for signature compatibility).
     * @param array|null $searchParams     Unused (kept for signature compatibility).
     * @param array|null $ids              Array of IDs to search for, keyed by type.
     *
     * @return array<Mapping> Array of Mapping value objects.
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
            fn ($object): Mapping => (new Mapping())->hydrate($object->jsonSerialize()),
            $objects
        );

    }//end findAll()

    /**
     * Create a new mapping from array data.
     *
     * @param array $object Array of mapping data.
     *
     * @return Mapping The created mapping value object.
     */
    public function createFromArray(array $object): Mapping
    {
        if (empty($object['uuid']) === true) {
            $object['uuid'] = (string) Uuid::v4();
        }

        if (empty($object['version']) === true) {
            $object['version'] = '0.0.1';
        }

        // OpenRegister owns identity via the uuid; drop any stray int `id`.
        unset($object['id']);

        $saved = $this->orObjectService->saveObject(
            object: $object,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        return (new Mapping())->hydrate($saved->jsonSerialize());

    }//end createFromArray()

    /**
     * Update an existing mapping from array data.
     *
     * @param int|string $id     ID/UUID of the mapping to update.
     * @param array      $object Array of updated mapping data.
     *
     * @return Mapping The updated mapping value object.
     *
     * @throws DoesNotExistException When the mapping does not exist.
     */
    public function updateFromArray(int | string $id, array $object): Mapping
    {
        $existing = $this->find($id);

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

        return (new Mapping())->hydrate($saved->jsonSerialize());

    }//end updateFromArray()

    /**
     * Get the total count of all mappings.
     *
     * @return int The total number of mapping objects.
     */
    public function getTotalCallCount(): int
    {
        return count($this->searchObjects());

    }//end getTotalCallCount()

    /**
     * Find all mappings that belong to a specific configuration.
     *
     * @param string $configurationId The ID of the configuration to find mappings for.
     *
     * @return array<Mapping> Array of Mapping value objects.
     */
    public function findByConfiguration(string $configurationId): array
    {
        return array_values(
            array_filter(
                $this->findAll(),
                static function (Mapping $mapping) use ($configurationId): bool {
                    return in_array($configurationId, (array) $mapping->jsonSerialize()['configurations'], true) === true;
                }
            )
        );

    }//end findByConfiguration()

    /**
     * Get all mapping ID to slug mappings.
     *
     * @return array<string,string> Array mapping mapping IDs to their slugs.
     */
    public function getIdToSlugMap(): array
    {
        $mappings = [];
        foreach ($this->findAll() as $mapping) {
            $data                           = $mapping->jsonSerialize();
            $mappings[(string) $data['id']] = $mapping->getSlug();
        }

        return $mappings;

    }//end getIdToSlugMap()

    /**
     * Get all mapping slug to ID mappings.
     *
     * @return array<string,string> Array mapping mapping slugs to their IDs.
     */
    public function getSlugToIdMap(): array
    {
        $mappings = [];
        foreach ($this->findAll() as $mapping) {
            $data                          = $mapping->jsonSerialize();
            $mappings[$mapping->getSlug()] = (string) $data['id'];
        }

        return $mappings;

    }//end getSlugToIdMap()

    /**
     * Run an OpenRegister object search scoped to the mapping register/schema.
     *
     * @param array    $filters Field filters keyed by mapping property.
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
