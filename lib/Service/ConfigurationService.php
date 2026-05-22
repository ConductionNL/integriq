<?php

namespace OCA\OpenConnector\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCA\OpenConnector\Service\ConfigurationHandlers\EndpointHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\SynchronizationHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\MappingHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\JobHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\SourceHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\RuleHandler;

/**
 * Class ConfigurationService
 *
 * Service class for managing configurations and their associated entities.
 *
 * @package   OCA\OpenConnector\Service
 * @category  Service
 * @author    OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/OpenConnector/openconnector
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class ConfigurationService
{

    /**
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * @var array<string,ConfigurationHandlerInterface>
     */
    private array $handlers = [];

    /**
     * Global mapping structure for entity ID and slug relationships.
     * This structure is used during export/import operations to maintain consistent
     * references between entities.
     *
     * Structure:
     * [
     *     'endpoint' => [
     *         'idToSlug' => ['id1' => 'slug1', 'id2' => 'slug2', ...],
     *         'slugToId' => ['slug1' => 'id1', 'slug2' => 'id2', ...]
     *     ],
     *     'synchronization' => [
     *         'idToSlug' => ['id1' => 'slug1', 'id2' => 'slug2', ...],
     *         'slugToId' => ['slug1' => 'id1', 'slug2' => 'id2', ...]
     *     ],
     *     'mapping' => [...],
     *     'rule' => [...],
     *     'source' => [...],
     *     'register' => [...],
     *     'schema' => [...],
     *     'job' => [...]
     * ]
     *
     * Purpose:
     * - During export: Used to replace entity IDs with their corresponding slugs
     * - During import: Used to replace entity slugs with their corresponding IDs
     * - Maintains bidirectional mapping for efficient lookups
     * - Ensures consistent references between related entities
     *
     * Usage:
     * - Access ID to slug: $this->mappings['entityType']['idToSlug'][$id]
     * - Access slug to ID: $this->mappings['entityType']['slugToId'][$slug]
     *
     * @var array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}>
     */
    private array $mappings = [];

    /**
     * ConfigurationService constructor.
     *
     * @param OrObjectService        $orObjectService        The OR object service
     * @param RegisterMapper         $registerMapper
     * @param SchemaMapper           $schemaMapper
     * @param EndpointHandler        $endpointHandler
     * @param SynchronizationHandler $synchronizationHandler
     * @param MappingHandler         $mappingHandler
     * @param JobHandler             $jobHandler
     * @param SourceHandler          $sourceHandler
     * @param RuleHandler            $ruleHandler
     */
    public function __construct(
        private readonly OrObjectService $orObjectService,
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        EndpointHandler $endpointHandler,
        SynchronizationHandler $synchronizationHandler,
        MappingHandler $mappingHandler,
        JobHandler $jobHandler,
        SourceHandler $sourceHandler,
        RuleHandler $ruleHandler
    ) {
        $this->registerMapper = $registerMapper;
        $this->schemaMapper   = $schemaMapper;

        // Register handlers
        $this->handlers['endpoint']        = $endpointHandler;
        $this->handlers['synchronization'] = $synchronizationHandler;
        $this->handlers['mapping']         = $mappingHandler;
        $this->handlers['job']    = $jobHandler;
        $this->handlers['source'] = $sourceHandler;
        $this->handlers['rule']   = $ruleHandler;
    }//end __construct()

    /**
     * Build slug/id maps for a given openconnector schema using OR findAll.
     *
     * @param  string $schema The schema slug (e.g. 'source', 'endpoint', ...)
     * @return array{idToSlug: array<string,string>, slugToId: array<string,string>}
     */
    private function buildSchemaSlugMaps(string $schema): array
    {
        $result   = $this->orObjectService->findAll(config: ['filters' => ['register' => 'openconnector', 'schema' => $schema]]);
        $items    = $result['results'] ?? $result;
        $idToSlug = [];
        $slugToId = [];
        foreach ($items as $item) {
            if ($item instanceof ObjectEntity === false) {
                continue;
            }

            $uuid = $item->getUuid();
            $data = $item->getObject();
            $slug = $data['slug'] ?? $uuid;
            if ($uuid !== '' && $slug !== '') {
                $idToSlug[$uuid] = $slug;
                $slugToId[$slug] = $uuid;
            }
        }

        return ['idToSlug' => $idToSlug, 'slugToId' => $slugToId];
    }//end buildSchemaSlugMaps()

    /**
     * Reset all mapping variables to their initial state and build new mappings
     */
    private function resetMappings(): void
    {
        // Reset mappings
        $this->mappings = [
            'endpoint'        => ['idToSlug' => [], 'slugToId' => []],
            'synchronization' => ['idToSlug' => [], 'slugToId' => []],
            'mapping'         => ['idToSlug' => [], 'slugToId' => []],
            'rule'            => ['idToSlug' => [], 'slugToId' => []],
            'source'          => ['idToSlug' => [], 'slugToId' => []],
            'register'        => ['idToSlug' => [], 'slugToId' => []],
            'schema'          => ['idToSlug' => [], 'slugToId' => []],
            'job'             => ['idToSlug' => [], 'slugToId' => []],
        ];

        // Build all openconnector schema maps via OR
        foreach (['endpoint', 'synchronization', 'mapping', 'rule', 'source', 'job'] as $schema) {
            $this->mappings[$schema] = $this->buildSchemaSlugMaps($schema);
        }

        // Build register maps from OR RegisterMapper
        $registers = $this->registerMapper->findAll();
        foreach ($registers as $register) {
            $id   = (string) $register->getId();
            $slug = $register->getSlug();
            $this->mappings['register']['idToSlug'][$id]   = $slug;
            $this->mappings['register']['slugToId'][$slug] = $id;
        }

        // Build schema maps from OR SchemaMapper
        $schemas = $this->schemaMapper->findAll();
        foreach ($schemas as $schema) {
            $id   = (string) $schema->getId();
            $slug = $schema->getSlug();
            $this->mappings['schema']['idToSlug'][$id]   = $slug;
            $this->mappings['schema']['slugToId'][$slug] = $id;
        }
    }//end resetMappings()

    /**
     * Fetch all ObjectEntity items for a given openconnector schema with optional extra filters.
     *
     * @param  string $schema  The schema slug
     * @param  array  $filters Extra filters to merge in
     * @return ObjectEntity[]
     */
    private function fetchBySchema(string $schema, array $filters=[]): array
    {
        $result = $this->orObjectService->findAll(
                config: [
                    'filters' => array_merge(['register' => 'openconnector', 'schema' => $schema], $filters),
                ]
                );
        $items  = $result['results'] ?? $result;
        return array_filter(
            $items,
            static function ($item) {
                return $item instanceof ObjectEntity;
            }
        );
    }//end fetchBySchema()

    /**
     * Get all entities associated with a specific configuration ID, indexed by their slug.
     *
     * @param  string $configurationId The ID of the configuration to get entities for
     * @return array<string,array> Array containing all entities grouped by type and indexed by slug
     */
    public function getEntitiesByConfiguration(string $configurationId): array
    {
        // Helper function to index ObjectEntities by slug
        $indexBySlug = function (array $entities): array {
            $indexedEntities = [];
            foreach ($entities as $entity) {
                if ($entity instanceof ObjectEntity === false) {
                    continue;
                }

                $data = $entity->getObject();
                $slug = $data['slug'] ?? $entity->getUuid();
                if ($slug !== '' && $slug !== null) {
                    $indexedEntities[$slug] = $data;
                }
            }

            return $indexedEntities;
        };

        // Filter entities whose 'configurations' array contains the given configurationId
        $filterByConfiguration = function (array $entities) use ($configurationId): array {
            return array_filter(
                $entities,
                function ($entity) use ($configurationId) {
                    if ($entity instanceof ObjectEntity === false) {
                        return false;
                    }

                    $data           = $entity->getObject();
                    $configurations = $data['configurations'] ?? [];
                    return in_array($configurationId, (array) $configurations, true);
                }
            );
        };

        return [
            'sources'          => $indexBySlug($filterByConfiguration($this->fetchBySchema('source'))),
            'endpoints'        => $indexBySlug($filterByConfiguration($this->fetchBySchema('endpoint'))),
            'mappings'         => $indexBySlug($filterByConfiguration($this->fetchBySchema('mapping'))),
            'rules'            => $indexBySlug($filterByConfiguration($this->fetchBySchema('rule'))),
            'jobs'             => $indexBySlug($filterByConfiguration($this->fetchBySchema('job'))),
            'synchronizations' => $indexBySlug($filterByConfiguration($this->fetchBySchema('synchronization'))),
        ];
    }//end getEntitiesByConfiguration()

    /**
     * Find entities of the given schema whose 'configurations' array contains configurationId.
     *
     * @param  string $schema          Schema slug
     * @param  string $configurationId Configuration ID to search for
     * @return ObjectEntity[]
     */
    private function findByConfiguration(string $schema, string $configurationId): array
    {
        $all = $this->fetchBySchema($schema);
        return array_values(
            array_filter(
                $all,
                function ($entity) use ($configurationId) {
                    $data           = $entity->getObject();
                    $configurations = $data['configurations'] ?? [];
                    return in_array($configurationId, (array) $configurations, true);
                }
            )
        );
    }//end findByConfiguration()

    /**
     * Export all entities associated with a specific configuration ID to JSON.
     * Entities are organized by components following OAS structure.
     *
     * @param  string $configurationId The ID of the configuration to export
     * @return array<string,array> JSON-serializable array containing all entities
     */
    public function exportConfiguration(string $configurationId): array
    {
        // Reset all mappings
        $this->resetMappings();

        // Get raw entities via OR
        $sources   = $this->findByConfiguration('source', $configurationId);
        $endpoints = $this->findByConfiguration('endpoint', $configurationId);
        $mappings  = $this->findByConfiguration('mapping', $configurationId);
        $rules     = $this->findByConfiguration('rule', $configurationId);
        $jobs      = $this->findByConfiguration('job', $configurationId);
        $synchronizations = $this->findByConfiguration('synchronization', $configurationId);

        // Collect register and schema IDs from entities that reference them
        $registerIds = [];
        $schemaIds   = [];

        foreach ($endpoints as $endpoint) {
            $data = $endpoint->getObject();
            if (($data['targetType'] ?? '') === 'register/schema' && str_contains($data['targetId'] ?? '', '/')) {
                [$registerId, $schemaId] = explode('/', $data['targetId']);
                $registerIds[]           = $registerId;
                $schemaIds[] = $schemaId;
            }
        }

        foreach ($synchronizations as $synchronization) {
            $data = $synchronization->getObject();
            if (($data['sourceType'] ?? '') === 'register/schema' && str_contains($data['sourceId'] ?? '', '/')) {
                [$registerId, $schemaId] = explode('/', $data['sourceId']);
                $registerIds[]           = $registerId;
                $schemaIds[] = $schemaId;
            }

            if (($data['targetType'] ?? '') === 'register/schema' && str_contains($data['targetId'] ?? '', '/')) {
                [$registerId, $schemaId] = explode('/', $data['targetId']);
                $registerIds[]           = $registerId;
                $schemaIds[] = $schemaId;
            }
        }

        // Remove duplicates and build register/schema mappings
        $registerIds = array_filter(array_unique($registerIds));
        $schemaIds   = array_filter(array_unique($schemaIds));
        $this->buildRegisterAndSchemaMappings($registerIds, $schemaIds);

        // Export entities using handlers to convert IDs to slugs
        $exportedSources = [];
        foreach ($sources as $source) {
            $data = $source->getObject();
            $slug = $data['slug'] ?? $source->getUuid();
            $exportedSources[$slug] = $this->exportSource($source);
        }

        $exportedEndpoints = [];
        foreach ($endpoints as $endpoint) {
            $data = $endpoint->getObject();
            $slug = $data['slug'] ?? $endpoint->getUuid();
            $exportedEndpoints[$slug] = $this->exportEndpoint($endpoint);
        }

        $exportedMappings = [];
        foreach ($mappings as $mapping) {
            $data = $mapping->getObject();
            $slug = $data['slug'] ?? $mapping->getUuid();
            $exportedMappings[$slug] = $this->exportMapping($mapping);
        }

        $exportedRules = [];
        foreach ($rules as $rule) {
            $data = $rule->getObject();
            $slug = $data['slug'] ?? $rule->getUuid();
            $exportedRules[$slug] = $this->exportRule($rule);
        }

        $exportedJobs = [];
        foreach ($jobs as $job) {
            $data = $job->getObject();
            $slug = $data['slug'] ?? $job->getUuid();
            $exportedJobs[$slug] = $this->exportJob($job);
        }

        $exportedSynchronizations = [];
        foreach ($synchronizations as $synchronization) {
            $data = $synchronization->getObject();
            $slug = $data['slug'] ?? $synchronization->getUuid();
            $exportedSynchronizations[$slug] = $this->exportSynchronization($synchronization);
        }

        // Organize entities by components
        $components = [
            'components' => [
                'sources'          => $this->organizeEntitiesByComponent($exportedSources),
                'endpoints'        => $this->organizeEntitiesByComponent($exportedEndpoints),
                'mappings'         => $this->organizeEntitiesByComponent($exportedMappings),
                'rules'            => $this->organizeEntitiesByComponent($exportedRules),
                'jobs'             => $this->organizeEntitiesByComponent($exportedJobs),
                'synchronizations' => $this->organizeEntitiesByComponent($exportedSynchronizations),
            ],
        ];

        return $components;
    }//end exportConfiguration()

    /**
     * Organize entities by their component type.
     *
     * @param  array $entities Array of entities to organize
     * @return array Organized entities by component
     */
    private function organizeEntitiesByComponent(array $entities): array
    {
        $organized = [];
        foreach ($entities as $entity) {
            $component = $this->getEntityComponent($entity);
            if (!isset($organized[$component])) {
                $organized[$component] = [];
            }

            $organized[$component][] = $entity;
        }

        return $organized;
    }//end organizeEntitiesByComponent()

    /**
     * Get the component type for an entity array (exported data).
     *
     * @param  mixed $entity The exported entity array
     * @return string The component type
     */
    private function getEntityComponent($entity): string
    {
        if (is_array($entity) === false) {
            return 'default';
        }

        // For sources: use the 'type' field
        if (isset($entity['type']) && isset($entity['authorizationHeader'])) {
            return $entity['type'] ?? 'default';
        }

        // For endpoints: use targetType
        if (isset($entity['targetType'])) {
            return $entity['targetType'] ?? 'default';
        }

        // For mappings: no meaningful component type
        if (isset($entity['mapping'])) {
            return 'mapping';
        }

        // For rules: use the 'type' field
        if (isset($entity['type']) && isset($entity['configuration'])) {
            return $entity['type'] ?? 'default';
        }

        // For jobs: identified by 'jobClass'
        if (isset($entity['jobClass'])) {
            return 'job';
        }

        // For synchronizations: identified by sourceType/targetType pair
        if (isset($entity['sourceType']) || isset($entity['targetType'])) {
            return 'sync';
        }

        return 'default';
    }//end getEntityComponent()

    /**
     * Export a source to OpenAPI format
     *
     * @param  ObjectEntity $source The source to export
     * @return array The OpenAPI source specification
     */
    private function exportSource(ObjectEntity $source): array
    {
        return $this->handlers['source']->export($source, $this->mappings);
    }//end exportSource()

    /**
     * Export an endpoint to OpenAPI format
     *
     * @param  ObjectEntity $endpoint The endpoint to export
     * @return array The OpenAPI endpoint specification
     */
    private function exportEndpoint(ObjectEntity $endpoint): array
    {
        return $this->handlers['endpoint']->export($endpoint, $this->mappings);
    }//end exportEndpoint()

    /**
     * Export a mapping to OpenAPI format
     *
     * @param  ObjectEntity $mapping    The mapping to export
     * @param  array        $mappingIds Additional mapping IDs collected during export
     * @return array The OpenAPI mapping specification
     */
    private function exportMapping(ObjectEntity $mapping, array &$mappingIds=[]): array
    {
        return $this->handlers['mapping']->export($mapping, $this->mappings, $mappingIds);
    }//end exportMapping()

    /**
     * Export a rule to OpenAPI format
     *
     * @param  ObjectEntity $rule       The rule to export
     * @param  array        $mappingIds Additional mapping IDs collected during export
     * @return array The OpenAPI rule specification
     */
    private function exportRule(ObjectEntity $rule, array &$mappingIds=[]): array
    {
        return $this->handlers['rule']->export($rule, $this->mappings, $mappingIds);
    }//end exportRule()

    /**
     * Export a job to OpenAPI format
     *
     * @param  ObjectEntity $job The job to export
     * @return array The OpenAPI job specification
     */
    private function exportJob(ObjectEntity $job): array
    {
        return $this->handlers['job']->export($job, $this->mappings);
    }//end exportJob()

    /**
     * Export a synchronization to OpenAPI format
     *
     * @param  ObjectEntity $synchronization The synchronization to export
     * @return array The OpenAPI synchronization specification
     */
    private function exportSynchronization(ObjectEntity $synchronization): array
    {
        return $this->handlers['synchronization']->export($synchronization, $this->mappings);
    }//end exportSynchronization()

    /**
     * Build mappings for registers and schemas
     *
     * @param array<string> $registerIds Array of register IDs
     * @param array<string> $schemaIds   Array of schema IDs
     */
    private function buildRegisterAndSchemaMappings(array $registerIds=[], array $schemaIds=[]): void
    {
        // Get register slugs and build mappings
        if (!empty($registerIds)) {
            $registers = $this->registerMapper->findAll(filters: ['id' => $registerIds]);
            foreach ($registers as $register) {
                $id   = (string) $register->getId();
                $slug = $register->getSlug();
                $this->mappings['register']['idToSlug'][$id]   = $slug;
                $this->mappings['register']['slugToId'][$slug] = $id;
            }
        }

        // Get schema slugs and build mappings
        if (!empty($schemaIds)) {
            $schemas = $this->schemaMapper->findAll(filters: ['id' => $schemaIds]);
            foreach ($schemas as $schema) {
                $id   = (string) $schema->getId();
                $slug = $schema->getSlug();
                $this->mappings['schema']['idToSlug'][$id]   = $slug;
                $this->mappings['schema']['slugToId'][$slug] = $id;
            }
        }
    }//end buildRegisterAndSchemaMappings()

    /**
     * Find endpoints connected to a given register ID by checking targetType and targetId prefix.
     *
     * @param  string $registerId The register ID to search for
     * @return ObjectEntity[]
     */
    private function getEndpointsByTarget(string $registerId): array
    {
        $all = $this->fetchBySchema('endpoint');
        return array_values(
            array_filter(
                $all,
                function ($entity) use ($registerId) {
                    $data = $entity->getObject();
                    if (($data['targetType'] ?? '') !== 'register/schema') {
                        return false;
                    }

                    $targetId = $data['targetId'] ?? '';
                    return str_starts_with($targetId, $registerId.'/') || $targetId === $registerId;
                }
            )
        );
    }//end getEndpointsByTarget()

    /**
     * Find synchronizations connected to a given register ID.
     *
     * @param  string $registerId   The register ID to search for
     * @param  bool   $searchSource Whether to check sourceId
     * @param  bool   $searchTarget Whether to check targetId
     * @return ObjectEntity[]
     */
    private function getSynchronizationsByTarget(string $registerId, bool $searchSource=true, bool $searchTarget=true): array
    {
        $all = $this->fetchBySchema('synchronization');
        return array_values(
            array_filter(
                $all,
                function ($entity) use ($registerId, $searchSource, $searchTarget) {
                    $data = $entity->getObject();

                    if ($searchSource && ($data['sourceType'] ?? '') === 'register/schema') {
                        $sourceId = $data['sourceId'] ?? '';
                        if (str_starts_with($sourceId, $registerId.'/') || $sourceId === $registerId) {
                            return true;
                        }
                    }

                    if ($searchTarget && ($data['targetType'] ?? '') === 'register/schema') {
                        $targetId = $data['targetId'] ?? '';
                        if (str_starts_with($targetId, $registerId.'/') || $targetId === $registerId) {
                            return true;
                        }
                    }

                    return false;
                }
            )
        );
    }//end getSynchronizationsByTarget()

    /**
     * Find jobs whose arguments reference any of the given synchronization, endpoint, or source IDs.
     *
     * @param  array $synchronizationIds UUIDs of synchronizations
     * @param  array $endpointIds        UUIDs of endpoints
     * @param  array $sourceIds          UUIDs of sources
     * @return ObjectEntity[]
     */
    private function findJobsByArgumentIds(array $synchronizationIds=[], array $endpointIds=[], array $sourceIds=[]): array
    {
        if (empty($synchronizationIds) && empty($endpointIds) && empty($sourceIds)) {
            return [];
        }

        $all = $this->fetchBySchema('job');
        return array_values(
            array_filter(
                $all,
                function ($entity) use ($synchronizationIds, $endpointIds, $sourceIds) {
                    $data      = $entity->getObject();
                    $arguments = $data['arguments'] ?? [];
                    if (is_string($arguments)) {
                        $arguments = json_decode($arguments, true) ?? [];
                    }

                    if (!empty($synchronizationIds) && isset($arguments['synchronizationId'])) {
                        if (in_array((string) $arguments['synchronizationId'], array_map('strval', $synchronizationIds), true)) {
                            return true;
                        }
                    }

                    if (!empty($endpointIds) && isset($arguments['endpointId'])) {
                        if (in_array((string) $arguments['endpointId'], array_map('strval', $endpointIds), true)) {
                            return true;
                        }
                    }

                    if (!empty($sourceIds) && isset($arguments['sourceId'])) {
                        if (in_array((string) $arguments['sourceId'], array_map('strval', $sourceIds), true)) {
                            return true;
                        }
                    }

                    return false;
                }
            )
        );
    }//end findJobsByArgumentIds()

    /**
     * Find entities of the given schema by their UUIDs.
     *
     * @param  string   $schema The schema slug
     * @param  string[] $uuids  UUIDs to find
     * @return ObjectEntity[]
     */
    private function findByUuids(string $schema, array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        $all = $this->fetchBySchema($schema);
        return array_values(
            array_filter(
                $all,
                function ($entity) use ($uuids) {
                    return in_array($entity->getUuid(), $uuids, true);
                }
            )
        );
    }//end findByUuids()

    /**
     * Export all entities (endpoints and synchronizations) connected to a specific register.
     * Entities are organized by their type and indexed by slug.
     * Also includes related rules, mappings, and sources.
     *
     * @param  string $registerId              The ID of the register to export entities for
     * @param  bool   $includeEndpoints        Whether to include endpoints in the export (default: true)
     * @param  bool   $includeSynchronizations Whether to include synchronizations in the export (default: true)
     * @param  bool   $searchSource            Whether to search in source fields for synchronizations (default: true)
     * @param  bool   $searchTarget            Whether to search in target fields for synchronizations (default: true)
     * @return array<string,array> JSON-serializable array containing all connected entities
     */
    public function exportRegister(
        string $registerId,
        bool $includeEndpoints=true,
        bool $includeSynchronizations=true,
        bool $searchSource=true,
        bool $searchTarget=true
    ): array {
        // Reset all mappings
        $this->resetMappings();

        $components = [
            'components' => [
                'mappings'         => [],
                'sources'          => [],
                'rules'            => [],
                'endpoints'        => [],
                'synchronizations' => [],
                'jobs'             => [],
            ],
        ];

        // Collect all entity IDs for batch processing
        $ruleIds            = [];
        $mappingIds         = [];
        $sourceIds          = [];
        $endpointIds        = [];
        $synchronizationIds = [];
        $registerIds        = [$registerId];
        $schemaIds          = [];

        // Get and organize endpoints if requested
        $endpoints = [];
        if ($includeEndpoints) {
            $endpoints = $this->getEndpointsByTarget(registerId: $registerId);
            foreach ($endpoints as $endpoint) {
                $data          = $endpoint->getObject();
                $endpointIds[] = $endpoint->getUuid();

                // Collect related IDs
                if (!empty($data['inputMapping'])) {
                    $mappingIds[] = $data['inputMapping'];
                }

                if (!empty($data['outputMapping'])) {
                    $mappingIds[] = $data['outputMapping'];
                }

                if (($data['targetType'] ?? '') === 'api') {
                    $sourceIds[] = $data['targetId'] ?? '';
                }

                // Collect register and schema IDs from register/schema type targets
                if (($data['targetType'] ?? '') === 'register/schema' && str_contains($data['targetId'] ?? '', '/')) {
                    [$targetRegisterId, $targetSchemaId] = explode('/', $data['targetId']);
                    $registerIds[] = $targetRegisterId;
                    $schemaIds[]   = $targetSchemaId;
                }

                // Collect rule IDs
                $rules = $data['rules'] ?? [];
                if (is_array($rules)) {
                    $ruleIds = array_merge($ruleIds, $rules);
                }
            }//end foreach
        }//end if

        // Get and organize synchronizations if requested
        $synchronizations = [];
        if ($includeSynchronizations) {
            $synchronizations = $this->getSynchronizationsByTarget(
                registerId: $registerId,
                searchSource: $searchSource,
                searchTarget: $searchTarget
            );
            foreach ($synchronizations as $synchronization) {
                $data = $synchronization->getObject();
                $synchronizationIds[] = $synchronization->getUuid();

                // Collect related IDs
                if (!empty($data['sourceTargetMapping'])) {
                    $mappingIds[] = $data['sourceTargetMapping'];
                }

                if (!empty($data['targetSourceMapping'])) {
                    $mappingIds[] = $data['targetSourceMapping'];
                }

                if (($data['sourceType'] ?? '') === 'api') {
                    $sourceIds[] = $data['sourceId'] ?? '';
                }

                if (($data['targetType'] ?? '') === 'api') {
                    $sourceIds[] = $data['targetId'] ?? '';
                }

                // Collect register and schema IDs from register/schema type sources and targets
                if (($data['sourceType'] ?? '') === 'register/schema' && str_contains($data['sourceId'] ?? '', '/')) {
                    [$sourceRegisterId, $sourceSchemaId] = explode('/', $data['sourceId']);
                    $registerIds[] = $sourceRegisterId;
                    $schemaIds[]   = $sourceSchemaId;
                }

                if (($data['targetType'] ?? '') === 'register/schema' && str_contains($data['targetId'] ?? '', '/')) {
                    [$targetRegisterId, $targetSchemaId] = explode('/', $data['targetId']);
                    $registerIds[] = $targetRegisterId;
                    $schemaIds[]   = $targetSchemaId;
                }

                // Collect rule IDs from actions
                $actions = $data['actions'] ?? [];
                if (is_array($actions)) {
                    $ruleIds = array_merge($ruleIds, $actions);
                }
            }//end foreach
        }//end if

        // Remove duplicates from collected IDs and unset any empty values
        $ruleIds            = array_filter(array_unique($ruleIds));
        $mappingIds         = array_filter(array_unique($mappingIds));
        $sourceIds          = array_filter(array_unique($sourceIds));
        $endpointIds        = array_filter(array_unique($endpointIds));
        $synchronizationIds = array_filter(array_unique($synchronizationIds));
        $registerIds        = array_filter(array_unique($registerIds));
        $schemaIds          = array_filter(array_unique($schemaIds));

        // Build initial ID to slug maps for registers and schemas BEFORE exporting entities
        $this->buildRegisterAndSchemaMappings($registerIds, $schemaIds);

        // Export synchronizations now that we have the register/schema mappings
        if ($includeSynchronizations) {
            foreach ($synchronizations as $synchronization) {
                $data = $synchronization->getObject();
                $slug = $data['slug'] ?? $synchronization->getUuid();
                $components['components']['synchronizations'][$slug] = $this->exportSynchronization($synchronization);
            }
        }

        // Export endpoints now that we have the register/schema mappings
        if ($includeEndpoints) {
            foreach ($endpoints as $endpoint) {
                $data = $endpoint->getObject();
                $slug = $data['slug'] ?? $endpoint->getUuid();
                $components['components']['endpoints'][$slug] = $this->exportEndpoint($endpoint);
            }
        }

        if (!empty($sourceIds)) {
            $sources = $this->findByUuids('source', array_values($sourceIds));
            foreach ($sources as $source) {
                $data = $source->getObject();
                $slug = $data['slug'] ?? $source->getUuid();
                $components['components']['sources'][$slug] = $this->exportSource($source);
            }
        }

        if (!empty($ruleIds)) {
            $rules = $this->findByUuids('rule', array_values($ruleIds));
            foreach ($rules as $rule) {
                $data = $rule->getObject();
                $slug = $data['slug'] ?? $rule->getUuid();
                $components['components']['rules'][$slug] = $this->exportRule($rule, $mappingIds);
            }
        }

        $mappingIds = array_values(array_filter(array_unique($mappingIds)));

        // Batch fetch and export related entities
        if (!empty($mappingIds)) {
            $mappings = $this->findByUuids('mapping', $mappingIds);
            $additionalMappingIds = [];
            foreach ($mappings as $mapping) {
                $data = $mapping->getObject();
                $slug = $data['slug'] ?? $mapping->getUuid();
                $components['components']['mappings'][$slug] = $this->exportMapping($mapping, $additionalMappingIds);
            }

            while (empty($additionalMappingIds) === false) {
                $additionalMappingIds = array_values(array_filter(array_unique($additionalMappingIds)));
                $additionalMappings   = $this->findByUuids('mapping', $additionalMappingIds);
                $additionalMappingIds = [];
                foreach ($additionalMappings as $mapping) {
                    $data = $mapping->getObject();
                    $slug = $data['slug'] ?? $mapping->getUuid();
                    $components['components']['mappings'][$slug] = $this->exportMapping($mapping, $additionalMappingIds);
                }
            }
        }

        // Get and export related jobs
        if (!empty($endpointIds) || !empty($synchronizationIds) || !empty($sourceIds)) {
            $jobs = $this->findJobsByArgumentIds(
                synchronizationIds: array_values($synchronizationIds),
                endpointIds: array_values($endpointIds),
                sourceIds: array_values($sourceIds)
            );
            foreach ($jobs as $job) {
                $data = $job->getObject();
                $slug = $data['slug'] ?? $job->getUuid();
                $components['components']['jobs'][$slug] = $this->exportJob($job);
            }
        }

        return $components;
    }//end exportRegister()

    /**
     * Import a complete configuration from an OAS array.
     * Components are processed in the correct order to maintain dependencies:
     * 1. Sources (no dependencies)
     * 2. Mappings (depends on sources)
     * 3. Rules (depends on sources)
     * 4. Endpoints (depends on sources and mappings)
     * 5. Synchronizations (depends on sources, mappings, and endpoints)
     * 6. Jobs (depends on synchronizations, endpoints, and sources)
     *
     * The function preserves all relationships and target types as specified in the OAS,
     * allowing for flexible configuration imports that may target different types of entities.
     *
     * @param  array $oas The OpenAPI Specification array containing components
     * @return array<string,array> Array containing all imported entities grouped by type
     * @throws \InvalidArgumentException If required components are missing or invalid
     */
    public function importConfiguration(array $oas): array
    {
        // Reset all mappings.
        $this->resetMappings();

        // Initialize result array.
        $result = [
            'sources'          => [],
            'mappings'         => [],
            'rules'            => [],
            'endpoints'        => [],
            'synchronizations' => [],
            'jobs'             => [],
        ];

        // Validate OAS structure.
        if (!isset($oas['components'])) {
            throw new \InvalidArgumentException('OAS must contain a components property');
        }

        $components = $oas['components'];

        // 1. Import sources first (no dependencies).
        if (isset($components['sources'])) {
            foreach ($components['sources'] as $sourceSlug => $sourceData) {
                $source = $this->handlers['source']->import($sourceData, $this->mappings);
                $result['sources'][$sourceSlug] = $source;
            }
        }

        // 2. Import mappings (depends on sources).
        if (isset($components['mappings'])) {
            foreach ($components['mappings'] as $mappingSlug => $mappingData) {
                $mapping = $this->handlers['mapping']->import($mappingData, $this->mappings);
                $result['mappings'][$mappingSlug] = $mapping;
            }
        }

        // 3. Import rules (depends on sources).
        if (isset($components['rules'])) {
            foreach ($components['rules'] as $ruleSlug => $ruleData) {
                $rule = $this->handlers['rule']->import($ruleData, $this->mappings);
                $result['rules'][$ruleSlug] = $rule;
            }
        }

        // 4. Import endpoints (depends on sources and mappings).
        if (isset($components['endpoints'])) {
            foreach ($components['endpoints'] as $endpointSlug => $endpointData) {
                $endpoint = $this->handlers['endpoint']->import($endpointData, $this->mappings);
                $result['endpoints'][$endpointSlug] = $endpoint;
            }
        }

        // 5. Import synchronizations (depends on sources, mappings, and endpoints).
        if (isset($components['synchronizations'])) {
            foreach ($components['synchronizations'] as $syncSlug => $syncData) {
                $synchronization = $this->handlers['synchronization']->import($syncData, $this->mappings);
                $result['synchronizations'][$syncSlug] = $synchronization;
            }
        }

        // 6. Import jobs (depends on synchronizations, endpoints, and sources).
        if (isset($components['jobs'])) {
            foreach ($components['jobs'] as $jobSlug => $jobData) {
                $job = $this->handlers['job']->import($jobData, $this->mappings);
                $result['jobs'][$jobSlug] = $job;
            }
        }

        return $result;
    }//end importConfiguration()
}//end class
