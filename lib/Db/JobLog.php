<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class JobLog extends Entity implements JsonSerializable
{
    protected ?string $uuid = null;
    protected string $level = 'INFO';
    protected string $message = 'success';
    protected ?string $jobId = null;
    protected ?string $jobListId = null;
    protected ?string $jobClass = 'OCA\OpenConnector\Action\PingAction';
    protected ?array $arguments = [];
    protected ?int $executionTime = 3600;
    protected ?string $userId = null;
    protected ?string $sessionId = null;
    protected ?array $stackTrace = [];
    protected ?DateTime $expires = null;
    protected ?DateTime $lastRun = null;
    protected ?DateTime $nextRun = null;
    protected ?DateTime $created = null;
    
    /** @var int $size Size of this log entry in bytes (calculated from serialized object) */
    protected int $size = 4096;

    /**
     * Get the job arguments
     *
     * @return array The job arguments or empty array if null
     */
    public function getArguments(): array
    {
        return $this->arguments ?? [];
    }

    /**
     * Get the stack trace
     *
     * @return array The stack trace or empty array if null
     */
    public function getStackTrace(): array
    {
        return $this->stackTrace ?? [];
    }

    /**
     * JobLog constructor
     *
     * Initializes field types and sets default values for expires and size properties.
     * The expires date is set to one week from creation, and size defaults to 4KB.
     *
     * @psalm-api
     * @phpstan-api
     */
    public function __construct() {
        $this->addType('uuid', 'string');
        $this->addType('level', 'string');
        $this->addType('message', 'string');
        $this->addType('jobId', 'string');
        $this->addType('jobListId', 'string');
        $this->addType('jobClass', 'string');
        $this->addType('arguments', 'json');
        $this->addType('executionTime', 'integer');
        $this->addType('userId', 'string');
        $this->addType('sessionId', 'string');
        $this->addType('stackTrace', 'json');
        $this->addType('expires', 'datetime');
        $this->addType('lastRun', 'datetime');
        $this->addType('nextRun', 'datetime');
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
            'level' => $this->level,
            'message' => $this->message,
            'jobId' => $this->jobId,
            'jobListId' => $this->jobListId,
            'jobClass' => $this->jobClass,
            'arguments' => $this->arguments,
            'executionTime' => $this->executionTime,
            'userId' => $this->userId,
            'sessionId' => $this->sessionId,
            'stackTrace' => $this->stackTrace,
            'expires' => isset($this->expires) ? $this->expires->format('c') : null,
            'lastRun' => isset($this->lastRun) ? $this->lastRun->format('c') : null,
            'nextRun' => isset($this->nextRun) ? $this->nextRun->format('c') : null,
            'created' => isset($this->created) ? $this->created->format('c') : null,
            'size' => $this->size,
        ];
    }
}
