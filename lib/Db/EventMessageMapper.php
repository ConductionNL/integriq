<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Class EventMessageMapper
 *
 * Handles database operations for event messages
 *
 * @package OCA\OpenConnector\Db
 */
/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class EventMessageMapper extends QBMapper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db Database connection
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openconnector_event_messages');
    }

    /**
     * Find a message by ID
     *
     * @param int $id The message ID
     * @return EventMessage
     */
    public function find(int $id): EventMessage
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openconnector_event_messages')
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity($qb);
    }

    /**
     * Find all messages matching the given criteria
     *
     * @param int|null $limit Maximum number of results
     * @param int|null $offset Number of records to skip
     * @param array|null $filters Key-value pairs for filtering
     * @return EventMessage[]
     */
    public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = []): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openconnector_event_messages')
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

        return $this->findEntities($qb);
    }

    /**
     * Find messages that need to be retried
     *
     * @param int $maxRetries Maximum number of retry attempts
     * @return EventMessage[]
     */
    public function findPendingRetries(int $maxRetries = 5): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from('openconnector_event_messages')
            ->where(
                $qb->expr()->eq('status', $qb->createNamedParameter('pending')),
                $qb->expr()->lt('retry_count', $qb->createNamedParameter($maxRetries, IQueryBuilder::PARAM_INT)),
                $qb->expr()->orX(
                    $qb->expr()->isNull('next_attempt'),
                    $qb->expr()->lte('next_attempt', $qb->createNamedParameter(new DateTime(), IQueryBuilder::PARAM_DATE))
                )
            );

        return $this->findEntities($qb);
    }

    /**
     * Create a new message from array data
     *
     * @param array $data Message data
     * @return EventMessage
     */
    public function createFromArray(array $data): EventMessage
    {
        $obj = new EventMessage();
        $obj->hydrate($data);
        
        // Set uuid
        if ($obj->getUuid() === null) {
            $obj->setUuid(Uuid::v4());
        }
        
        // Set timestamps
        $obj->setCreated(new DateTime());
        $obj->setUpdated(new DateTime());

        return $this->insert(entity: $obj);
    }

    /**
     * Update an existing message
     *
     * @param int $id Message ID
     * @param array $data Updated message data
     * @return EventMessage
     */
    public function updateFromArray(int $id, array $data): EventMessage
    {
        $obj = $this->find($id);
        $obj->hydrate($data);
        
        // Update timestamp
        $obj->setUpdated(new DateTime());

        return $this->update($obj);
    }

    /**
     * Mark a message as delivered
     *
     * @param int $id Message ID
     * @param array $response Response from the consumer
     * @return EventMessage
     */
    public function markDelivered(int $id, array $response): EventMessage
    {
        return $this->updateFromArray($id, [
            'status' => 'delivered',
            'lastResponse' => $response,
            'lastAttempt' => new DateTime()
        ]);
    }

    /**
     * Mark a message as failed
     *
     * @param int $id Message ID
     * @param array $response Error response
     * @param int $backoffMinutes Minutes to wait before next attempt
     * @return EventMessage
     */
    public function markFailed(int $id, array $response, int $backoffMinutes = 5): EventMessage
    {
        $message = $this->find($id);
        $message->incrementRetry($backoffMinutes);
        
        return $this->updateFromArray($id, [
            'status' => 'failed',
            'lastResponse' => $response,
            'retryCount' => $message->getRetryCount(),
            'lastAttempt' => $message->getLastAttempt(),
            'nextAttempt' => $message->getNextAttempt()
        ]);
    }
} 