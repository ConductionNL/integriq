<?php
/**
 * OpenConnector Rule mapper (OpenRegister-backed adapter).
 *
 * Post OpenRegister-cutover the `openconnector_rules` table was dropped. This
 * mapper is no longer a QBMapper: it is a thin adapter over
 * `\OCA\OpenRegister\Service\ObjectService` (register `openconnector`, schema
 * `rule`). It keeps the original public surface (find / findByRef / findAll /
 * createFromArray / updateFromArray / reorder / slug maps) so the existing call
 * sites in the engine keep working, but every read/write now flows through the
 * OpenRegister object API and returns hydrated `Rule` value objects.
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

use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Uid\Uuid;

/**
 * Class RuleMapper
 *
 * OpenRegister-backed adapter for rule objects.
 *
 * @package OCA\OpenConnector\Db
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class RuleMapper
{

    /**
     * The OpenRegister register rules live in.
     *
     * @var string
     */
    private const REGISTER = 'openconnector';

    /**
     * The OpenRegister schema for rule objects.
     *
     * @var string
     */
    private const SCHEMA = 'rule';

    /**
     * Constructor.
     *
     * @param OrObjectService $orObjectService The OpenRegister object service.
     */
    public function __construct(
        private readonly OrObjectService $orObjectService
    ) {

    }//end __construct()

    /**
     * Find a rule by ID, UUID, or slug.
     *
     * @param int|string $id The ID, UUID, or slug of the rule to find.
     *
     * @return Rule The hydrated rule value object.
     *
     * @throws DoesNotExistException When no rule matches the identifier.
     */
    public function find(int | string $id): Rule
    {
        $object = $this->orObjectService->find(
            id: (string) $id,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        if ($object === null) {
            throw new DoesNotExistException('The rule you are looking for does not exist');
        }

        return (new Rule())->hydrate($object->jsonSerialize());

    }//end find()

    /**
     * Find all rules carrying the given reference.
     *
     * @param string $reference The reference to match.
     *
     * @return array<Rule> Array of Rule value objects.
     */
    public function findByRef(string $reference): array
    {
        return $this->findAll(filters: ['reference' => $reference]);

    }//end findByRef()

    /**
     * Find all rules matching the given criteria.
     *
     * @param int|null   $limit            Maximum number of results to return.
     * @param int|null   $offset           Number of results to skip.
     * @param array|null $filters          Array of field => value pairs to filter by.
     * @param array|null $searchConditions Unused (kept for signature compatibility).
     * @param array|null $searchParams     Unused (kept for signature compatibility).
     * @param array|null $ids              Array of IDs to search for, keyed by type.
     *
     * @return array<Rule> Array of Rule value objects, ordered by `order`.
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[],
        ?array $ids=[]
    ): array {
        $objects = $this->searchObjects(filters: ($filters ?? []), limit: $limit, offset: $offset, ids: $ids);

        $rules = array_map(
            fn ($object): Rule => (new Rule())->hydrate($object->jsonSerialize()),
            $objects
        );

        // Preserve the historical ordering by the `order` field.
        usort(
            $rules,
            static function (Rule $left, Rule $right): int {
                return ($left->getOrder() <=> $right->getOrder());
            }
        );

        return $rules;

    }//end findAll()

    /**
     * Create a new rule from array data.
     *
     * @param array $object Array of rule data.
     *
     * @return Rule The created rule value object.
     */
    public function createFromArray(array $object): Rule
    {
        if (empty($object['uuid']) === true) {
            $object['uuid'] = (string) Uuid::v4();
        }

        if (empty($object['version']) === true) {
            $object['version'] = '0.0.1';
        }

        // If no order is specified, append to the end.
        if (isset($object['order']) === false || $object['order'] === null) {
            $object['order'] = ($this->getMaxOrder() + 1);
        }

        // OpenRegister owns identity via the uuid; drop any stray int `id`.
        unset($object['id']);

        $saved = $this->orObjectService->saveObject(
            object: $object,
            register: self::REGISTER,
            schema: self::SCHEMA
        );

        return (new Rule())->hydrate($saved->jsonSerialize());

    }//end createFromArray()

    /**
     * Update a rule from array data.
     *
     * @param int|string $id     ID/UUID of the rule to update.
     * @param array      $object Array of updated rule data.
     *
     * @return Rule The updated rule value object.
     *
     * @throws DoesNotExistException When the rule does not exist.
     */
    public function updateFromArray(int | string $id, array $object): Rule
    {
        $existing = $this->find($id);

        if (empty($existing->getVersion()) === true) {
            $object['version'] = '0.0.1';
        } else if (empty($object['version']) === true) {
            $version = explode('.', $existing->getVersion());
            if (isset($version[2]) === true) {
                $version[2]        = ((int) $version[2] + 1);
                $object['version'] = implode('.', $version);
            }
        }

        $merged = array_merge($existing->jsonSerialize(), $object);
        unset($merged['id']);

        $saved = $this->orObjectService->saveObject(
            object: $merged,
            register: self::REGISTER,
            schema: self::SCHEMA,
            uuid: (string) ($existing->getUuid() ?? $id)
        );

        return (new Rule())->hydrate($saved->jsonSerialize());

    }//end updateFromArray()

    /**
     * Get the highest order number for rules.
     *
     * @return int The highest order value, or 0 when there are no rules.
     */
    private function getMaxOrder(): int
    {
        $max = 0;
        foreach ($this->findAll() as $rule) {
            $max = max($max, $rule->getOrder());
        }

        return $max;

    }//end getMaxOrder()

    /**
     * Get the total count of all rules.
     *
     * @return int The total number of rule objects.
     */
    public function getTotalCount(): int
    {
        return count($this->searchObjects());

    }//end getTotalCount()

    /**
     * Reorder rules.
     *
     * @param array<int|string,int> $orderMap Array of rule ID => new order.
     *
     * @return void
     */
    public function reorder(array $orderMap): void
    {
        foreach ($orderMap as $ruleId => $newOrder) {
            try {
                $this->updateFromArray((string) $ruleId, ['order' => (int) $newOrder]);
            } catch (DoesNotExistException $exception) {
                continue;
            }
        }

    }//end reorder()

    /**
     * Find all rules that belong to a specific configuration.
     *
     * @param string $configurationId The ID of the configuration to find rules for.
     *
     * @return array<Rule> Array of Rule value objects.
     */
    public function findByConfiguration(string $configurationId): array
    {
        return array_values(
            array_filter(
                $this->findAll(),
                static function (Rule $rule) use ($configurationId): bool {
                    return in_array($configurationId, (array) $rule->jsonSerialize()['configurations'], true) === true;
                }
            )
        );

    }//end findByConfiguration()

    /**
     * Get a map of rule IDs to their corresponding slugs.
     *
     * @return array<string,string> Array mapping rule IDs to slugs.
     */
    public function getIdToSlugMap(): array
    {
        $map = [];
        foreach ($this->findAll() as $rule) {
            $data                      = $rule->jsonSerialize();
            $map[(string) $data['id']] = $rule->getSlug();
        }

        return $map;

    }//end getIdToSlugMap()

    /**
     * Get a map of rule slugs to their corresponding IDs.
     *
     * @return array<string,string> Array mapping rule slugs to IDs.
     */
    public function getSlugToIdMap(): array
    {
        $map = [];
        foreach ($this->findAll() as $rule) {
            $data                     = $rule->jsonSerialize();
            $map[$rule->getSlug()] = (string) $data['id'];
        }

        return $map;

    }//end getSlugToIdMap()

    /**
     * Run an OpenRegister object search scoped to the rule register/schema.
     *
     * @param array    $filters Field filters keyed by rule property.
     * @param int|null $limit   Optional result limit.
     * @param int|null $offset  Optional result offset.
     * @param array    $ids     Optional id/uuid/slug filter buckets.
     *
     * @return array<\OCA\OpenRegister\Db\ObjectEntity> The matched OpenRegister objects.
     */
    private function searchObjects(array $filters=[], ?int $limit=null, ?int $offset=null, ?array $ids=[]): array
    {
        $config = [
            'filters' => array_merge(
                ['register' => self::REGISTER, 'schema' => self::SCHEMA],
                $filters
            ),
        ];

        if ($limit !== null) {
            $config['limit'] = $limit;
        }

        if ($offset !== null) {
            $config['offset'] = $offset;
        }

        if (empty($ids) === false) {
            $flat = array_merge(($ids['id'] ?? []), ($ids['uuid'] ?? []), ($ids['slug'] ?? []));
            if (empty($flat) === false) {
                $config['ids'] = array_values(array_unique($flat));
            }
        }

        $matches = $this->orObjectService->findAll(config: $config);

        return array_values(($matches['results'] ?? $matches));

    }//end searchObjects()
}//end class
