<?php
/**
 * OpenConnector SynchronizationContract value object.
 *
 * Post OpenRegister-cutover, synchronization contracts are persisted as
 * OpenRegister objects (register `openconnector`, schema
 * `synchronization_contract`) and fetched via
 * `\OCA\OpenRegister\Service\ObjectService`. The legacy
 * `openconnector_synchronization_contracts` table + its QBMapper were removed in
 * the cutover, but the engine (`SynchronizationService`) consumes a strongly
 * typed contract (`->getOriginId()`, `->getTargetId()`, ...).
 *
 * This class is therefore a thin, mapper-free hydratable value object:
 * `SynchronizationContractMapper` (an OpenRegister-backed adapter) hydrates it
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
 * This class is used to define a contract for a synchronization. Or in other words, a contract between a source and target object.
 *
 * @package OCA\OpenConnector\Db
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class SynchronizationContract extends Entity implements JsonSerializable
{

    /**
     * Legacy source id mirror (kept for migration compatibility).
     *
     * @var string|null
     */
    protected ?string $sourceId = null;

    /**
     * Legacy source hash mirror (kept for migration compatibility).
     *
     * @var string|null
     */
    protected ?string $sourceHash = null;

    protected ?string $uuid = null;

    protected ?string $version = null;

    protected ?string $synchronizationId = null;

    protected ?string $originId = null;

    protected ?string $originHash = null;

    protected ?DateTime $sourceLastChanged = null;

    protected ?DateTime $sourceLastChecked = null;

    protected ?DateTime $sourceLastSynced = null;

    protected ?string $targetId = null;

    protected ?string $targetHash = null;

    protected ?DateTime $targetLastChanged = null;

    protected ?DateTime $targetLastChecked = null;

    protected ?DateTime $targetLastSynced = null;

    protected ?string $targetLastAction = null;

    protected ?DateTime $created = null;

    protected ?DateTime $updated = null;

    /**
     * Constructor: declare field types so getJsonFields()/hydrate() behave.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('version', 'string');
        $this->addType('synchronizationId', 'string');
        $this->addType('originId', 'string');
        $this->addType('originHash', 'string');
        $this->addType('sourceLastChanged', 'datetime');
        $this->addType('sourceLastChecked', 'datetime');
        $this->addType('sourceLastSynced', 'datetime');
        $this->addType('targetId', 'string');
        $this->addType('targetHash', 'string');
        $this->addType('targetLastChanged', 'datetime');
        $this->addType('targetLastChecked', 'datetime');
        $this->addType('targetLastSynced', 'datetime');
        $this->addType('targetLastAction', 'string');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');
        $this->addType('sourceId', 'string');
        $this->addType('sourceHash', 'string');
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

        return $this;
    }//end hydrate()

    /**
     * Serialise the synchronization contract to its array form.
     *
     * @return array The serialised contract.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->id,
            'uuid'              => $this->uuid,
            'version'           => $this->version,
            'synchronizationId' => $this->synchronizationId,
            'originId'          => $this->originId,
            'originHash'        => $this->originHash,
            'sourceLastChanged' => $this->formatDate(date: $this->sourceLastChanged),
            'sourceLastChecked' => $this->formatDate(date: $this->sourceLastChecked),
            'sourceLastSynced'  => $this->formatDate(date: $this->sourceLastSynced),
            'targetId'          => $this->targetId,
            'targetHash'        => $this->targetHash,
            'targetLastChanged' => $this->formatDate(date: $this->targetLastChanged),
            'targetLastChecked' => $this->formatDate(date: $this->targetLastChecked),
            'targetLastSynced'  => $this->formatDate(date: $this->targetLastSynced),
            'targetLastAction'  => $this->targetLastAction,
            'created'           => $this->formatDate(date: $this->created),
            'updated'           => $this->formatDate(date: $this->updated),
            'sourceId'          => $this->sourceId,
            'sourceHash'        => $this->sourceHash,
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
