<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class EventMessage
 *
 * Represents a message that needs to be or has been delivered to a consumer based on their subscription.
 * Tracks delivery attempts, responses, and current status.
 *
 * @package OCA\OpenConnector\Db
 */
class EventMessage extends Entity implements JsonSerializable
{

    protected ?string $uuid = null;

    // Unique identifier for the message
    protected ?int $eventId = null;

    // Reference to the original event
    protected ?int $consumerId = null;

    // Reference to the consumer
    protected ?int $subscriptionId = null;

    // Reference to the subscription
    protected ?string $status = 'pending';

    // Current status of the message (pending, delivered, failed)
    protected ?array $payload = null;

    // The actual message payload to be delivered
    protected ?array $lastResponse = null;

    // The last response received from the consumer
    protected int $retryCount = 0;

    // Number of delivery attempts
    protected ?DateTime $lastAttempt = null;

    // Timestamp of the last delivery attempt
    protected ?DateTime $nextAttempt = null;

    // Scheduled time for next attempt
    protected ?DateTime $created = null;

    // Creation timestamp
    protected ?DateTime $updated = null;
    // Last update timestamp

    /**
     * Get the message payload
     *
     * @return array The message payload or empty array if null
     */
    public function getPayload(): array
    {
        return $this->payload ?? [];
    }//end getPayload()

    /**
     * Get the last response from consumer
     *
     * @return array The last response or empty array if null
     */
    public function getLastResponse(): array
    {
        return $this->lastResponse ?? [];
    }//end getLastResponse()

    /**
     * Constructor to set up data types for properties
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('eventId', 'integer');
        $this->addType('consumerId', 'integer');
        $this->addType('subscriptionId', 'integer');
        $this->addType('status', 'string');
        $this->addType('payload', 'json');
        $this->addType('lastResponse', 'json');
        $this->addType('retryCount', 'integer');
        $this->addType('lastAttempt', 'datetime');
        $this->addType('nextAttempt', 'datetime');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');
    }//end __construct()

    /**
     * Get fields that should be JSON encoded
     *
     * @return array<string> List of field names that are JSON type
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
                    $this->getFieldTypes(),
                    function ($field) {
                        return $field === 'json';
                    }
                    )
        );
    }//end getJsonFields()

    /**
     * Hydrate the entity from an array of data
     *
     * @param  array<string,mixed> $object Data to hydrate from
     * @return self Returns the hydrated entity
     */
    public function hydrate(array $object): self
    {
        $jsonFields = $this->getJsonFields();

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields) === true && $value === []) {
                $value = [];
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Silent fail if property doesn't exist
            }
        }

        return $this;
    }//end hydrate()

    /**
     * Increment the retry count and update attempt timestamps
     *
     * @param  int $backoffMinutes Minutes to wait before next attempt
     * @return void
     */
    public function incrementRetry(int $backoffMinutes=5): void
    {
        $this->setRetryCount($this->getRetryCount() + 1);
        $this->setLastAttempt(new DateTime());
        $this->setNextAttempt((new DateTime())->modify("+{$backoffMinutes} minutes"));
    }//end incrementRetry()

    /**
     * Serialize the entity to JSON
     *
     * @return array<string,mixed> JSON serializable array
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'eventId'        => $this->eventId,
            'consumerId'     => $this->consumerId,
            'subscriptionId' => $this->subscriptionId,
            'status'         => $this->status,
            'payload'        => $this->payload,
            'lastResponse'   => $this->lastResponse,
            'retryCount'     => $this->retryCount,
            'lastAttempt'    => isset($this->lastAttempt) ? $this->lastAttempt->format('c') : null,
            'nextAttempt'    => isset($this->nextAttempt) ? $this->nextAttempt->format('c') : null,
            'created'        => isset($this->created) ? $this->created->format('c') : null,
            'updated'        => isset($this->updated) ? $this->updated->format('c') : null,
        ];
    }//end jsonSerialize()
}//end class
