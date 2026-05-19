<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\ISession;
use OCP\IUserSession;
use Symfony\Component\Uid\Uuid;
use OCP\Session\Exceptions\SessionNotAvailableException;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class SynchronizationLogMapper extends QBMapper
{
	public function __construct(
		IDBConnection $db,
		private readonly IUserSession $userSession,
		private readonly ISession $session
	) {
		parent::__construct($db, 'openconnector_synchronization_logs');
	}

	public function find(int $id): SynchronizationLog
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_synchronization_logs')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
			);

		return $this->findEntity($qb);
	}

	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = []
	): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_synchronization_logs')
			->orderBy('created', 'DESC')
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

	/**
	 * Process contracts array to ensure it only contains valid UUIDs
	 *
	 * @param array $contracts Array of contracts or contract objects
	 * @return array Processed array containing only valid UUIDs
	 */
	private function processContracts(array $contracts): array
	{
		return array_values(array_filter(
			array_map(
				function ($contract) {
					if (is_object($contract)) {
						// If it's an object with getUuid method, use that
						if (method_exists($contract, 'getUuid')) {
							return $contract->getUuid() ?: null;
						}
						return null;
					}
					// If it's already a string (UUID), return it
					return is_string($contract) ? $contract : null;
				},
				$contracts
			)
		));
	}

	/**
	 * Creates a new synchronization log entry
	 *
	 * @param array $object The log data
	 * @return SynchronizationLog The created log entry
	 */
	public function createFromArray(array $object): SynchronizationLog
	{
		$obj = new SynchronizationLog();

		// Auto-fill system fields
		$object['uuid'] = $object['uuid'] ?? Uuid::v4();
		$object['userId'] = $object['userId'] ?? $this->userSession->getUser()?->getUID();

		// Catch error from session, because when running from a Job this might cause an error preventing the Job from being ran.
		try {
			$object['sessionId'] = $object['sessionId'] ?? $this->session->getId();
		} catch (SessionNotAvailableException $exception) {
			$object['sessionId'] = null;
		}

		$object['created'] = $object['created'] ?? new DateTime();
		$object['expires'] = $object['expires'] ?? new DateTime('+30 days');
		$object['test'] = $object['test'] ?? false;
		$object['force'] = $object['force'] ?? false;

		// Process contracts in results if they exist
		if (isset($object['result']['contracts']) && is_array($object['result']['contracts'])) {
			$object['result']['contracts'] = $this->processContracts($object['result']['contracts']);
		}

		$obj->hydrate($object);

		// Set uuid
		if ($obj->getUuid() === null){
			$obj->setUuid(Uuid::v4());
		}

		return $this->insert($obj);
	}

	/**
	 * Updates an existing synchronization log entry
	 *
	 * @param int $id The ID of the log entry to update
	 * @param array $object The updated log data
	 * @return SynchronizationLog The updated log entry
	 */
	public function updateFromArray(int $id, array $object): SynchronizationLog
	{
		$obj = $this->find($id);

		// Process contracts in results if they exist
		if (isset($object['result']['contracts']) && is_array($object['result']['contracts'])) {
			$object['result']['contracts'] = $this->processContracts($object['result']['contracts']);
		}

        // If the log is successful, limit log retention to 1 hour.
        if($object['message'] === 'Success') {
            $object['expires'] = new DateTime('+1 hour');
        }

		$obj->hydrate($object);

		return $this->update($obj);
	}

	/**
	 * Get the total count of synchronization logs
	 *
	 * @param array $filters Optional filters to apply
	 * @return int The total number of logs
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
		   ->from('openconnector_synchronization_logs');

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

		$result = $qb->execute();
		$row = $result->fetch();

		// Return the total count
		return (int)$row['count'];
	}

	/**
	 * Cleans up expired log entries
	 *
	 * @return int Number of deleted entries
	 */
	public function cleanupExpired(): int
	{
		$qb = $this->db->getQueryBuilder();

		$qb->delete('openconnector_synchronization_logs')
            ->andWhere($qb->expr()->lt('expires', $qb->createFunction('NOW()')));

		return $qb->executeStatement();
	}
}
