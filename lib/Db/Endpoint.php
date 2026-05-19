<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use RuntimeException;

/**
 * Class Endpoint
 * 
 * Represents an API endpoint configuration entity
 *
 * @package OCA\OpenConnector\Db
 * @category Database
 * @author OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://github.com/OpenConnector/openconnector
 */
/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class Endpoint extends Entity implements JsonSerializable
{
    protected ?string   $uuid = null;
	protected ?string   $name = null; // The name of the endpoint
	protected ?string   $description = null; // The description of the endpoint
	protected ?string   $reference = null; // The reference of the endpoint
	protected ?string   $version = '0.0.0'; // The version of the endpoint
	protected ?string   $endpoint = null; // The actual endpoint e.g /api/buildings/{{id}}. An endpoint may contain parameters e.g {{id}}
	protected ?array    $endpointArray = []; // An array representation of the endpoint. Automatically generated
	protected ?string   $endpointRegex = null; // A regex representation of the endpoint. Automatically generated
	protected ?string   $method = null; // One of GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD. method and endpoint combination should be unique
	protected ?string   $targetType = null; // The target to attach this endpoint to, should be one of source (to create a proxy endpoint) or register/schema (to create an object endpoint) or job (to fire an event) or synchronization (to create a synchronization endpoint)
	protected ?string   $targetId = null; // The target id to attach this endpoint to
	protected ?array 	$conditions = []; // Array of conditions to be applied
	protected ?DateTime $created = null;
	protected ?DateTime $updated = null;
	protected ?string 	$inputMapping = null;
	protected ?string 	$outputMapping = null;
	protected ?array 	$rules = []; // Array of rules to be applied
	protected ?array    $configurations = []; // Array of configuration IDs that this endpoint belongs to
	protected ?string   $slug = null;

	/**
	 * Get the endpoint array representation
	 *
	 * @return array The endpoint array or empty array if null
	 */
	public function getEndpointArray(): array
	{
		return $this->endpointArray ?? [];
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
	 * Get the rules array
	 *
	 * @return array The rules or empty array if null
	 */
	public function getRules(): array
	{
		return $this->rules ?? [];
	}

	public function __construct() {
        $this->addType(fieldName:'uuid', type: 'string');
		$this->addType(fieldName:'name', type: 'string');
		$this->addType(fieldName:'description', type: 'string');
		$this->addType(fieldName:'reference', type: 'string');
		$this->addType(fieldName:'version', type: 'string');
		$this->addType(fieldName:'endpoint', type: 'string');
		$this->addType(fieldName:'endpointArray', type: 'json');
		$this->addType(fieldName:'endpointRegex', type: 'string');
		$this->addType(fieldName:'method', type: 'string');
		$this->addType(fieldName:'targetType', type: 'string');
		$this->addType(fieldName:'targetId', type: 'string');
		$this->addType(fieldName:'conditions', type: 'json');
		$this->addType(fieldName:'created', type: 'datetime');
		$this->addType(fieldName:'updated', type: 'datetime');
		$this->addType(fieldName:'inputMapping', type: 'string');
		$this->addType(fieldName:'outputMapping', type: 'string');
		$this->addType(fieldName:'rules', type: 'json');
		$this->addType(fieldName:'configurations', type: 'json');
		$this->addType(fieldName:'slug', type: 'string');
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
				// ("Error writing $key");
			}
		}

		return $this;
	}

	/**
	 * Serialize the endpoint entity to JSON
	 *
	 * @return array<string,mixed> The serialized endpoint data
	 */
	public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'reference' => $this->reference,
            'version' => $this->version,
            'endpoint' => $this->endpoint,
            'endpointArray' => $this->endpointArray,
            'endpointRegex' => $this->endpointRegex,
            'method' => $this->method,
            'targetType' => $this->targetType,
            'targetId' => $this->targetId,
            'conditions' => $this->conditions,
            'inputMapping' => $this->inputMapping,
            'outputMapping' => $this->outputMapping,
            'rules' => $this->rules,
            'configurations' => $this->configurations,
            'slug' => $this->getSlug(),
            'created' => isset($this->created) ? $this->created->format('c') : null,
            'updated' => isset($this->updated) ? $this->updated->format('c') : null
        ];
    }
}
