<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class CallLog extends Entity implements JsonSerializable
{

    /**
     * @var string|null $uuid Unique identifier for this call log entry
     */
    protected ?string $uuid = null;

    /**
     * @var integer|null $statusCode HTTP status code returned from the API call
     */
    protected ?int $statusCode = null;

    /**
     * @var string|null $statusMessage Status message or description returned with the response
     */
    protected ?string $statusMessage = null;

    /**
     * @var array|null $request Complete request data including headers, method, body, etc.
     */
    protected ?array $request = null;

    /**
     * @var array|null $response Complete response data including headers, body, and status info
     */
    protected ?array $response = null;

    /**
     * @var integer|null $sourceId Reference to the source/endpoint that was called
     */
    protected ?int $sourceId = null;

    /**
     * @var integer|null $actionId Reference to the action that triggered this call
     */
    protected ?int $actionId = null;

    /**
     * @var integer|null $synchronizationId Reference to the synchronization process if this call is part of one
     */
    protected ?int $synchronizationId = null;

    /**
     * @var string|null $userId Identifier of the user who initiated the call
     */
    protected ?string $userId = null;

    /**
     * @var string|null $sessionId Session identifier associated with this call
     */
    protected ?string $sessionId = null;

    /**
     * @var DateTime|null $expires When this log entry should expire/be deleted
     */
    protected ?DateTime $expires = null;

    /**
     * @var DateTime|null $created When this log entry was created
     */
    protected ?DateTime $created = null;

    /**
     * @var integer $size Size of this log entry in bytes (calculated from serialized object)
     */
    protected int $size = 4096;

    /**
     * Get the request data
     *
     * @return array The request data or null
     */
    public function getRequest(): ?array
    {
        return $this->request;
    }//end getRequest()

    /**
     * Get the response data
     *
     * @return array The response data or null
     */
    public function getResponse(): ?array
    {
        return $this->response;
    }//end getResponse()

    /**
     * CallLog constructor
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
        $this->addType('statusCode', 'integer');
        $this->addType('statusMessage', 'string');
        $this->addType('request', 'json');
        $this->addType('response', 'json');
        $this->addType('sourceId', 'integer');
        $this->addType('actionId', 'integer');
        $this->addType('synchronizationId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('sessionId', 'string');
        $this->addType('expires', 'datetime');
        $this->addType('created', 'datetime');
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
            'statusCode'        => $this->statusCode,
            'statusMessage'     => $this->statusMessage,
            'request'           => $this->request,
            'response'          => $this->response,
            'sourceId'          => $this->sourceId,
            'actionId'          => $this->actionId,
            'synchronizationId' => $this->synchronizationId,
            'userId'            => $this->userId,
            'sessionId'         => $this->sessionId,
            'expires'           => isset($this->expires) ? $this->expires->format('c') : null,
            'created'           => isset($this->created) ? $this->created->format('c') : null,
            'size'              => $this->size,
        ];
    }//end jsonSerialize()
}//end class
