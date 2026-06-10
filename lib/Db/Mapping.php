<?php
/**
 * OpenConnector Mapping value object.
 *
 * Post OpenRegister-cutover, mappings are persisted as OpenRegister objects
 * (register `openconnector`, schema `mapping`) and fetched via
 * `\OCA\OpenRegister\Service\ObjectService`. The legacy `openconnector_mappings`
 * table + its QBMapper were removed in the cutover, but the engine
 * (`MappingService`, `SynchronizationService`) consumes a strongly typed mapping
 * (`->getMapping()`, `->getCast()`, ...).
 *
 * This class is therefore a thin, mapper-free hydratable value object:
 * `MappingMapper` (an OpenRegister-backed adapter) hydrates it from an
 * OpenRegister `ObjectEntity` at the boundary and serialises it back through
 * `ObjectService::saveObject()`. It is NOT registered as a QBMapper entity and
 * owns no database table.
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
 * Class Mapping
 *
 * Represents a mapping configuration entity that defines how to transform data between different formats.
 *
 * @package OCA\OpenConnector\Db
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

    protected ?array $configurations = [];

    protected ?string $slug = null;

    /**
     * Get the mapping configuration.
     *
     * @return array The mapping configuration or empty array if null.
     */
    public function getMapping(): array
    {
        return $this->mapping ?? [];
    }//end getMapping()

    /**
     * Get the unset configuration.
     *
     * @return array The unset configuration or empty array if null.
     */
    public function getUnset(): array
    {
        return $this->unset ?? [];
    }//end getUnset()

    /**
     * Get the cast configuration.
     *
     * @return array The cast configuration or empty array if null.
     */
    public function getCast(): array
    {
        return $this->cast ?? [];
    }//end getCast()

    /**
     * Constructor: declare field types so getJsonFields()/hydrate() behave.
     */
    public function __construct()
    {
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
     * Backwards-compatible alias for the modification timestamp.
     *
     * @return DateTime|null The modification timestamp.
     */
    public function getUpdated(): ?DateTime
    {
        return $this->dateModified;
    }//end getUpdated()

    /**
     * Get the slug for the mapping. If unset, generate one from the name.
     * Falls back to a deterministic value when transliteration yields an empty result.
     *
     * @return         string The slug for the mapping.
     * @phpstan-return non-empty-string
     * @psalm-return   non-empty-string
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @SuppressWarnings(PHPMD.ErrorControlOperator)
     */
    public function getSlug(): string
    {
        // Return existing slug when present.
        if (empty($this->slug) === false) {
            return $this->slug;
        }

        // Prepare name.
        $name = trim((string) ($this->name ?? ''));

        // Attempt transliteration to ASCII for non-Latin names.
        $transliterated = $name;
        if ($name !== '') {
            if (class_exists('\Transliterator') === true) {
                $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
                if ($transliterator !== false) {
                    $transliterated = (string) $transliterator->transliterate($name);
                }
            } else if (function_exists('iconv') === true) {
                $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
                if ($converted !== false) {
                    $transliterated = $converted;
                }
            }
        }

        // Convert to slug: lowercase, non-alphanumeric to hyphens, trim.
        $generatedSlug = strtolower($transliterated);
        $generatedSlug = preg_replace('/[^a-z0-9]+/', '-', $generatedSlug ?? '');
        $generatedSlug = trim((string) $generatedSlug, '-');

        // Safe fallback if empty (e.g., name only contains symbols or could not transliterate).
        if ($generatedSlug === '') {
            $prefix = 'mapping';
            /** @psalm-suppress RedundantPropertyInitializationCheck */
            if (isset($this->id) === true && (string) $this->id !== '') {
                $generatedSlug = $prefix.'-'.(string) $this->id;
            }

            if ($generatedSlug === '') {
                try {
                    $generatedSlug = $prefix.'-'.bin2hex(random_bytes(4));
                } catch (\Exception $e) {
                    $generatedSlug = $prefix.'-'.substr(md5((string) $name), 0, 8);
                }
            }
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
     * Serialise the mapping to its array form.
     *
     * @return array The serialised mapping.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'name'           => $this->name,
            'description'    => $this->description,
            'version'        => $this->version,
            'reference'      => $this->reference,
            'mapping'        => $this->mapping,
            'unset'          => $this->unset,
            'cast'           => $this->cast,
            'passThrough'    => $this->passThrough,
            'configurations' => $this->configurations,
            'dateCreated'    => $this->formatDate(date: $this->dateCreated),
            'dateModified'   => $this->formatDate(date: $this->dateModified),
            'slug'           => $this->getSlug(),
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
