<?php

namespace OCA\OpenConnector\Db;

use OCA\OpenConnector\Db\Endpoint;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper class for handling Endpoint database operations
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EndpointMapper extends QBMapper
{
	/**
	 * Cache dirty flag file path
	 */
	private const CACHE_DIRTY_FLAG = '/tmp/openconnector_endpoints_cache_dirty';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openconnector_endpoints');
	}

	/**
	 * Find an endpoint by ID, UUID, or slug
	 *
	 * @param int|string $id The ID, UUID, or slug of the endpoint to find
	 * @return Endpoint
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int|string $id): Endpoint
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_endpoints');

		// If it's a string but can be converted to a numeric value without data loss, use as ID
		if (is_string($id) && ctype_digit($id) === false) {
			// For non-numeric strings, search in uuid and slug columns
			$qb->where(
				$qb->expr()->orX(
					$qb->expr()->eq('uuid', $qb->createNamedParameter($id)),
					$qb->expr()->eq('slug', $qb->createNamedParameter($id)),
					$qb->expr()->eq('id', $qb->createNamedParameter($id))
				)
			);

			return $this->findEntity(query: $qb);
		}

		// For numeric values, search in id column
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
		);

		return $this->findEntity(query: $qb);
	}

	public function findByRef(string $reference): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_endpoints')
			->where(
				$qb->expr()->eq('reference', $qb->createNamedParameter($reference))
			);

		return $this->findEntities(query: $qb);
	}

	/**
	 * Find all endpoints matching the given criteria
	 *
	 * @param int|null $limit Maximum number of results to return
	 * @param int|null $offset Number of results to skip
	 * @param array<string,mixed> $filters Array of field => value pairs to filter by
	 * @param array<string> $searchConditions Array of search conditions to apply
	 * @param array<string,mixed> $searchParams Array of parameters for the search conditions
	 * @param array<string,array<string>> $ids Array of IDs to search for, keyed by type ('id', 'uuid', or 'slug')
	 * @return array<Endpoint> Array of Endpoint entities
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
		?array $ids = []
	): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_endpoints')
			->setMaxResults($limit)
			->setFirstResult($offset);

		// Apply ID filters if provided
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

		// Apply regular filters
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
			$qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
			foreach ($searchParams as $param => $value) {
				$qb->setParameter($param, $value);
			}
		}

		return $this->findEntities(query: $qb);
	}

	private function createEndpointRegex(string $endpoint): string {
		$regex = '#^' . preg_replace(
			['#\/{{([^}}]+)}}\/#', '#\/{{([^}}]+)}}$#'],
			['/([^/]+)/', '(/([^/]+))?'],
			$endpoint
		) . '#';

		// Replace only the LAST occurrence of "(/([^/]+))?#" with "(?:/([^/]+))?$#"
		$regex = preg_replace_callback(
			'/\(\/\(\[\^\/\]\+\)\)\?#/',
			function () {
				return '(?:/([^/]+))?$#';
			},
			$regex,
			1 // Limit to only one replacement
		);

		if (str_ends_with($regex, '?#') === false && str_ends_with($regex, '$#') === false) {
			$regex = substr($regex, 0, -1) . '$#';
		}

		return $regex;
	}

	public function createFromArray(array $object): Endpoint
	{
		$obj = new Endpoint();
		$obj->hydrate($object);

		// Set uuid
		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}

		// Set version
		if (empty($obj->getVersion()) === true) {
			$obj->setVersion('0.0.1');
		}

		// Endpoint-specific logic
		if($obj->getEndpointRegex() === null) {
			$obj->setEndpointRegex($this->createEndpointRegex($obj->getEndpoint()));
		}
		$obj->setEndpointArray(explode('/', $obj->getEndpoint()));

		$result = $this->insert(entity: $obj);
		
		// Mark cache as dirty - will be refreshed on next request
		$this->setCacheDirty();
		
		return $result;
	}

	public function updateFromArray(int $id, array $object): Endpoint
	{
		$obj = $this->find($id);

		// Set version
		if (empty($obj->getVersion()) === true) {
			$object['version'] = '0.0.1';
		} else if (empty($object['version']) === true) {
			// Update version
			$version = explode('.', $obj->getVersion());
			if (isset($version[2]) === true) {
				$version[2] = (int) $version[2] + 1;
				$object['version'] = implode('.', $version);
			}
		}

		if($obj->getEndpoint() !== $object['endpoint']) {
			$updateRegex = true;
		}

		$obj->hydrate($object);

		// Endpoint-specific logic
		if($updateRegex === true) {
			$obj->setEndpointRegex($this->createEndpointRegex($obj->getEndpoint()));
		}
		$obj->setEndpointArray(explode('/', $obj->getEndpoint()));

		$result = $this->update($obj);
		
		// Mark cache as dirty - will be refreshed on next request
		$this->setCacheDirty();
		
		return $result;
	}

    /**
     * Get the total count of all call logs.
     *
     * @return int The total number of call logs in the database.
     */
    public function getTotalCallCount(): int
    {
        $qb = $this->db->getQueryBuilder();

        // Select count of all logs
        $qb->select($qb->createFunction('COUNT(*) as count'))
           ->from('openconnector_endpoints');

        $result = $qb->executeQuery();
        $row = $result->fetch();

        // Return the total count
        return (int)$row['count'];
    }

    /**
     * Find endpoints that match a given path and method using regex comparison
     *
     * @param string $path The path to match against endpoint regex patterns
     * @param string $method The HTTP method to filter by (GET, POST, etc)
     * @return array Array of matching Endpoint entities
     */
    public function findByPathRegex(string $path, string $method): array
    {
        // Get all endpoints first since we need to do regex comparison
        $endpoints = $this->findAll();

        // Filter endpoints where both path matches regex pattern and method matches
        return array_filter($endpoints, function(Endpoint $endpoint) use ($path, $method) {
            // Get the regex pattern from the endpoint
            $pattern = $endpoint->getEndpointRegex();

            // Skip if no regex pattern is set
            if (empty($pattern) === true) {
                return false;
            }

            // Check if both path matches the regex pattern and method matches
            return preg_match($pattern, $path) === 1 &&
                   $endpoint->getMethod() === $method;
        });
    }

    /**
     * Find all endpoints that belong to a specific configuration.
     *
     * @param string $configurationId The ID of the configuration to find endpoints for
     * @return array<Endpoint> Array of Endpoint entities
     */
    public function findByConfiguration(string $configurationId): array
    {
        $sql = 'SELECT * FROM `' . $this->getTableName() . '` WHERE JSON_CONTAINS(configurations, ?)';
        return $this->findEntities($sql, [$configurationId]);
    }

    /**
     * Find all endpoints that are connected to a specific register and/or schema.
     * Endpoints are considered connected if:
     * 1. Their targetType is 'register/schema'
     * 2. The targetId matches the provided register and/or schema
     *
     * @param string|null $registerId The ID of the register to find endpoints for
     * @param string|null $schemaId The ID of the schema to find endpoints for
     * @return array<Endpoint> Array of Endpoint entities
     * @throws \InvalidArgumentException If neither registerId nor schemaId is provided
     */
    public function getByTarget(?string $registerId = null, ?string $schemaId = null): array
    {
        // Validate that at least one parameter is provided
        if ($registerId === null && $schemaId === null) {
            throw new InvalidArgumentException('Either registerId or schemaId must be provided');
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('target_type', $qb->createNamedParameter('register/schema'))
            );

        // Build the target_id condition based on provided parameters
        if ($registerId !== null && $schemaId !== null) {
            // Both register and schema are provided - exact match
            $qb->andWhere(
                $qb->expr()->eq('target_id', $qb->createNamedParameter($registerId . '/' . $schemaId))
            );
            return $this->findEntities($qb);
        }

        if ($registerId !== null) {
            // Only register is provided - match any schema
            $qb->andWhere(
                $qb->expr()->like('target_id', $qb->createNamedParameter($registerId . '/%'))
            );
            return $this->findEntities($qb);
        }

        // Only schema is provided - match any register
        $qb->andWhere(
            $qb->expr()->like('target_id', $qb->createNamedParameter('%/' . $schemaId))
        );

        return $this->findEntities($qb);
    }

    /**
     * Get all endpoint ID to slug mappings
     *
     * @return array<string,string> Array mapping endpoint IDs to their slugs
     */
    public function getIdToSlugMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $result = $qb->executeQuery();
        $mappings = [];
        while ($row = $result->fetch()) {
            $mappings[$row['id']] = $row['slug'];
        }
        return $mappings;
    }

    /**
     * Get all endpoint slug to ID mappings
     *
     * @return array<string,string> Array mapping endpoint slugs to their IDs
     */
    public function getSlugToIdMap(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'slug')
            ->from($this->getTableName());

        $result = $qb->executeQuery();
        $mappings = [];
        while ($row = $result->fetch()) {
            $mappings[$row['slug']] = $row['id'];
        }
        return $mappings;
    }

	/**
	 * Override delete method to invalidate cache
	 *
	 * @param Entity $entity The entity to delete
	 * @return Entity The deleted entity
	 */
	public function delete(Entity $entity): Entity
	{
		$result = parent::delete($entity);
		
		// Mark cache as dirty - will be refreshed on next request
		$this->setCacheDirty();
		
		return $result;
	}

	/**
	 * Mark the endpoint cache as dirty
	 * 
	 * This creates a flag file that indicates the cache needs to be refreshed.
	 * This is a lightweight way to signal cache invalidation across requests.
	 *
	 * @return void
	 */
	private function setCacheDirty(): void
	{
		try {
			file_put_contents(self::CACHE_DIRTY_FLAG, time());
		} catch (\Exception $e) {
			// Fail silently - cache invalidation is not critical
		}
	}

	/**
	 * Check if the endpoint cache is dirty (needs refresh)
	 *
	 * @return bool True if cache needs refresh
	 */
	public function isCacheDirty(): bool
	{
		return file_exists(self::CACHE_DIRTY_FLAG);
	}

	/**
	 * Mark the cache as clean (after successful refresh)
	 *
	 * @return void
	 */
	public function setCacheClean(): void
	{
		try {
			if (file_exists(self::CACHE_DIRTY_FLAG)) {
				unlink(self::CACHE_DIRTY_FLAG);
			}
		} catch (\Exception $e) {
			// Fail silently - cache management is not critical
		}
	}


}
