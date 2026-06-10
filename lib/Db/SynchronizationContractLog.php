<?php
/**
 * OpenConnector SynchronizationContractLog value object.
 *
 * Post OpenRegister-cutover, synchronization contract logs are persisted as
 * OpenRegister objects (register `openconnector`, schema
 * `synchronization_contract_log`) and fetched via
 * `\OCA\OpenRegister\Service\ObjectService`. The legacy
 * `openconnector_synchronization_contract_logs` table + its QBMapper were removed
 * in the cutover. The OpenRegister `synchronization_contract_log` schema is
 * append-only (write-once): OR rejects any UPDATE/DELETE on it, so the engine
 * writes each contract log exactly once.
 *
 * This class is therefore a thin, mapper-free hydratable value object:
 * `SynchronizationContractLogMapper` (an OpenRegister-backed adapter) hydrates it
 * from an OpenRegister `ObjectEntity` at the boundary and serialises it back
 * through `ObjectService::saveObject()`. It is NOT registered as a QBMapper
 * entity and owns no database table.
 *
 * @category Db
 * @package  OCA\OpenConnector\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class SynchronizationContractLog
 *
 * Value object representing a synchronization contract log entry.
 *
 * @package OCA\OpenConnector\Db
 *
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

    protected ?string $targetResult = null;

    protected ?string $userId = null;

    protected ?string $sessionId = null;

    protected ?bool $test = false;

    protected ?bool $force = false;

    protected ?DateTime $expires = null;

    protected ?DateTime $created = null;

    /**
     * Size of this log entry in bytes (calculated from serialized object).
     *
     * @var int
     */
    protected int $size = 4096;

    /**
     * Get the source data.
     *
     * @return array The source data or empty array.
     */
    public function getSource(): ?array
    {
        return $this->source;
    }//end getSource()

    /**
     * Get the target data.
     *
     * @return array The target data or empty array.
     */
    public function getTarget(): ?array
    {
        return $this->target;
    }//end getTarget()

    /**
     * SynchronizationContractLog constructor.
     *
     * Initializes field types and sets default values for expires and size properties.
     *
     * @psalm-api
     * @phpstan-api
     */
    public function __construct()
    {
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

        // Set default expires to next week.
        if ($this->expires === null) {
            $this->expires = new DateTime('+1 week');
        }

        // Calculate and set object size.
        $this->calculateSize();
    }//end __construct()

    /**
     * Get fields that should be JSON encoded.
     *
     * @return array<string> List of field names that are JSON type.
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
     * Hydrate the value object from an associative array.
     *
     * Tolerates the OpenRegister object shape: the OpenRegister id/uuid is mapped
     * onto both `id` and `uuid`, and unknown keys are ignored.
     *
     * @param array $object The data to hydrate from.
     *
     * @return self
     */
    public function hydrate(array $object): self
    {
        $jsonFields = $this->getJsonFields();

        if (isset($object['id']) === true && isset($object['uuid']) === false) {
            $object['uuid'] = $object['id'];
        }

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields, true) === true && $value === null) {
                $value = [];
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                continue;
            }
        }

        // Recalculate size after hydration to ensure it reflects current data.
        $this->calculateSize();

        return $this;
    }//end hydrate()

    /**
     * Calculate and set the size of this log entry.
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function calculateSize(): void
    {
        // Serialize the current object to calculate its size.
        $serialized = json_encode($this->jsonSerialize());
        $this->size = strlen($serialized);

        // Ensure minimum size of 4KB if calculated size is smaller.
        if ($this->size < 4096) {
            $this->size = 4096;
        }
    }//end calculateSize()

    /**
     * Get the size of this log entry in bytes.
     *
     * @return int The size in bytes.
     *
     * @psalm-return   int
     * @phpstan-return int
     */
    public function getSize(): int
    {
        return $this->size;
    }//end getSize()

    /**
     * Set the size of this log entry in bytes.
     *
     * @param int $size The size in bytes.
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

    /**
     * Serialise the contract log to its array form.
     *
     * @return array The serialised contract log.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                        => $this->id,
            'uuid'                      => $this->uuid,
            'message'                   => $this->message,
            'synchronizationId'         => $this->synchronizationId,
            'synchronizationContractId' => $this->synchronizationContractId,
            'synchronizationLogId'      => $this->synchronizationLogId,
            'source'                    => $this->source,
            'target'                    => $this->target,
            'targetResult'              => $this->targetResult,
            'userId'                    => $this->userId,
            'sessionId'                 => $this->sessionId,
            'test'                      => $this->test,
            'force'                     => $this->force,
            'expires'                   => $this->formatDate(date: $this->expires),
            'created'                   => $this->formatDate(date: $this->created),
            'size'                      => $this->size,
        ];
    }//end jsonSerialize()

    /**
     * Format a nullable DateTime as an ISO-8601 string.
     *
     * @param DateTime|null $date The date to format.
     *
     * @return string|null The ISO-8601 string, or null when the date is unset.
     */
    private function formatDate(?DateTime $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->format('c');
    }//end formatDate()
}//end class
