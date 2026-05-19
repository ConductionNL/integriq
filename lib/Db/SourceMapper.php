<?php

namespace OCA\OpenConnector\Db;

use OCA\OpenConnector\Db\Source;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class SourceMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openconnector_sources');
	}

	/**
	 * Find a source by ID, UUID, or slug
	 *
	 * @param int|string $id The ID, UUID, or slug of the source to find
	 * @return Source
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int|string $id): Source
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_sources');

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

	/**
	 * Find all sources matching the given criteria
	 *
	 * @param int|null $limit Maximum number of results to return
	 * @param int|null $offset Number of results to skip
	 * @param array<string,mixed> $filters Array of field => value pairs to filter by
	 * @param array<string> $searchConditions Array of search conditions to apply
	 * @param array<string,mixed> $searchParams Array of parameters for the search conditions
	 * @param array<string,array<string>> $ids Array of IDs to search for, keyed by type ('id', 'uuid', or 'slug')
	 * @return array<Source> Array of Source entities
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
			->from('openconnector_sources')
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

	public function createFromArray(array $object): Source
	{
		$obj = new Source();
		$obj->hydrate($object);

		// Set uuid
		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}

		// Set version
		if (empty($obj->getVersion()) === true) {
			$obj->setVersion('0.0.1');
		}

		return $this->insert(entity: $obj);
	}

	public function updateFromArray(int $id, array $object): Source
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

		$obj->hydrate($object);

		return $this->update($obj);
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
           ->from('openconnector_sources');

        $result = $qb->execute();
        $row = $result->fetch();

        // Return the total count
        return (int)$row['count'];
    }

    /**
     * Find or create a source based on the location.
     * If a source with the given location exists, it will be returned.
     * If no source exists, a new one will be created with the provided data.
     *
     * @param string $location The location to search for
     * @param array $defaultData Additional data to use when creating a new source
     * @return Source The found or newly created source
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws \OCP\DB\Exception
     */
    public function findOrCreateByLocation(string $location, array $defaultData = []): Source
    {
        // Create query builder
        $qb = $this->db->getQueryBuilder();

        // Search for existing source with the given location
        $qb->select('*')
            ->from('openconnector_sources')
            ->where(
                $qb->expr()->eq('location', $qb->createNamedParameter($location))
            );

        try {
            // Try to find existing source
			$source = $this->findEntity(query: $qb);
            return $source;
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // If source doesn't exist, create a new one
            $sourceData = array_merge([
                'location' => $location,
                'name' => basename($location),
                'type' => 'api',
                'enabled' => true,
            ], $defaultData);

            return $this->createFromArray($sourceData);
        }
    }

    /**
     * Find all sources that belong to a specific configuration.
     *
     * @param string $configurationId The ID of the configuration to find sources for
     * @return array<Source> Array of Source entities
     */
    public function findByConfiguration(string $configurationId): array
    {
        $sql = 'SELECT * FROM `' . $this->getTableName() . '` WHERE JSON_CONTAINS(configurations, ?)';
        return $this->findEntities($sql, [$configurationId]);
    }

    /**
     * Get all source ID to slug mappings
     *
     * @return array<string,string> Array mapping source IDs to their slugs
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
     * Get all source slug to ID mappings
     *
     * @return array<string,string> Array mapping source slugs to their IDs
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
}
