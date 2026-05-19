<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use RuntimeException;

/**
 * Class Rule
 *
 * Represents a rule that can be triggered during endpoint handling
 *
 * @package OCA\OpenConnector\Db
 */
class Rule extends Entity implements JsonSerializable
{
    protected ?string $uuid = null;
    protected ?string $name = null;
    protected ?string $description = null;
	protected ?string $reference = null;
	protected ?string $version = '0.0.0';
    protected ?string $action = null; // create, read, update, delete
    protected ?string $timing = 'before'; // before or after
    protected ?array $conditions = []; // JSON Logic format conditions
    protected ?string $type = null; // mapping, error, script, synchronization
    protected ?array $configuration = []; // Type-specific configuration
    protected int $order = 0; // Order in which the rule should be applied
    protected ?array $configurations = []; // Array of configuration IDs that this rule belongs to

    // Additional tracking fields
    protected ?DateTime $created = null;
    protected ?DateTime $updated = null;

    /**
     * @var string|null URL-friendly identifier for the rule
     */
    protected ?string $slug = null;

    /**
     * Get the conditions array
     *
     * @return array The conditions in JSON Logic format or empty array if null
     */
    public function getConditions(): array
    {
        return $this->conditions ?? [];
    }

    /**
     * Get the configuration array
     *
     * @return array The type-specific configuration or empty array if null
     */
    public function getConfiguration(): array
    {
        return $this->configuration ?? [];
    }

    public function __construct() {
        $this->addType('uuid', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
		$this->addType(fieldName:'reference', type: 'string');
		$this->addType(fieldName:'version', type: 'string');
        $this->addType('action', 'string');
        $this->addType('timing', 'string');
        $this->addType('conditions', 'json');
        $this->addType('type', 'string');
        $this->addType('configuration', 'json');
        $this->addType('order', 'integer');
        $this->addType('configurations', 'json');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');
        $this->addType('slug', 'string');
    }

    /**
     * Get fields that should be JSON encoded
     *
     * @return array<string> List of field names that are JSON type
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter($this->getFieldTypes(), function ($field) {
                return $field === 'json';
            })
        );
    }


	/**
	 * Get the slug for the endpoint.
	 * If the slug is not set, generate one from the name.
	 *
	 * @return string The slug for the endpoint
	 * @phpstan-return non-empty-string
	 * @psalm-return non-empty-string
	 */
	public function getSlug(): string
	{
		// Check if the slug is already set
		if (!empty($this->slug)) {
			return $this->slug;
		}

		// Generate a slug from the name if not set
		// Convert the name to lowercase, replace spaces with hyphens, and remove non-alphanumeric characters
		$generatedSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($this->name)));

		// Ensure the generated slug is not empty
		if (empty($generatedSlug)) {
			throw new RuntimeException('Unable to generate a valid slug from the name.');
		}

		return $generatedSlug;
	}

    /**
     * Hydrate the entity from an array of data
     *
     * @param array<string,mixed> $object Data to hydrate from
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
    }

    /**
     * Fix for deprecated way of setting synchronizations for synchronization rules
     *
     * @deprecated
     * @TODO: remove before stable 0.2.5
     *
     * @param array $configuration
     * @return array
     */
    private function parseConfiguration(array $configuration) {
        if (isset($configuration['synchronization']) === true && is_array($configuration['synchronization']) === false) {
            $configuration['synchronization'] = [
                'synchronization' => $configuration['synchronization'],
                'retainResponse' => false,
            ];

        }

        return $configuration;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
			'reference' => $this->reference,
			'version' => $this->version,
            'action' => $this->action,
            'timing' => $this->timing,
            'conditions' => $this->conditions,
            'type' => $this->type,
            'configuration' => $this->parseConfiguration($this->configuration ?? []),
            'order' => $this->order,
            'configurations' => $this->configurations,
            'created' => isset($this->created) ? $this->created->format('c') : null,
            'updated' => isset($this->updated) ? $this->updated->format('c') : null,
            'slug' => $this->getSlug(),
        ];
    }
}
