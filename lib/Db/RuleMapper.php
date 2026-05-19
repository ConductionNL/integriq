<?php

namespace OCA\OpenConnector\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Class RuleMapper
 *
 * Handles database operations for rules
 *
 * @package OCA\OpenConnector\Db
 */
/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class RuleMapper extends QBMapper
{
	/**
	 * @param IDBConnection $db
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'openconnector_rules');
	}

	/**
	 * Find a rule by ID, UUID, or slug
	 *
	 * @param int|string $id The ID, UUID, or slug of the rule to find
	 * @return Rule
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int|string $id): Rule
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_rules');

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

			return $this->findEntity($qb);
		}

		// For numeric values, search in id column
		$qb->where(
			$qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
		);

		return $this->findEntity($qb);
	}

	/**
	 * Find a rule by reference
	 *
	 * @param int $id
	 * @return Rule
	 */
	public function findByRef(string $reference): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from('openconnector_rules')
			->where(
				$qb->expr()->eq('reference', $qb->createNamedParameter($reference))
			);

		return $this->findEntities(query: $qb);
	}

	/**
	 * Find all rules with optional filtering
	 *
	 * @param int|null $limit Maximum number of results to return
	 * @param int|null $offset Number of results to skip
	 * @param array<string,mixed> $filters Array of field => value pairs to filter by
	 * @param array<string> $searchConditions Array of search conditions to apply
	 * @param array<string,mixed> $searchParams Array of parameters for the search conditions
	 * @param array<string,array<string>> $ids Array of IDs to search for, keyed by type ('id', 'uuid', or 'slug')
	 * @return array<Rule> Array of Rule entities
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
			->from('openconnector_rules')
			->orderBy('order', 'ASC')
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

		return $this->findEntities($qb);
	}

	/**
	 * Create a new rule from array data
	 *
	 * @param array<string,mixed> $object
	 * @return Rule
	 */
	public function createFromArray(array $object): Rule
	{
		$obj = new Rule();
		$obj->hydrate($object);

		// Set uuid
		if ($obj->getUuid() === null) {
			$obj->setUuid(Uuid::v4());
		}

		// Set version
		if (empty($obj->getVersion()) === true) {
			$obj->setVersion('0.0.1');
		}

		// Rule-specific logic
		// If no order is specified, append to the end
		if ($obj->getOrder() === null) {
			$maxOrder = $this->getMaxOrder();
			$obj->setOrder($maxOrder + 1);
		}

		return $this->insert(entity: $obj);
	}

	/**
	 * Update a rule from array data
	 *
	 * @param int $id
	 * @param array<string,mixed> $object
	 * @return Rule
	 */
	public function updateFromArray(int $id, array $object): Rule
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
	 * Get the highest order number for rules
	 *
	 * @return int
	 */
	private function getMaxOrder(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COALESCE(MAX(`order`), 0) as max_order'))
		   ->from('openconnector_rules');

		$result = $qb->execute();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['max_order']);
	}

	/**
	 * Get the total count of all rules
	 *
	 * @return int The total number of rules in the database
	 */
	public function getTotalCount(): int
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->createFunction('COUNT(*) as count'))
		   ->from('openconnector_rules');

		$result = $qb->execute();
		$row = $result->fetch();

		return (int)$row['count'];
	}

	/**
	 * Reorder rules
	 *
	 * @param array<int,int> $orderMap Array of rule ID => new order
	 * @return void
	 */
	public function reorder(array $orderMap): void
	{
		foreach ($orderMap as $ruleId => $newOrder) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('openconnector_rules')
			   ->set('order', $qb->createNamedParameter($newOrder, IQueryBuilder::PARAM_INT))
			   ->where($qb->expr()->eq('id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
			   ->execute();
		}
	}

	/**
	 * Find all rules that belong to a specific configuration.
	 *
	 * @param string $configurationId The ID of the configuration to find rules for
	 * @return array<Rule> Array of Rule entities
	 */
	public function findByConfiguration(string $configurationId): array
	{
		$sql = 'SELECT * FROM `' . $this->getTableName() . '` WHERE JSON_CONTAINS(configurations, ?)';
		return $this->findEntities($sql, [$configurationId]);
	}

	/**
	 * Get a map of rule IDs to their corresponding slugs
	 *
	 * @return array<string,string> Array mapping rule IDs to slugs
	 */
	public function getIdToSlugMap(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug')
		   ->from($this->getTableName());

		$result = $qb->executeQuery();
		$map = [];
		while ($row = $result->fetch()) {
			$map[$row['id']] = $row['slug'];
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Get a map of rule slugs to their corresponding IDs
	 *
	 * @return array<string,string> Array mapping rule slugs to IDs
	 */
	public function getSlugToIdMap(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'slug')
		   ->from($this->getTableName());

		$result = $qb->executeQuery();
		$map = [];
		while ($row = $result->fetch()) {
			$map[$row['slug']] = $row['id'];
		}
		$result->closeCursor();

		return $map;
	}
}
