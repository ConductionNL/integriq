<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use RuntimeException;

/**
 * Class Synchronization
 *
 * Represents a synchronization configuration entity that defines how to sync data between sources and targets.
 *
 * @package OCA\OpenConnector\Db
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class Synchronization extends Entity implements JsonSerializable
{
    protected ?string $uuid = null;
	protected ?string $name = null;	// The name of the synchronization
	protected ?string $description = null;	// The description of the synchronization
	protected ?string $reference = null; // The reference of the endpoint
	protected ?string $version = '0.0.0';	// The version of the synchronization
	// Source
	protected ?string $sourceId = null;	// The id of the source object
	protected ?string $sourceType = null;	// The type of the source object (e.g. api, database, register/schema.)
	protected ?string $sourceHash = null;	// The hash of the source object when it was last synced.
	protected ?string $sourceHashMapping = null;	// The mapping id of the mapping that we map the object to for hashing.
	protected ?string $sourceTargetMapping = null;	// The mapping of the source object to the target object
	protected ?array $sourceConfig = []; // The configuration of the object in the source
	protected ?DateTime $sourceLastChanged = null;	// The last changed date of the source object
	protected ?DateTime $sourceLastChecked = null;	// The last checked date of the source object
	protected ?DateTime $sourceLastSynced = null;	// The last synced date of the source object
	protected ?int $currentPage = 1; // The last page synced. Used for keeping track where to continue syncing after Rate Limit has been exceeded on source with pagination.
	// Target
	protected ?string $targetId = null;	// The id of the target object
	protected ?string $targetType = null;	// The type of the target object (e.g. api, database, register/schema.)
	protected ?string $targetHash = null;	// The hash of the target object
	protected ?string $targetSourceMapping = null;	// The mapping of the target object to the source object
	protected ?array $targetConfig = []; // The configuration of the object in the target
	protected ?DateTime $targetLastChanged = null;	// The last changed date of the target object
	protected ?DateTime $targetLastChecked = null;	// The last checked date of the target object
	protected ?DateTime $targetLastSynced = null;	// The last synced date of the target object
	// General
	protected ?DateTime $created = null;	// The date and time the synchronization was created
	protected ?DateTime $updated = null;	// The date and time the synchronization was updated

	protected array $conditions = [];
	protected array $followUps = [];
    protected array $actions = [];
    protected ?array $configurations = []; // Array of configuration IDs that this synchronization belongs to

	/**
	 * @var string|null The status of the synchronization
	 */
	protected ?string $status = null;

	/**
	 * @var string|null URL-friendly identifier for the synchronization
	 */
	protected ?string $slug = null;

	/**
	 * Get the source configuration array
	 *
	 * @return array The source configuration or empty array if null
	 */
	public function getSourceConfig(): array
	{
		return $this->sourceConfig ?? [];
	}

	/**
	 * Get the target configuration array
	 *
	 * @return array The target configuration or empty array if null
	 */
	public function getTargetConfig(): array
	{
		return $this->targetConfig ?? [];
	}

	/**
	 * Get the conditions array
	 *
	 * @return array The conditions or empty array if null
	 */
	public function getConditions(): array
	{
		return $this->conditions ?? [];
	}

	/**
	 * Get the follow-ups array
	 *
	 * @return array The follow-ups or empty array if null
	 */
	public function getFollowUps(): array
	{
		return $this->followUps ?? [];
	}

	/**
	 * Get the actions array
	 *
	 * @return array The actions or empty array if null
	 */
	public function getActions(): array
	{
		return $this->actions ?? [];
	}

	public function __construct() {
        $this->addType('uuid', 'string');
		$this->addType('name', 'string');
		$this->addType('description', 'string');
		$this->addType(fieldName:'reference', type: 'string');
		$this->addType('version', 'string');
		$this->addType('sourceId', 'string');
		$this->addType('sourceType', 'string');
		$this->addType('sourceHash', 'string');
		$this->addType('sourceHashMapping', 'string');
		$this->addType('sourceTargetMapping', 'string');
		$this->addType('sourceConfig', 'json');
		$this->addType('sourceLastChanged', 'datetime');
		$this->addType('sourceLastChecked', 'datetime');
		$this->addType('sourceLastSynced', 'datetime');
		$this->addType('currentPage', 'integer');
		$this->addType('targetId', 'string');
		$this->addType('targetType', 'string');
		$this->addType('targetHash', 'string');
		$this->addType('targetSourceMapping', 'string');
		$this->addType('targetConfig', 'json');
		$this->addType('targetLastChanged', 'datetime');
		$this->addType('targetLastChecked', 'datetime');
		$this->addType('targetLastSynced', 'datetime');
		$this->addType('created', 'datetime');
		$this->addType('updated', 'datetime');
		$this->addType(fieldName:'conditions', type: 'json');
		$this->addType(fieldName:'followUps', type: 'json');
        $this->addType(fieldName: 'actions', type: 'json');
        $this->addType(fieldName: 'configurations', type: 'json');
		$this->addType('status', 'string');
		$this->addType('slug', 'string');
	}

    /**
     * Checks through sourceConfig if the source of this sync uses pagination
     *
     * @return bool true if its uses pagination
     */
    public function usesPagination(): bool
    {
        if (isset($this->sourceConfig['usesPagination']) === true && ($this->sourceConfig['usesPagination'] === false || $this->sourceConfig['usesPagination'] === 'false')) {
            return false;
        }

        // By default sources use basic pagination.
        return true;
    }

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
				// Error handling could be improved here
			}
		}

		return $this;
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
			'sourceId' => $this->sourceId,
			'sourceType' => $this->sourceType,
			'sourceHash' => $this->sourceHash,
			'sourceHashMapping' => $this->sourceHashMapping,
			'sourceTargetMapping' => $this->sourceTargetMapping,
			'sourceConfig' => $this->sourceConfig,
			'sourceLastChanged' => isset($this->sourceLastChanged) === true ? $this->sourceLastChanged->format('c') : null,
			'sourceLastChecked' => isset($this->sourceLastChecked) === true ? $this->sourceLastChecked->format('c') : null,
			'sourceLastSynced' => isset($this->sourceLastSynced) === true ? $this->sourceLastSynced->format('c') : null,
			'currentPage' => $this->currentPage,
			'targetId' => $this->targetId,
			'targetType' => $this->targetType,
			'targetHash' => $this->targetHash,
			'targetSourceMapping' => $this->targetSourceMapping,
			'targetConfig' => $this->targetConfig,
			'targetLastChanged' => isset($this->targetLastChanged) === true ? $this->targetLastChanged->format('c') : null,
			'targetLastChecked' => isset($this->targetLastChecked) === true ? $this->targetLastChecked->format('c') : null,
			'targetLastSynced' => isset($this->targetLastSynced) === true ? $this->targetLastSynced->format('c') : null,
			'created' => isset($this->created) === true ? $this->created->format('c') : null,
			'updated' => isset($this->updated) === true ? $this->updated->format('c') : null,
			'conditions' => $this->conditions,
			'followUps' => $this->followUps,
			'actions' => $this->actions,
			'configurations' => $this->configurations,
			'status' => $this->status,
			'slug' => $this->getSlug(),
		];
	}
}
