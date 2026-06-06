<?php

namespace OCA\OpenConnector\Db;

use OCA\OpenConnector\Db\Event;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Mapper class for Event entities
 *
 * Handles database operations for events including CRUD operations
 */
class EventMapper extends QBMapper
{
	/**
	 * Constructor
	 *
	 * @param IDBConnection $db Database connection
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openconnector_events');
	}

	/**
	 * Find a single event by ID
	 *
	 * @param int $id The event ID
	 * @return Event The found event
	 */
	public function find(int $id): Event
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_events')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity(query: $qb);
	}

	/**
	 * Find all events with optional filtering and pagination
	 *
	 * @param int|null $limit Maximum number of results
	 * @param int|null $offset Number of records to skip
	 * @param array|null $filters Key-value pairs for filtering
	 * @param array|null $searchConditions Search conditions
	 * @param array|null $searchParams Search parameters
	 * @return array Array of Event objects
	 */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_events')
			->setMaxResults($limit)
			->setFirstResult($offset);

        foreach ($filters as $filter => $value) {
			if ($value === 'IS NOT NULL') {
				$qb->andWhere($qb->expr()->isNotNull($filter));
			} elseif ($value === 'IS NULL') {
				$qb->andWhere($qb->expr()->isNull($filter));
			} else {
				$qb->andWhere($qb->expr()->eq($filter, $qb->createNamedParameter($value)));
			}
        }

		if (empty($searchConditions) === false) {
            $qb->andWhere('(' . implode(' OR ', $searchConditions) . ')');
            foreach ($searchParams as $param => $value) {
                $qb->setParameter($param, $value);
            }
        }

		return $this->findEntities(query: $qb);
	}

	/**
	 * Create a new event from array data
	 *
	 * @param array $object Array of event data
	 * @return Event The created event
	 */
	public function createFromArray(array $object): Event
	{
		$obj = new Event();
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

	/**
	 * Update an existing event from array data
	 *
	 * @param int $id Event ID to update
	 * @param array $object Array of event data
	 * @return Event The updated event
	 */
	public function updateFromArray(int $id, array $object): Event
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
     * Get the total count of all events
     *
     * @return int The total number of events in the database
     */
    public function getTotalCount(): int
    {
        $qb = $this->db->getQueryBuilder();

        // Select count of all events
        $qb->select($qb->createFunction('COUNT(*) as count'))
           ->from('openconnector_events');

        $result = $qb->executeQuery();
        $row = $result->fetch();

        // Return the total count
        return (int)$row['count'];
    }
}
