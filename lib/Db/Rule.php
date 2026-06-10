<?php
/**
 * OpenConnector Rule value object.
 *
 * Post OpenRegister-cutover, rules are persisted as OpenRegister objects
 * (register `openconnector`, schema `rule`) and fetched via
 * `\OCA\OpenRegister\Service\ObjectService`. The legacy `openconnector_rules`
 * table + its QBMapper were removed in the cutover, but the engine
 * (`RuleService`, `SynchronizationService`, `EndpointService`) consumes a
 * strongly typed rule (`->getConfiguration()`, `->getTiming()`, ...).
 *
 * This class is therefore a thin, mapper-free hydratable value object:
 * `RuleMapper` (an OpenRegister-backed adapter) hydrates it from an OpenRegister
 * `ObjectEntity` at the boundary and serialises it back through
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
use RuntimeException;

/**
 * Class Rule
 *
 * Represents a rule that can be triggered during endpoint handling.
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

    protected ?string $action = null;

    protected ?string $timing = 'before';

    protected ?array $conditions = [];

    protected ?string $type = null;

    protected ?array $configuration = [];

    protected int $order = 0;

    protected ?array $configurations = [];

    protected ?DateTime $created = null;

    protected ?DateTime $updated = null;

    protected ?string $slug = null;

    /**
     * Get the conditions array.
     *
     * @return array The conditions in JSON Logic format or empty array if null.
     */
    public function getConditions(): array
    {
        return $this->conditions ?? [];
    }//end getConditions()

    /**
     * Get the configuration array.
     *
     * @return array The type-specific configuration or empty array if null.
     */
    public function getConfiguration(): array
    {
        return $this->configuration ?? [];
    }//end getConfiguration()

    /**
     * Backwards-compatible alias for the rule configuration.
     *
     * Some engine paths (e.g. extend_input rule processing) request the rule
     * config through `getConfig()`; keep it pointed at the canonical
     * `configuration` payload.
     *
     * @return array The type-specific configuration or empty array if null.
     */
    public function getConfig(): array
    {
        return $this->getConfiguration();
    }//end getConfig()

    /**
     * Constructor: declare field types so getJsonFields()/hydrate() behave.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType(fieldName: 'reference', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
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
     * Get the slug for the rule. If unset, generate one from the name.
     *
     * @return         string The slug for the rule.
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
     * @param array<string,mixed> $object Data to hydrate from.
     *
     * @return self Returns the hydrated value object.
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
     * Fix for deprecated way of setting synchronizations for synchronization rules.
     *
     * @deprecated
     * @TODO:      remove before stable 0.2.5
     *
     * @param array $configuration The rule configuration.
     *
     * @return array The normalised configuration.
     */
    private function parseConfiguration(array $configuration): array
    {
        if (isset($configuration['synchronization']) === true && is_array($configuration['synchronization']) === false) {
            $configuration['synchronization'] = [
                'synchronization' => $configuration['synchronization'],
                'retainResponse'  => false,
            ];
        }

        return $configuration;
    }//end parseConfiguration()

    /**
     * Serialise the rule to its array form.
     *
     * @return array The serialised rule.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'name'           => $this->name,
            'description'    => $this->description,
            'reference'      => $this->reference,
            'version'        => $this->version,
            'action'         => $this->action,
            'timing'         => $this->timing,
            'conditions'     => $this->conditions,
            'type'           => $this->type,
            'configuration'  => $this->parseConfiguration($this->configuration ?? []),
            'order'          => $this->order,
            'configurations' => $this->configurations,
            'created'        => $this->formatDate(date: $this->created),
            'updated'        => $this->formatDate(date: $this->updated),
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
