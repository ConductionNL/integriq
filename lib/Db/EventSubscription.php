<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class EventSubscription
 *
 * Represents a subscription to events in the system, following the CloudEvents specification.
 * Supports both push and pull delivery styles and configurable filtering logic.
 *
 * @package OCA\OpenConnector\Db
 *
 * @property string|null $uuid Unique identifier for the subscription
 * @property string|null $source URI identifying the context where events originate
 * @property array|null $types Array of CloudEvent type values to subscribe to
 * @property array|null $config Subscription manager specific configuration
 * @property array|null $filters Array of filter expressions for event matching
 * @property string|null $sink URI where events should be delivered
 * @property string|null $protocol Delivery protocol (HTTP, MQTT, AMQP, etc.)
 * @property array|null $protocolSettings Protocol-specific settings
 * @property string|null $style Delivery style ('push' or 'pull')
 * @property string|null $status Subscription status
 * @property string|null $userId Owner of the subscription
 * @property DateTime|null $created Creation timestamp
 * @property DateTime|null $updated Last update timestamp
 */
class EventSubscription extends Entity implements JsonSerializable
{

    protected ?string $uuid = null;

    protected ?string $reference = null;

    protected ?string $version = '0.0.0';

    protected ?string $source = null;

    protected ?array $types = [];

    protected ?array $config = [];

    protected ?array $filters = [];

    protected ?string $sink = null;

    protected ?string $protocol = null;

    protected ?array $protocolSettings = [];

    protected ?string $style = 'push';

    protected ?string $status = 'active';

    protected ?string $userId = null;

    protected ?DateTime $created = null;

    protected ?DateTime $updated = null;

    /**
     * Get the event types to subscribe to
     *
     * @return array The event types or empty array if null
     */
    public function getTypes(): array
    {
        return $this->types ?? [];
    }//end getTypes()

    /**
     * Get the subscription configuration
     *
     * @return array The configuration or empty array if null
     */
    public function getConfig(): array
    {
        return $this->config ?? [];
    }//end getConfig()

    /**
     * Get the subscription filters
     *
     * @return array The filters or empty array if null
     */
    public function getFilters(): array
    {
        return $this->filters ?? [];
    }//end getFilters()

    /**
     * Get the protocol settings
     *
     * @return array The protocol settings or empty array if null
     */
    public function getProtocolSettings(): array
    {
        return $this->protocolSettings ?? [];
    }//end getProtocolSettings()

    /**
     * Constructor to set up data types for properties
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType(fieldName:'reference', type: 'string');
        $this->addType(fieldName:'version', type: 'string');
        $this->addType('source', 'string');
        $this->addType('types', 'json');
        $this->addType('config', 'json');
        $this->addType('filters', 'json');
        $this->addType('sink', 'string');
        $this->addType('protocol', 'string');
        $this->addType('protocolSettings', 'json');
        $this->addType('style', 'string');
        $this->addType('status', 'string');
        $this->addType('userId', 'string');
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
     * Serialize the entity to JSON
     *
     * @return array<string,mixed> JSON serializable array
     */
    public function jsonSerialize(): array
    {
        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid,
            'source'           => $this->source,
            'types'            => $this->types,
            'config'           => $this->config,
            'filters'          => $this->filters,
            'sink'             => $this->sink,
            'protocol'         => $this->protocol,
            'protocolSettings' => $this->protocolSettings,
            'style'            => $this->style,
            'status'           => $this->status,
            'userId'           => $this->userId,
            'created'          => isset($this->created) ? $this->created->format('c') : null,
            'updated'          => isset($this->updated) ? $this->updated->format('c') : null,
        ];
    }//end jsonSerialize()
}//end class
