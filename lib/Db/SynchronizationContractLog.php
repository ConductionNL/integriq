<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class SynchronizationContractLog
 * 
 * Entity class representing a synchronization contract log entry
 */
/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class SynchronizationContractLog extends Entity implements JsonSerializable
{
    protected ?string $uuid = null;
	protected ?string $message = null;
    protected ?string $synchronizationId = null;
    protected ?string $synchronizationContractId = null;
    protected ?string $synchronizationLogId = null;
    protected ?array $source = [];
    protected ?array $target = [];
    protected ?string $targetResult = null; // CRUD action taken on target (create/read/update/delete)
    protected ?string $userId = null;
    protected ?string $sessionId = null;
    protected ?bool $test = false;
    protected ?bool $force = false;
    protected ?DateTime $expires = null;
    protected ?DateTime $created = null;
    
    /** @var int $size Size of this log entry in bytes (calculated from serialized object) */
    protected int $size = 4096;

    /**
     * Get the source data
     *
     * @return array The source data or null
     */
    public function getSource(): ?array
    {
        return $this->source;
    }

    /**
     * Get the target data
     *
     * @return array The target data or null
     */
    public function getTarget(): ?array
    {
        return $this->target;
    }

    /**
     * SynchronizationContractLog constructor
     *
     * Initializes field types and sets default values for expires and size properties.
     * The expires date is set to one week from creation, and size defaults to 4KB.
     *
     * @psalm-api
     * @phpstan-api
     */
    public function __construct() {
        $this->addType('uuid', 'string');
        $this->addType('message', 'string');
        $this->addType('synchronizationId', 'string');
        $this->addType('synchronizationContractId', 'string');
        $this->addType('synchronizationLogId', 'string');
        $this->addType('source', 'json');
        $this->addType('target', 'json');
        $this->addType('targetResult', 'string');
        $this->addType('userId', 'string');
        $this->addType('sessionId', 'string');
        $this->addType('test', 'boolean');
        $this->addType('force', 'boolean');
        $this->addType('expires', 'datetime');
        $this->addType('created', 'datetime');
        $this->addType('size', 'integer');

        // Set default expires to next week
        if ($this->expires === null) {
            $this->expires = new DateTime('+1 week');
        }
        
        // Calculate and set object size
        $this->calculateSize();
    }

    public function getJsonFields(): array
    {
        return array_keys(
            array_filter($this->getFieldTypes(), function ($field) {
                return $field === 'json';
            })
        );
    }

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
    }

    /**
     * Calculate and set the size of this log entry
     *
     * This method calculates the size of the log entry by serializing the object
     * and measuring its byte size. This helps with storage management and cleanup.
     *
     * @return void
     *
     * @psalm-return void
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
    }

    /**
     * Get the size of this log entry in bytes
     *
     * @return int The size in bytes
     *
     * @psalm-return int
     * @phpstan-return int
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * Set the size of this log entry in bytes
     *
     * @param int $size The size in bytes
     *
     * @return void
     *
     * @psalm-param int $size
     * @psalm-return void
     * @phpstan-param int $size
     * @phpstan-return void
     */
    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'message' => $this->message,
            'synchronizationId' => $this->synchronizationId,
            'synchronizationContractId' => $this->synchronizationContractId,
            'synchronizationLogId' => $this->synchronizationLogId,
            'source' => $this->source,
            'target' => $this->target,
            'targetResult' => $this->targetResult,
            'userId' => $this->userId,
            'sessionId' => $this->sessionId,
            'test' => $this->test,
            'force' => $this->force,
            'expires' => isset($this->expires) ? $this->expires->format('c') : null,
            'created' => isset($this->created) ? $this->created->format('c') : null,
            'size' => $this->size,
        ];
    }
}
