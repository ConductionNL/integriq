<?php
/**
 * OpenConnector Synchronization Run Log
 *
 * In-memory value object for a single synchronization run-log entry.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service;

use DateTime;

/**
 * In-memory value object for a single synchronization run-log entry.
 *
 * Post OpenRegister-cutover the `SynchronizationLog` entity + its QBMapper were
 * deleted; the run-log now lives in OpenRegister (register `openconnector`,
 * schema `synchronization_log`). This plain value object is the in-memory handle
 * the synchronization engine mutates while a run is in progress; it is persisted
 * to OpenRegister through SynchronizationLogService. It deliberately lives in the
 * Service namespace (not Db) so it is not a legacy Db type.
 */
class SynchronizationRunLog implements \JsonSerializable
{

    /**
     * The OpenRegister id (UUID string) of the persisted log object.
     *
     * @var string|null
     */
    private ?string $id = null;

    /**
     * The canonical UUID of the log.
     *
     * @var string|null
     */
    private ?string $uuid = null;

    /**
     * Free-form log message ("Success" or an error description).
     *
     * @var string|null
     */
    private ?string $message = null;

    /**
     * The UUID of the synchronization this log belongs to.
     *
     * @var string|null
     */
    private ?string $synchronizationId = null;

    /**
     * The structured result summary (counts, timing, contracts, ...).
     *
     * @var array
     */
    private array $result = [];

    /**
     * The Nextcloud user id that triggered the run (null for cron).
     *
     * @var string|null
     */
    private ?string $userId = null;

    /**
     * The session token for multi-event correlation.
     *
     * @var string|null
     */
    private ?string $sessionId = null;

    /**
     * Whether this entry is from a dry-run/test execution.
     *
     * @var boolean
     */
    private bool $test = false;

    /**
     * Whether this entry is from a forced (skip-hash) execution.
     *
     * @var boolean
     */
    private bool $force = false;

    /**
     * The execution duration in milliseconds.
     *
     * @var integer
     */
    private int $executionTime = 0;

    /**
     * The creation timestamp.
     *
     * @var DateTime|null
     */
    private ?DateTime $created = null;

    /**
     * The computed retention horizon.
     *
     * @var DateTime|null
     */
    private ?DateTime $expires = null;

    /**
     * Approximate row size for storage accounting.
     *
     * @var integer
     */
    private int $size = 4096;

    /**
     * Whether this log has already been persisted to OpenRegister.
     *
     * The `synchronization_log` schema is append-only, so the row is written
     * exactly once; this guards against a second (rejected) write.
     *
     * @var boolean
     */
    private bool $persisted = false;

    /**
     * Hydrate the value object from an associative array (OpenRegister shape).
     *
     * The OpenRegister object exposes its canonical identifier under `id` (a UUID
     * string); it is mapped onto both `id` and `uuid` when `uuid` is absent.
     *
     * @param array $object The data to hydrate from.
     *
     * @return self
     */
    public function hydrate(array $object): self
    {
        // OpenRegister exposes the canonical identifier under `id`; mirror it
        // onto `uuid` when only `id` is present.
        if (isset($object['id']) === true && isset($object['uuid']) === false) {
            $object['uuid'] = $object['id'];
        }

        $this->id     = $this->stringOrNull(value: ($object['id'] ?? null));
        $this->uuid   = $this->stringOrNull(value: ($object['uuid'] ?? null));
        $this->userId = $this->stringOrNull(value: ($object['userId'] ?? null));

        $this->message           = ($object['message'] ?? $this->message);
        $this->synchronizationId = ($object['synchronizationId'] ?? $this->synchronizationId);
        $this->sessionId         = ($object['sessionId'] ?? $this->sessionId);

        if (array_key_exists('result', $object) === true && is_array($object['result']) === true) {
            $this->result = $object['result'];
        }

        $this->test  = (bool) ($object['test'] ?? $this->test);
        $this->force = (bool) ($object['force'] ?? $this->force);

        $this->executionTime = (int) ($object['executionTime'] ?? $this->executionTime);
        $this->size          = (int) ($object['size'] ?? $this->size);

        $this->created = $this->toDateTime(value: ($object['created'] ?? $this->created));
        $this->expires = $this->toDateTime(value: ($object['expires'] ?? $this->expires));

        return $this;

    }//end hydrate()

    /**
     * Coerce a value to a non-empty string, or null.
     *
     * @param mixed $value The value to coerce.
     *
     * @return string|null The string value, or null when empty/absent.
     */
    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;

    }//end stringOrNull()

    /**
     * Normalise a value into a DateTime (accepts DateTime or string).
     *
     * @param mixed $value The value to normalise.
     *
     * @return DateTime|null The DateTime, or null when it cannot be parsed.
     */
    private function toDateTime(mixed $value): ?DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        if (is_string($value) === true && $value !== '') {
            try {
                return new DateTime($value);
            } catch (\Exception $exception) {
                return null;
            }
        }

        return null;

    }//end toDateTime()

    /**
     * Get the OpenRegister id (UUID string) of the log.
     *
     * @return string|null The OpenRegister id.
     */
    public function getId(): ?string
    {
        return $this->id;

    }//end getId()

    /**
     * Get the canonical UUID of the log.
     *
     * @return string|null The UUID.
     */
    public function getUuid(): ?string
    {
        return $this->uuid;

    }//end getUuid()

    /**
     * Get the structured result summary.
     *
     * @return array The result summary.
     */
    public function getResult(): array
    {
        return $this->result;

    }//end getResult()

    /**
     * Set the structured result summary.
     *
     * @param array $result The result summary.
     *
     * @return void
     */
    public function setResult(array $result): void
    {
        $this->result = $result;

    }//end setResult()

    /**
     * Get the log message.
     *
     * @return string|null The message.
     */
    public function getMessage(): ?string
    {
        return $this->message;

    }//end getMessage()

    /**
     * Set the log message.
     *
     * @param string|null $message The message.
     *
     * @return void
     */
    public function setMessage(?string $message): void
    {
        $this->message = $message;

    }//end setMessage()

    /**
     * Set the execution time in milliseconds.
     *
     * @param integer $executionTime The execution time.
     *
     * @return void
     */
    public function setExecutionTime(int $executionTime): void
    {
        $this->executionTime = $executionTime;

    }//end setExecutionTime()

    /**
     * Set the retention horizon.
     *
     * @param DateTime|null $expires The expiry timestamp.
     *
     * @return void
     */
    public function setExpires(?DateTime $expires): void
    {
        $this->expires = $expires;

    }//end setExpires()

    /**
     * Get the approximate row size.
     *
     * @return integer The row size.
     */
    public function getSize(): int
    {
        return $this->size;

    }//end getSize()

    /**
     * Whether this log has already been persisted to OpenRegister.
     *
     * @return boolean True when the (append-only) row has been written.
     */
    public function isPersisted(): bool
    {
        return $this->persisted;

    }//end isPersisted()

    /**
     * Mark this log as persisted and adopt the stored object's identifiers.
     *
     * @param array $saved The OpenRegister object (jsonSerialize) of the stored row.
     *
     * @return void
     */
    public function markPersisted(array $saved): void
    {
        $this->persisted = true;

        if (isset($saved['id']) === true) {
            $this->id = (string) $saved['id'];
        }

        if (isset($saved['uuid']) === true) {
            $this->uuid = (string) $saved['uuid'];
        }

    }//end markPersisted()

    /**
     * Serialise the log to its array (OpenRegister object) form.
     *
     * @return array The serialised log.
     */
    public function jsonSerialize(): array
    {
        $created = null;
        if (isset($this->created) === true) {
            $created = $this->created->format('c');
        }

        $expires = null;
        if (isset($this->expires) === true) {
            $expires = $this->expires->format('c');
        }

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
            'created'           => $created,
            'expires'           => $expires,
            'size'              => $this->size,
        ];

    }//end jsonSerialize()
}//end class
