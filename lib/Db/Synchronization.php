<?php
/**
 * OpenConnector Synchronization value object.
 *
 * Post OpenRegister-cutover, synchronizations are persisted as OpenRegister
 * objects (register `openconnector`, schema `synchronization`) and fetched via
 * `\OCA\OpenRegister\Service\ObjectService`. The 15 legacy Db entities + mappers
 * were removed in the cutover, but `SynchronizationService` consumes a strongly
 * typed synchronization (`->getSourceConfig()`, `->getFollowUps()`, ...).
 *
 * This class is therefore re-introduced as a thin, mapper-free hydratable value
 * object: `SynchronizationService` hydrates it from an OpenRegister `ObjectEntity`
 * at the boundary and serialises it back through `ObjectService::saveObject()`.
 * It is NOT registered as a QBMapper entity and owns no database table.
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
use RuntimeException;

/**
 * Class Synchronization
 *
 * Represents a synchronization configuration that defines how to sync data between sources and targets.
 *
 * @package OCA\OpenConnector\Db
 *
 * @method string|null   getUuid()
 * @method void          setUuid(?string $uuid)
 * @method string|null   getName()
 * @method void          setName(?string $name)
 * @method string|null   getDescription()
 * @method void          setDescription(?string $description)
 * @method string|null   getReference()
 * @method void          setReference(?string $reference)
 * @method string|null   getVersion()
 * @method void          setVersion(?string $version)
 * @method string|null   getSourceId()
 * @method void          setSourceId(?string $sourceId)
 * @method string|null   getSourceType()
 * @method void          setSourceType(?string $sourceType)
 * @method string|null   getSourceHash()
 * @method void          setSourceHash(?string $sourceHash)
 * @method string|null   getSourceHashMapping()
 * @method void          setSourceHashMapping(?string $sourceHashMapping)
 * @method string|null   getSourceTargetMapping()
 * @method void          setSourceTargetMapping(?string $sourceTargetMapping)
 * @method void          setSourceConfig(?array $sourceConfig)
 * @method DateTime|null getSourceLastChanged()
 * @method void          setSourceLastChanged(?DateTime $sourceLastChanged)
 * @method DateTime|null getSourceLastChecked()
 * @method void          setSourceLastChecked(?DateTime $sourceLastChecked)
 * @method DateTime|null getSourceLastSynced()
 * @method void          setSourceLastSynced(?DateTime $sourceLastSynced)
 * @method int|null      getCurrentPage()
 * @method void          setCurrentPage(?int $currentPage)
 * @method string|null   getTargetId()
 * @method void          setTargetId(?string $targetId)
 * @method string|null   getTargetType()
 * @method void          setTargetType(?string $targetType)
 * @method string|null   getTargetHash()
 * @method void          setTargetHash(?string $targetHash)
 * @method string|null   getTargetSourceMapping()
 * @method void          setTargetSourceMapping(?string $targetSourceMapping)
 * @method void          setTargetConfig(?array $targetConfig)
 * @method DateTime|null getTargetLastChanged()
 * @method void          setTargetLastChanged(?DateTime $targetLastChanged)
 * @method DateTime|null getTargetLastChecked()
 * @method void          setTargetLastChecked(?DateTime $targetLastChecked)
 * @method DateTime|null getTargetLastSynced()
 * @method void          setTargetLastSynced(?DateTime $targetLastSynced)
 * @method DateTime|null getCreated()
 * @method void          setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void          setUpdated(?DateTime $updated)
 * @method void          setConditions(array $conditions)
 * @method void          setFollowUps(array $followUps)
 * @method void          setActions(array $actions)
 * @method array|null    getConfigurations()
 * @method void          setConfigurations(?array $configurations)
 * @method string|null   getStatus()
 * @method void          setStatus(?string $status)
 * @method void          setSlug(?string $slug)
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class Synchronization extends Entity implements JsonSerializable
{

    /**
     * The unique identifier of the synchronization.
     *
     * @var string|null
     */
    protected ?string $uuid = null;

    /**
     * The name of the synchronization.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * The description of the synchronization.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * The reference of the synchronization.
     *
     * @var string|null
     */
    protected ?string $reference = null;

    /**
     * The version of the synchronization.
     *
     * @var string|null
     */
    protected ?string $version = '0.0.0';

    /**
     * The id of the source object.
     *
     * @var string|null
     */
    protected ?string $sourceId = null;

    /**
     * The type of the source object (e.g. api, database, register/schema).
     *
     * @var string|null
     */
    protected ?string $sourceType = null;

    /**
     * The hash of the source object when it was last synced.
     *
     * @var string|null
     */
    protected ?string $sourceHash = null;

    /**
     * The mapping id used to hash the source object.
     *
     * @var string|null
     */
    protected ?string $sourceHashMapping = null;

    /**
     * The mapping of the source object to the target object.
     *
     * @var string|null
     */
    protected ?string $sourceTargetMapping = null;

    /**
     * The configuration of the object in the source.
     *
     * @var array|null
     */
    protected ?array $sourceConfig = [];

    /**
     * The last changed date of the source object.
     *
     * @var DateTime|null
     */
    protected ?DateTime $sourceLastChanged = null;

    /**
     * The last checked date of the source object.
     *
     * @var DateTime|null
     */
    protected ?DateTime $sourceLastChecked = null;

    /**
     * The last synced date of the source object.
     *
     * @var DateTime|null
     */
    protected ?DateTime $sourceLastSynced = null;

    /**
     * The last page synced (used to resume after a source rate limit).
     *
     * @var integer|null
     */
    protected ?int $currentPage = 1;

    /**
     * The id of the target object.
     *
     * @var string|null
     */
    protected ?string $targetId = null;

    /**
     * The type of the target object (e.g. api, database, register/schema).
     *
     * @var string|null
     */
    protected ?string $targetType = null;

    /**
     * The hash of the target object.
     *
     * @var string|null
     */
    protected ?string $targetHash = null;

    /**
     * The mapping of the target object to the source object.
     *
     * @var string|null
     */
    protected ?string $targetSourceMapping = null;

    /**
     * The configuration of the object in the target.
     *
     * @var array|null
     */
    protected ?array $targetConfig = [];

    /**
     * The last changed date of the target object.
     *
     * @var DateTime|null
     */
    protected ?DateTime $targetLastChanged = null;

    /**
     * The last checked date of the target object.
     *
     * @var DateTime|null
     */
    protected ?DateTime $targetLastChecked = null;

    /**
     * The last synced date of the target object.
     *
     * @var DateTime|null
     */
    protected ?DateTime $targetLastSynced = null;

    /**
     * The date and time the synchronization was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $created = null;

    /**
     * The date and time the synchronization was updated.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updated = null;

    /**
     * The conditions that gate the synchronization.
     *
     * @var array
     */
    protected array $conditions = [];

    /**
     * The follow-up synchronizations to run afterwards.
     *
     * @var array
     */
    protected array $followUps = [];

    /**
     * The actions to run as part of the synchronization.
     *
     * @var array
     */
    protected array $actions = [];

    /**
     * Configuration IDs that this synchronization belongs to.
     *
     * @var array|null
     */
    protected ?array $configurations = [];

    /**
     * The status of the synchronization.
     *
     * @var string|null
     */
    protected ?string $status = null;

    /**
     * URL-friendly identifier for the synchronization.
     *
     * @var string|null
     */
    protected ?string $slug = null;

    /**
     * Get the source configuration array.
     *
     * @return array The source configuration or empty array if null.
     */
    public function getSourceConfig(): array
    {
        return $this->sourceConfig ?? [];
    }//end getSourceConfig()

    /**
     * Get the target configuration array.
     *
     * @return array The target configuration or empty array if null.
     */
    public function getTargetConfig(): array
    {
        return $this->targetConfig ?? [];
    }//end getTargetConfig()

    /**
     * Get the conditions array.
     *
     * @return array The conditions or empty array if null.
     */
    public function getConditions(): array
    {
        return $this->conditions ?? [];
    }//end getConditions()

    /**
     * Get the follow-ups array.
     *
     * @return array The follow-ups or empty array if null.
     */
    public function getFollowUps(): array
    {
        return $this->followUps ?? [];
    }//end getFollowUps()

    /**
     * Get the actions array.
     *
     * @return array The actions or empty array if null.
     */
    public function getActions(): array
    {
        return $this->actions ?? [];
    }//end getActions()

    /**
     * Constructor registers field types for hydration.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'reference', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'sourceId', type: 'string');
        $this->addType(fieldName: 'sourceType', type: 'string');
        $this->addType(fieldName: 'sourceHash', type: 'string');
        $this->addType(fieldName: 'sourceHashMapping', type: 'string');
        $this->addType(fieldName: 'sourceTargetMapping', type: 'string');
        $this->addType(fieldName: 'sourceConfig', type: 'json');
        $this->addType(fieldName: 'sourceLastChanged', type: 'datetime');
        $this->addType(fieldName: 'sourceLastChecked', type: 'datetime');
        $this->addType(fieldName: 'sourceLastSynced', type: 'datetime');
        $this->addType(fieldName: 'currentPage', type: 'integer');
        $this->addType(fieldName: 'targetId', type: 'string');
        $this->addType(fieldName: 'targetType', type: 'string');
        $this->addType(fieldName: 'targetHash', type: 'string');
        $this->addType(fieldName: 'targetSourceMapping', type: 'string');
        $this->addType(fieldName: 'targetConfig', type: 'json');
        $this->addType(fieldName: 'targetLastChanged', type: 'datetime');
        $this->addType(fieldName: 'targetLastChecked', type: 'datetime');
        $this->addType(fieldName: 'targetLastSynced', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'conditions', type: 'json');
        $this->addType(fieldName: 'followUps', type: 'json');
        $this->addType(fieldName: 'actions', type: 'json');
        $this->addType(fieldName: 'configurations', type: 'json');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'slug', type: 'string');
    }//end __construct()

    /**
     * Checks through sourceConfig if the source of this sync uses pagination.
     *
     * @return bool True if it uses pagination.
     */
    public function usesPagination(): bool
    {
        $usesPagination = ($this->sourceConfig['usesPagination'] ?? null);
        if ($usesPagination === false || $usesPagination === 'false') {
            return false;
        }

        // By default sources use basic pagination.
        return true;
    }//end usesPagination()

    /**
     * Get the field names that are JSON-typed.
     *
     * @return array The list of JSON field names.
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
     * Get the slug for the synchronization.
     *
     * If the slug is not set, generate one from the name.
     *
     * @return         string The slug for the synchronization.
     * @phpstan-return non-empty-string
     * @psalm-return   non-empty-string
     */
    public function getSlug(): string
    {
        // Check if the slug is already set.
        if (empty($this->slug) === false) {
            return $this->slug;
        }

        // Generate a slug from the name if not set.
        $generatedSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string) $this->name)));

        // Ensure the generated slug is not empty.
        if (empty($generatedSlug) === true) {
            throw new RuntimeException('Unable to generate a valid slug from the name.');
        }

        return $generatedSlug;
    }//end getSlug()

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

        // OpenRegister exposes the canonical identifier under `id` (a UUID string)
        // and may repeat it under `uuid`; keep both populated for the engine.
        if (isset($object['id']) === true && isset($object['uuid']) === false) {
            $object['uuid'] = $object['id'];
        }

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields, true) === true && $value === []) {
                $value = [];
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Unknown / non-settable keys from the OpenRegister object are ignored.
                continue;
            }
        }

        return $this;
    }//end hydrate()

    /**
     * Serialise the synchronization to its array form.
     *
     * @return array The serialised synchronization.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                  => $this->id,
            'uuid'                => $this->uuid,
            'name'                => $this->name,
            'description'         => $this->description,
            'reference'           => $this->reference,
            'version'             => $this->version,
            'sourceId'            => $this->sourceId,
            'sourceType'          => $this->sourceType,
            'sourceHash'          => $this->sourceHash,
            'sourceHashMapping'   => $this->sourceHashMapping,
            'sourceTargetMapping' => $this->sourceTargetMapping,
            'sourceConfig'        => $this->sourceConfig,
            'sourceLastChanged'   => $this->formatDate(date: $this->sourceLastChanged),
            'sourceLastChecked'   => $this->formatDate(date: $this->sourceLastChecked),
            'sourceLastSynced'    => $this->formatDate(date: $this->sourceLastSynced),
            'currentPage'         => $this->currentPage,
            'targetId'            => $this->targetId,
            'targetType'          => $this->targetType,
            'targetHash'          => $this->targetHash,
            'targetSourceMapping' => $this->targetSourceMapping,
            'targetConfig'        => $this->targetConfig,
            'targetLastChanged'   => $this->formatDate(date: $this->targetLastChanged),
            'targetLastChecked'   => $this->formatDate(date: $this->targetLastChecked),
            'targetLastSynced'    => $this->formatDate(date: $this->targetLastSynced),
            'created'             => $this->formatDate(date: $this->created),
            'updated'             => $this->formatDate(date: $this->updated),
            'conditions'          => $this->conditions,
            'followUps'           => $this->followUps,
            'actions'             => $this->actions,
            'configurations'      => $this->configurations,
            'status'              => $this->status,
            'slug'                => $this->getSlug(),
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
