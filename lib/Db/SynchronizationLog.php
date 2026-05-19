<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity class representing a synchronization log entry
 */
class SynchronizationLog extends Entity implements JsonSerializable
{

    protected ?string $uuid = null;

    protected ?string $message = null;

    protected ?string $synchronizationId = null;

    protected ?array $result = [];

    protected ?string $userId = null;

    protected ?string $sessionId = null;

    protected bool $test = false;

    protected bool $force = false;

    protected int $executionTime = 0;

    protected ?DateTime $created = null;

    protected ?DateTime $expires = null;

    /**
     * @var int $size Size of this log entry in bytes (calculated from serialized object)
     */
    protected int $size = 4096;

    /**
     * Get the synchronization result
     *
     * @return array The result data or empty array if null
     */
    public function getResult(): array
    {
        return $this->result ?? [];
    }//end getResult()

    /**
     * SynchronizationLog constructor
     *
     * Initializes field types and sets default values for expires and size properties.
     * The expires date is set to one week from creation, and size defaults to 4KB.
     *
     * @psalm-api
     * @phpstan-api
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('message', 'string');
        $this->addType('synchronizationId', 'string');
        $this->addType('result', 'json');
        $this->addType('userId', 'string');
        $this->addType('sessionId', 'string');
        $this->addType('test', 'boolean');
        $this->addType('force', 'boolean');
        $this->addType('executionTime', 'integer');
        $this->addType('created', 'datetime');
        $this->addType('expires', 'datetime');
        $this->addType('size', 'integer');

        // Set default expires to next week
        if ($this->expires === null) {
            $this->expires = new DateTime('+1 week');
        }

        // Calculate and set object size
        $this->calculateSize();
    }//end __construct()

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
                // Handle or log the exception if needed
            }
        }

        // Recalculate size after hydration to ensure it reflects current data
        $this->calculateSize();

        return $this;
    }//end hydrate()

    /**
     * Calculate and set the size of this log entry
     *
     * This method calculates the size of the log entry by serializing the object
     * and measuring its byte size. This helps with storage management and cleanup.
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function calculateSize(): void
    {
        // Serialize the current object to calculate its size
        $serialized = json_encode($this->jsonSerialize());
        $this->size = strlen($serialized);

        // Ensure minimum size of 4KB if calculated size is smaller
        if ($this->size < 4096) {
            $this->size = 4096;
        }
    }//end calculateSize()

    /**
     * Get the size of this log entry in bytes
     *
     * @return int The size in bytes
     *
     * @psalm-return   int
     * @phpstan-return int
     */
    public function getSize(): int
    {
        return $this->size;
    }//end getSize()

    /**
     * Set the size of this log entry in bytes
     *
     * @param int $size The size in bytes
     *
     * @return void
     *
     * @psalm-param    int $size
     * @psalm-return   void
     * @phpstan-param  int $size
     * @phpstan-return void
     */
    public function setSize(int $size): void
    {
        $this->size = $size;
    }//end setSize()

    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'message'           => $this->message,
            'synchronizationId' => $this->synchronizationId,
            'result'            => $this->result,
            'userId'            => $this->userId,
            'sessionId'         => $this->sessionId,
            'test'              => $this->test,
            'force'             => $this->force,
            'executionTime'     => $this->executionTime,
            'created'           => isset($this->created) ? $this->created->format('c') : null,
            'expires'           => isset($this->expires) ? $this->expires->format('c') : null,
            'size'              => $this->size,
        ];
    }//end jsonSerialize()
}//end class
