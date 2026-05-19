<?php

namespace OCA\OpenConnector\Db;

use DateInterval;
use DatePeriod;
use DateTime;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class JobLogMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openconnector_job_logs');
    }

    public function find(int $id): JobLog
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openconnector_job_logs')
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity($qb);
    }

    public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = []): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openconnector_job_logs')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

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

        return $this->findEntities($qb);
    }

	public function createForJob(Job $job, array $object): JobLog
	{
		$jobObject = [
			'jobId'         => $job->getId(),
			'jobClass'      => $job->getJobClass(),
			'jobListId'     => $job->getJobListId(),
			'arguments'     => $job->getArguments(),
			'lastRun'       => $job->getLastRun(),
			'nextRun'       => $job->getNextRun(),
		];

		$object = array_merge($jobObject, $object);

        switch($object['level']) {
            case 'INFO':
                $object['expires'] = new DateTime('+1 hour');
                break;
            case 'WARNING':
            case 'ERROR':
                $object['expires'] = new DateTime('+3 days');
                break;
            default:
                $object['expires'] = new DateTime('+30 days');
        }

		return $this->createFromArray($object);
	}

    public function createFromArray(array $object): JobLog
    {
		if (isset($object['executionTime']) === false) {
			$object['executionTime'] = 0;
		}

        $obj = new JobLog();
		$obj->hydrate($object);
		// Set uuid
		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}
        return $this->insert($obj);
    }

    public function updateFromArray(int $id, array $object): JobLog
    {
        $obj = $this->find($id);
		$obj->hydrate($object);
		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}

        return $this->update($obj);
    }

	/**
	 * Get the last call log.
	 *
	 * @return CallLog|null The last call log or null if no logs exist.
	 * @throws MultipleObjectsReturnedException
	 * @throws Exception
	 */
    public function getLastCallLog(): ?JobLog
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
           ->from('openconnector_job_logs')
           ->orderBy('created', 'DESC')
           ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

	/**
	 * Get job statistics grouped by date for a specific date range
	 *
	 * @param DateTime $from Start date
	 * @param DateTime $to End date
	 *
	 * @return array Array of daily statistics with counts per log level
	 * @throws Exception
	 */
    public function getJobStatsByDateRange(DateTime $from, DateTime $to): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
                $qb->createFunction('DATE(created) as date'),
                $qb->createFunction('SUM(CASE WHEN level = \'INFO\' THEN 1 ELSE 0 END) as info'),
                $qb->createFunction('SUM(CASE WHEN level = \'WARNING\' THEN 1 ELSE 0 END) as warning'),
                $qb->createFunction('SUM(CASE WHEN level = \'ERROR\' THEN 1 ELSE 0 END) as error'),
                $qb->createFunction('SUM(CASE WHEN level = \'DEBUG\' THEN 1 ELSE 0 END) as debug')
            )
            ->from('openconnector_job_logs')
            ->where($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
            ->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))))
            ->groupBy('date')
            ->orderBy('date', 'ASC');

        $result = $qb->executeQuery();
        $stats = [];

        // Create DatePeriod to iterate through all dates
        $period = new DatePeriod(
            $from,
            new DateInterval('P1D'),
            $to->modify('+1 day')
        );

        // Initialize all dates with zero values
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $stats[$dateStr] = [
                'info' => 0,
                'warning' => 0,
                'error' => 0,
                'debug' => 0
            ];
        }

        // Fill in actual values where they exist
        while ($row = $result->fetch()) {
            $stats[$row['date']] = [
                'info' => (int)$row['info'],
                'warning' => (int)$row['warning'],
                'error' => (int)$row['error'],
                'debug' => (int)$row['debug']
            ];
        }

        return $stats;
    }

	/**
	 * Get job statistics grouped by hour for a specific date range
	 *
	 * @param DateTime $from Start date
	 * @param DateTime $to End date
	 *
	 * @return array Array of hourly statistics with counts per log level
	 * @throws Exception
	 */
    public function getJobStatsByHourRange(DateTime $from, DateTime $to): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select(
                $qb->createFunction('HOUR(created) as hour'),
                $qb->createFunction('SUM(CASE WHEN level = \'INFO\' THEN 1 ELSE 0 END) as info'),
                $qb->createFunction('SUM(CASE WHEN level = \'WARNING\' THEN 1 ELSE 0 END) as warning'),
                $qb->createFunction('SUM(CASE WHEN level = \'ERROR\' THEN 1 ELSE 0 END) as error'),
                $qb->createFunction('SUM(CASE WHEN level = \'DEBUG\' THEN 1 ELSE 0 END) as debug')
            )
            ->from('openconnector_job_logs')
            ->where($qb->expr()->gte('created', $qb->createNamedParameter($from->format('Y-m-d H:i:s'))))
            ->andWhere($qb->expr()->lte('created', $qb->createNamedParameter($to->format('Y-m-d H:i:s'))))
            ->groupBy('hour')
            ->orderBy('hour', 'ASC');

        $result = $qb->executeQuery();
        $stats = [];

        while ($row = $result->fetch()) {
            $stats[$row['hour']] = [
                'info' => (int)$row['info'],
                'warning' => (int)$row['warning'],
                'error' => (int)$row['error'],
                'debug' => (int)$row['debug']
            ];
        }

        return $stats;
    }

    /**
     * Clear expired logs from the database
     *
     * This method deletes all job logs that have expired (i.e., their 'expires' date is earlier than the current date and time)
     * and have the 'expires' column set. This helps maintain database performance by removing old log entries that are no longer needed.
     *
     * @return bool True if any logs were deleted, false otherwise
     *
     * @throws \Exception Database operation exceptions
     *
     * @psalm-return bool
     * @phpstan-return bool
     */
    public function clearLogs(): bool
    {
        try {
            // Get the query builder for database operations
            $qb = $this->db->getQueryBuilder();

            // Build the delete query to remove expired job logs that have the 'expires' column set
            $qb->delete('openconnector_job_logs')
               ->where($qb->expr()->isNotNull('expires'))
               ->andWhere($qb->expr()->lt('expires', $qb->createFunction('NOW()')));

            // Execute the query and get the number of affected rows
            $result = $qb->executeStatement();

            // Return true if any rows were affected (i.e., any logs were deleted)
            return $result > 0;
        } catch (\Exception $e) {
            // Log the error for debugging purposes
            \OC::$server->getLogger()->error('Failed to clear expired job logs: ' . $e->getMessage(), [
                'app' => 'openconnector',
                'exception' => $e
            ]);

            // Re-throw the exception so the caller knows something went wrong
            throw $e;
        }
    }

    /**
     * Get the total count of all job logs.
     *
     * @param array $filters Optional filters to apply
     * @return int The total number of job logs in the database.
     * @throws \OCP\DB\Exception Database operation exceptions
     *
     * @psalm-return int
     * @phpstan-return int
     */
    public function getTotalCount(array $filters = []): int
    {
        $qb = $this->db->getQueryBuilder();

        // Select count of all logs
        $qb->select($qb->createFunction('COUNT(*) as count'))
           ->from('openconnector_job_logs');

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
}
