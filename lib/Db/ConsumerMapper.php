<?php

namespace OCA\OpenConnector\Db;

use OCA\OpenConnector\Db\Consumer;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Class ConsumerMapper
 *
 * This class is responsible for mapping Consumer entities to the database.
 * It provides methods for finding, creating, and updating Consumer objects.
 *
 * @package OCA\OpenConnector\Db
 */
class ConsumerMapper extends QBMapper
{
	/**
	 * ConsumerMapper constructor.
	 *
	 * @param IDBConnection $db The database connection
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openconnector_consumers');
	}

	/**
	 * Find a Consumer by its ID.
	 *
	 * @param int $id The ID of the Consumer
	 * @return Consumer The found Consumer entity
	 */
	public function find(int $id): Consumer
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_consumers')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity(query: $qb);
	}

	/**
	 * Find all Consumers with optional filtering and pagination.
	 *
	 * @param int|null $limit Maximum number of results to return
	 * @param int|null $offset Number of results to skip
	 * @param array|null $filters Associative array of filters
	 * @param array|null $searchConditions Array of search conditions
	 * @param array|null $searchParams Array of search parameters
	 * @return array An array of Consumer entities
	 */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_consumers')
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
	 * Create a new Consumer from an array of data.
	 *
	 * @param array $object An array of Consumer data
	 * @return Consumer The newly created Consumer entity
	 */
	public function createFromArray(array $object): Consumer
	{
		$obj = new Consumer();
		$obj->hydrate($object);
		// Set uuid
		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}
		return $this->insert(entity: $obj);
	}

	/**
	 * Update an existing Consumer from an array of data.
	 *
	 * @param int $id The ID of the Consumer to update
	 * @param array $object An array of updated Consumer data
	 * @return Consumer The updated Consumer entity
	 */
	public function updateFromArray(int $id, array $object): Consumer
	{
		$obj = $this->find($id);
		$obj->hydrate($object);

		// @todo: does Consumer need a version? $version field does currently not exist.
//		if (isset($object['version']) === false) {
//			// Set or update the version
//			$version = explode('.', $obj->getVersion());
//			$version[2] = (int)$version[2] + 1;
//			$obj->setVersion(implode('.', $version));
//		}

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
           ->from('openconnector_consumers');

        $result = $qb->executeQuery();
        $row = $result->fetch();

        // Return the total count
        return (int)$row['count'];
    }
}
