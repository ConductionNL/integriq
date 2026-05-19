<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * This class is used to define a contract for a synchronization. Or in other words, a contract between a source and target object.
 *
 * @package OCA\OpenConnector\Db
 */
/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class SynchronizationContract extends Entity implements JsonSerializable
{
	// @todo can be removed when migrations are merged
	protected ?string $sourceId = null; // OLD The id of the object in the source
	protected ?string $sourceHash = null; // OLD The hash of the object in the source

    protected ?string $uuid = null;
    protected ?string $version = null;
	protected ?string $synchronizationId = null; // The synchronization that this contract belongs to
	// Source
	protected ?string $originId = null; // The id of the object in the source
	protected ?string $originHash = null; // The hash of the object in the source
	protected ?DateTime $sourceLastChanged = null; // The last changed date of the object in the source
	protected ?DateTime $sourceLastChecked = null; // The last checked date of the object in the source
	protected ?DateTime $sourceLastSynced = null; // The last synced date of the object in the source
	// Target
	protected ?string $targetId = null; // The id of the object in the target
	protected ?string $targetHash = null; // The hash of the object in the target
	protected ?DateTime $targetLastChanged = null; // The last changed date of the object in the target
	protected ?DateTime $targetLastChecked = null; // The last checked date of the object in the target
	protected ?DateTime $targetLastSynced = null; // The last synced date of the object in the target
	protected ?string $targetLastAction = null; // The last CRUD action performed on the target (create, read, update, delete)
	// General
	protected ?DateTime $created = null; // the date and time the synchronization was created
	protected ?DateTime $updated = null; // the date and time the synchronization was updated


	public function __construct() {
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

		// @todo can be removed when migrations are merged
		$this->addType('sourceId', 'string');
		$this->addType('sourceHash', 'string');
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
			'version' => $this->version,
			'synchronizationId' => $this->synchronizationId,
			'originId' => $this->originId,
			'originHash' => $this->originHash,
			'sourceLastChanged' => isset($this->sourceLastChanged) ? $this->sourceLastChanged->format('c') : null,
			'sourceLastChecked' => isset($this->sourceLastChecked) ? $this->sourceLastChecked->format('c') : null,
			'sourceLastSynced' => isset($this->sourceLastSynced) ? $this->sourceLastSynced->format('c') : null,
			'targetId' => $this->targetId,
			'targetHash' => $this->targetHash,
			'targetLastChanged' => isset($this->targetLastChanged) ? $this->targetLastChanged->format('c') : null,
			'targetLastChecked' => isset($this->targetLastChecked) ? $this->targetLastChecked->format('c') : null,
			'targetLastSynced' => isset($this->targetLastSynced) ? $this->targetLastSynced->format('c') : null,
			'targetLastAction' => $this->targetLastAction,
			'created' => isset($this->created) ? $this->created->format('c') : null,
			'updated' => isset($this->updated) ? $this->updated->format('c') : null,
			// @todo these 2 can be removed when migrations are merged
			'sourceId' => $this->sourceId,
			'sourceHash' => $this->sourceHash
		];
	}
}
