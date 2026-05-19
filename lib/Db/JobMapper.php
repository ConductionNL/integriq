<?php

namespace OCA\OpenConnector\Db;

use OCA\OpenConnector\Db\Job;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use DateTime;
use Symfony\Component\Uid\Uuid;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class JobMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openconnector_jobs');
	}

	/**
	 * Find a job by ID, UUID, or slug
	 *
	 * @param int|string $id The ID, UUID, or slug of the job to find
	 * @return Job
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int|string $id): Job
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_jobs');

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
			->from('openconnector_jobs')
			->where(
				$qb->expr()->eq('reference', $qb->createNamedParameter($reference))
			);

		return $this->findEntities(query: $qb);
	}

	/**
	 * Find all jobs matching the given criteria
	 *
	 * @param int|null $limit Maximum number of results to return
	 * @param int|null $offset Number of results to skip
	 * @param array<string,mixed> $filters Array of field => value pairs to filter by
	 * @param array<string> $searchConditions Array of search conditions to apply
	 * @param array<string,mixed> $searchParams Array of parameters for the search conditions
	 * @param array<string,array<string>> $ids Array of IDs to search for, keyed by type ('id', 'uuid', or 'slug')
	 * @return array<Job> Array of Job entities
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
			->from('openconnector_jobs')
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

	public function createFromArray(array $object): Job
	{
		$obj = new Job();
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

	public function updateFromArray(int $id, array $object): Job
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
     * Get the total count of all jobs.
     *
     * @param array $filters Optional filters to apply
     * @return int The total number of jobs in the database.
     * @throws \OCP\DB\Exception Database operation exceptions
     *
     * @psalm-return int
     * @phpstan-return int
     */
    public function getTotalCount(array $filters = []): int
    {
        $qb = $this->db->getQueryBuilder();

        // Select count of all jobs
        $qb->select($qb->createFunction('COUNT(*) as count'))
           ->from('openconnector_jobs');

        // Apply filters if provided
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

        $result = $qb->executeQuery();
        $row = $result->fetch();

        // Return the total count
        return (int)$row['count'];
    }

    /**
     * Find all jobs that belong to a specific configuration.
     *
     * @param string $configurationId The ID of the configuration to find jobs for
     * @return array<Job> Array of Job entities
     */
    public function findByConfiguration(string $configurationId): array
    {
        $sql = 'SELECT * FROM `' . $this->getTableName() . '` WHERE JSON_CONTAINS(configurations, ?)';
        return $this->findEntities($sql, [$configurationId]);
    }

    /**
     * Find all jobs that have any of the given IDs in their arguments.
     * This will search through the arguments JSON field for specific ID types.
     *
     * @param array<string> $synchronizationIds Array of synchronization IDs to search for
     * @param array<string> $endpointIds Array of endpoint IDs to search for
     * @param array<string> $sourceIds Array of source IDs to search for
     * @return array<Job> Array of Job entities
     */
    /**
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function findByArgumentIds(
        array $synchronizationIds = [],
        array $endpointIds = [],
        array $sourceIds = []
    ): array {
        if (empty($synchronizationIds) && empty($endpointIds) && empty($sourceIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        // Build conditions for each type of ID
        $conditions = [];

        // Add conditions for synchronization IDs
        if (!empty($synchronizationIds)) {
            $syncConditions = [];
            foreach ($synchronizationIds as $id) {
                $syncConditions[] = $qb->expr()->like('arguments', $qb->createNamedParameter('%"synchronizationId":"' . $id . '"%'));
            }
            $conditions[] = $qb->expr()->orX(...$syncConditions);
        }

        // Add conditions for endpoint IDs
        if (!empty($endpointIds)) {
            $endpointConditions = [];
            foreach ($endpointIds as $id) {
                $endpointConditions[] = $qb->expr()->like('arguments', $qb->createNamedParameter('%"endpointId":"' . $id . '"%'));
            }
            $conditions[] = $qb->expr()->orX(...$endpointConditions);
        }

        // Add conditions for source IDs
        if (!empty($sourceIds)) {
            $sourceConditions = [];
            foreach ($sourceIds as $id) {
                $sourceConditions[] = $qb->expr()->like('arguments', $qb->createNamedParameter('%"sourceId":"' . $id . '"%'));
            }
            $conditions[] = $qb->expr()->orX(...$sourceConditions);
        }

        // Combine all conditions with OR
        $qb->where($qb->expr()->orX(...$conditions));

        return $this->findEntities($qb);
    }

    /**
     * Get all job ID to slug mappings
     *
     * @return array<string,string> Array mapping job IDs to their slugs
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
     * Get all job slug to ID mappings
     *
     * @return array<string,string> Array mapping job slugs to their IDs
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
     * Find all jobs that are enabled and scheduled to run (nextRun <= now)
     *
     * @return array<Job> Array of runnable Job entities
     */
    public function findRunnable(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openconnector_jobs')
            ->where($qb->expr()->eq('is_enabled', $qb->createNamedParameter(true)))
            ->andWhere($qb->expr()->isNotNull('next_run'))
            ->andWhere($qb->expr()->lte('next_run', $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s'))));
        return $this->findEntities(query: $qb);
    }
}
