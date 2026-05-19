<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class Mapping
 *
 * Represents a mapping configuration entity that defines how to transform data between different formats.
 *
 * @package OCA\OpenConnector\Db
 * @category Database
 * @author OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://github.com/OpenConnector/openconnector
 */
class Mapping extends Entity implements JsonSerializable
{
    protected ?string $uuid = null;
	protected ?string $reference = null;
	protected ?string $version = '0.0.0';
	protected ?string $name = null;
	protected ?string $description = null;
	protected ?array $mapping = [];
	protected ?array $unset = [];
	protected ?array $cast = [];
	protected ?bool $passThrough = null;
	protected ?DateTime $dateCreated = null;
	protected ?DateTime $dateModified = null;
	protected ?array $configurations = []; // Array of configuration IDs that this mapping belongs to
	protected ?string $slug = null;

	/**
	 * Get the mapping configuration
	 *
	 * @return array The mapping configuration or empty array if null
	 */
	public function getMapping(): array
	{
		return $this->mapping ?? [];
	}

	/**
	 * Get the unset configuration
	 *
	 * @return array The unset configuration or empty array if null
	 */
	public function getUnset(): array
	{
		return $this->unset ?? [];
	}

	/**
	 * Get the cast configuration
	 *
	 * @return array The cast configuration or empty array if null
	 */
	public function getCast(): array
	{
		return $this->cast ?? [];
	}

	public function __construct() {
        $this->addType('uuid', 'string');
		$this->addType('reference', 'string');
		$this->addType('version', 'string');
		$this->addType('name', 'string');
		$this->addType('description', 'string');
		$this->addType('mapping', 'json');
		$this->addType('unset', 'json');
		$this->addType('cast', 'json');
		$this->addType('passThrough', 'boolean');
		$this->addType('dateCreated', 'datetime');
		$this->addType('dateModified', 'datetime');
		$this->addType('configurations', 'json');
		$this->addType('slug', 'string');
	}

	public function getJsonFields(): array
	{
		return array_keys(
			array_filter($this->getFieldTypes(), function ($field) {
				return $field === 'json';
			})
		);
	}

    public function getUpdated(): ?DateTime
    {
        return $this->dateModified;
    }


	/**
	 * Get the slug for the endpoint.
	 * If the slug is not set, generate one from the name.
	 * Falls back to a deterministic value when transliteration yields an empty result.
	 *
	 * @return string The slug for the endpoint
	 * @phpstan-return non-empty-string
	 * @psalm-return non-empty-string
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.ErrorControlOperator)
	 */
	public function getSlug(): string
	{
		// Return existing slug when present
		if (!empty($this->slug)) {
			return $this->slug;
		}

		// Prepare name
		$name = trim((string)($this->name ?? ''));

		// Attempt transliteration to ASCII for non-Latin names
		$transliterated = $name;
		if ($name !== '') {
			if (class_exists('\Transliterator')) {
				$transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
				if ($transliterator !== false) {
					$transliterated = (string)$transliterator->transliterate($name);
				}
			} elseif (function_exists('iconv')) {
				$converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
				if ($converted !== false) {
					$transliterated = $converted;
				}
			}
		}

		// Convert to slug: lowercase, non-alphanumeric to hyphens, trim
		$generatedSlug = strtolower($transliterated);
		$generatedSlug = preg_replace('/[^a-z0-9]+/', '-', $generatedSlug ?? '');
		$generatedSlug = trim((string)$generatedSlug, '-');

		// Safe fallback if empty (e.g., name only contains symbols or could not transliterate)
		if ($generatedSlug === '') {
			$prefix = 'mapping';
			if (isset($this->id) && (string)$this->id !== '') {
				$generatedSlug = $prefix . '-' . (string)$this->id;
			}

			if ($generatedSlug === '') {
				try {
					$generatedSlug = $prefix . '-' . bin2hex(random_bytes(4));
				} catch (\Exception $e) {
					$generatedSlug = $prefix . '-' . substr(md5((string)$name), 0, 8);
				}
			}
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
//				("Error writing $key");
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
			'version' => $this->version,
			'reference' => $this->reference,
			'mapping' => $this->mapping,
			'unset' => $this->unset,
			'cast' => $this->cast,
			'passThrough' => $this->passThrough,
			'configurations' => $this->configurations,
			'dateCreated' => isset($this->dateCreated) ? $this->dateCreated->format('c') : null,
			'dateModified' => isset($this->dateModified) ? $this->dateModified->format('c') : null,
			'slug' => $this->getSlug(),
		];
	}
}
