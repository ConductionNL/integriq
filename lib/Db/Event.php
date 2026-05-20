<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity class representing a CloudEvent
 *
 * This class implements the CloudEvents specification (https://cloudevents.io/)
 * for events generated when objects are updated in open registers.
 */
class Event extends Entity implements JsonSerializable
{

    // Required CloudEvent attributes
    protected ?string $uuid = null; // Unique UUID identifier for the event

    protected ?string $source = null; // URI identifying the context where event happened

    protected ?string $type = null; // Event type identifier

    protected ?string $specversion = '1.0'; // CloudEvents specification version

    protected ?DateTime $time = null; // Timestamp of when the event occurred

    // Optional CloudEvent attributes
    protected ?string $datacontenttype = 'application/json'; // Content type of data

    protected ?string $dataschema = null; // URI to the schema that data adheres to

    protected ?string $subject = null; // Subject of the event

    protected ?array $data = []; // Event payload

    // Additional tracking fields
    protected ?string $userId = null; // User who triggered the event

    protected ?DateTime $created = null; // When the event was created in our system

    protected ?DateTime $updated = null; // When the event was last updated

    protected ?DateTime $processed = null; // When the event was processed

    protected ?string $status = 'pending'; // Event processing status

    /**
     * Get the event data payload
     *
     * @return array The event data or empty array if null
     */
    public function getData(): array
    {
        return $this->data ?? [];
    }//end getData()

    /**
     * Constructor to set up data types for properties
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('source', 'string');
        $this->addType('type', 'string');
        $this->addType('specversion', 'string');
        $this->addType('time', 'datetime');
        $this->addType('datacontenttype', 'string');
        $this->addType('dataschema', 'string');
        $this->addType('subject', 'string');
        $this->addType('data', 'json');
        $this->addType('userId', 'string');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');
        $this->addType('processed', 'datetime');
        $this->addType('status', 'string');
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
     * Serialize the entity to JSON
     *
     * @return array<string,mixed> JSON serializable array
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->id,
            'uuid'            => $this->uuid,
            'source'          => $this->source,
            'type'            => $this->type,
            'specversion'     => $this->specversion,
            'time'            => isset($this->time) ? $this->time->format('c') : null,
            'datacontenttype' => $this->datacontenttype,
            'dataschema'      => $this->dataschema,
            'subject'         => $this->subject,
            'data'            => $this->data,
            'userId'          => $this->userId,
            'created'         => isset($this->created) ? $this->created->format('c') : null,
            'updated'         => isset($this->updated) ? $this->updated->format('c') : null,
            'processed'       => isset($this->processed) ? $this->processed->format('c') : null,
            'status'          => $this->status,
        ];
    }//end jsonSerialize()
}//end class
