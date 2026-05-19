<?php

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\Db\Source;
use OCA\OpenConnector\Db\Endpoint;
use OCA\OpenConnector\Db\Mapping;
use OCA\OpenConnector\Db\Rule;
use OCA\OpenConnector\Db\Job;
use OCA\OpenConnector\Db\Synchronization;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Db\EndpointMapper;
use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\RuleMapper;
use OCA\OpenConnector\Db\JobMapper;
use OCA\OpenConnector\Db\SynchronizationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
 * @package OCA\OpenConnector\Service
 * @category Service
 * @author OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://github.com/OpenConnector/openconnector
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
     * @var SourceMapper
     */
    private SourceMapper $sourceMapper;

    /**
     * @var EndpointMapper
     */
    private EndpointMapper $endpointMapper;

    /**
     * @var MappingMapper
     */
    private MappingMapper $mappingMapper;

    /**
     * @var RuleMapper
     */
    private RuleMapper $ruleMapper;

    /**
     * @var JobMapper
     */
    private JobMapper $jobMapper;

    /**
     * @var SynchronizationMapper
     */
    private SynchronizationMapper $synchronizationMapper;

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
     * @param SourceMapper $sourceMapper
     * @param EndpointMapper $endpointMapper
     * @param MappingMapper $mappingMapper
     * @param RuleMapper $ruleMapper
     * @param JobMapper $jobMapper
     * @param SynchronizationMapper $synchronizationMapper
     * @param RegisterMapper $registerMapper
     * @param SchemaMapper $schemaMapper
     * @param EndpointHandler $endpointHandler
     * @param SynchronizationHandler $synchronizationHandler
     * @param MappingHandler $mappingHandler
     * @param JobHandler $jobHandler
     * @param SourceHandler $sourceHandler
     * @param RuleHandler $ruleHandler
     */
    public function __construct(
        SourceMapper $sourceMapper,
        EndpointMapper $endpointMapper,
        MappingMapper $mappingMapper,
        RuleMapper $ruleMapper,
        JobMapper $jobMapper,
        SynchronizationMapper $synchronizationMapper,
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        EndpointHandler $endpointHandler,
        SynchronizationHandler $synchronizationHandler,
        MappingHandler $mappingHandler,
        JobHandler $jobHandler,
        SourceHandler $sourceHandler,
        RuleHandler $ruleHandler
    ) {
        $this->sourceMapper = $sourceMapper;
        $this->endpointMapper = $endpointMapper;
        $this->mappingMapper = $mappingMapper;
        $this->ruleMapper = $ruleMapper;
        $this->jobMapper = $jobMapper;
        $this->synchronizationMapper = $synchronizationMapper;
        $this->registerMapper = $registerMapper;
        $this->schemaMapper = $schemaMapper;

        // Register handlers
        $this->handlers['endpoint'] = $endpointHandler;
        $this->handlers['synchronization'] = $synchronizationHandler;
        $this->handlers['mapping'] = $mappingHandler;
        $this->handlers['job'] = $jobHandler;
        $this->handlers['source'] = $sourceHandler;
        $this->handlers['rule'] = $ruleHandler;
    }

    /**
     * Reset all mapping variables to their initial state and build new mappings
     */
    private function resetMappings(): void
    {
        // Reset mappings
        $this->mappings = [
            'endpoint' => ['idToSlug' => [], 'slugToId' => []],
            'synchronization' => ['idToSlug' => [], 'slugToId' => []],
            'mapping' => ['idToSlug' => [], 'slugToId' => []],
            'rule' => ['idToSlug' => [], 'slugToId' => []],
            'source' => ['idToSlug' => [], 'slugToId' => []],
            'register' => ['idToSlug' => [], 'slugToId' => []],
            'schema' => ['idToSlug' => [], 'slugToId' => []],
            'job' => ['idToSlug' => [], 'slugToId' => []]
        ];

        // Build all mappings at once
        $this->mappings['endpoint']['idToSlug'] = $this->endpointMapper->getIdToSlugMap();
        $this->mappings['endpoint']['slugToId'] = $this->endpointMapper->getSlugToIdMap();
        $this->mappings['job']['idToSlug'] = $this->jobMapper->getIdToSlugMap();
        $this->mappings['job']['slugToId'] = $this->jobMapper->getSlugToIdMap();
        $this->mappings['synchronization']['idToSlug'] = $this->synchronizationMapper->getIdToSlugMap();
        $this->mappings['synchronization']['slugToId'] = $this->synchronizationMapper->getSlugToIdMap();
        $this->mappings['mapping']['idToSlug'] = $this->mappingMapper->getIdToSlugMap();
        $this->mappings['mapping']['slugToId'] = $this->mappingMapper->getSlugToIdMap();
        $this->mappings['rule']['idToSlug'] = $this->ruleMapper->getIdToSlugMap();
        $this->mappings['rule']['slugToId'] = $this->ruleMapper->getSlugToIdMap();
        $this->mappings['source']['idToSlug'] = $this->sourceMapper->getIdToSlugMap();
        $this->mappings['source']['slugToId'] = $this->sourceMapper->getSlugToIdMap();
        $this->mappings['register']['idToSlug'] = $this->registerMapper->getIdToSlugMap();
        $this->mappings['register']['slugToId'] = $this->registerMapper->getSlugToIdMap();
        $this->mappings['schema']['idToSlug'] = $this->schemaMapper->getIdToSlugMap();
        $this->mappings['schema']['slugToId'] = $this->schemaMapper->getSlugToIdMap();
    }

    /**
     * Get all entities associated with a specific configuration ID, indexed by their slug.
     *
     * @param string $configurationId The ID of the configuration to get entities for
     * @return array<string,array> Array containing all entities grouped by type and indexed by slug
     */
    public function getEntitiesByConfiguration(string $configurationId): array
    {
        // Helper function to index entities by slug
        $indexBySlug = function(array $entities): array {
            $indexedEntities = [];
            foreach ($entities as $entity) {
                if (isset($entity['slug'])) {
                    $indexedEntities[$entity['slug']] = $entity;
                }
            }
            return $indexedEntities;
        };

        return [
            'sources' => $indexBySlug($this->sourceMapper->findByConfiguration($configurationId)),
            'endpoints' => $indexBySlug($this->endpointMapper->findByConfiguration($configurationId)),
            'mappings' => $indexBySlug($this->mappingMapper->findByConfiguration($configurationId)),
            'rules' => $indexBySlug($this->ruleMapper->findByConfiguration($configurationId)),
            'jobs' => $indexBySlug($this->jobMapper->findByConfiguration($configurationId)),
            'synchronizations' => $indexBySlug($this->synchronizationMapper->findByConfiguration($configurationId)),
        ];
    }

    /**
     * Export all entities associated with a specific configuration ID to JSON.
     * Entities are organized by components following OAS structure.
     *
     * @param string $configurationId The ID of the configuration to export
     * @return array<string,array> JSON-serializable array containing all entities
     */
    public function exportConfiguration(string $configurationId): array
    {
        // Reset all mappings
        $this->resetMappings();
        
        // Get raw entities from database
        $sources = $this->sourceMapper->findByConfiguration($configurationId);
        $endpoints = $this->endpointMapper->findByConfiguration($configurationId);
        $mappings = $this->mappingMapper->findByConfiguration($configurationId);
        $rules = $this->ruleMapper->findByConfiguration($configurationId);
        $jobs = $this->jobMapper->findByConfiguration($configurationId);
        $synchronizations = $this->synchronizationMapper->findByConfiguration($configurationId);
        
        // Collect register and schema IDs from entities that reference them
        $registerIds = [];
        $schemaIds = [];
        
        foreach ($endpoints as $endpoint) {
            if ($endpoint->getTargetType() === 'register/schema' && str_contains($endpoint->getTargetId(), '/')) {
                [$registerId, $schemaId] = explode('/', $endpoint->getTargetId());
                $registerIds[] = $registerId;
                $schemaIds[] = $schemaId;
            }
        }
        
        foreach ($synchronizations as $synchronization) {
            if ($synchronization->getSourceType() === 'register/schema' && str_contains($synchronization->getSourceId(), '/')) {
                [$registerId, $schemaId] = explode('/', $synchronization->getSourceId());
                $registerIds[] = $registerId;
                $schemaIds[] = $schemaId;
            }
            if ($synchronization->getTargetType() === 'register/schema' && str_contains($synchronization->getTargetId(), '/')) {
                [$registerId, $schemaId] = explode('/', $synchronization->getTargetId());
                $registerIds[] = $registerId;
                $schemaIds[] = $schemaId;
            }
        }
        
        // Remove duplicates and build register/schema mappings
        $registerIds = array_filter(array_unique($registerIds));
        $schemaIds = array_filter(array_unique($schemaIds));
        $this->buildRegisterAndSchemaMappings($registerIds, $schemaIds);
        
        // Export entities using handlers to convert IDs to slugs
        $exportedSources = [];
        foreach ($sources as $source) {
            $exportedSources[$source->getSlug()] = $this->exportSource($source);
        }
        
        $exportedEndpoints = [];
        foreach ($endpoints as $endpoint) {
            $exportedEndpoints[$endpoint->getSlug()] = $this->exportEndpoint($endpoint);
        }
        
        $exportedMappings = [];
        foreach ($mappings as $mapping) {
            $exportedMappings[$mapping->getSlug()] = $this->exportMapping($mapping);
        }
        
        $exportedRules = [];
        foreach ($rules as $rule) {
            $exportedRules[$rule->getSlug()] = $this->exportRule($rule);
        }
        
        $exportedJobs = [];
        foreach ($jobs as $job) {
            $slug = $job->getSlug();
            if (empty($slug)) {
                // Generate slug if not set
                $slug = 'job-' . $job->getId();
            }
            $exportedJobs[$slug] = $this->exportJob($job);
        }
        
        $exportedSynchronizations = [];
        foreach ($synchronizations as $synchronization) {
            $exportedSynchronizations[$synchronization->getSlug()] = $this->exportSynchronization($synchronization);
        }
        
        // Organize entities by components
        $components = [
            'components' => [
                'sources' => $this->organizeEntitiesByComponent($exportedSources),
                'endpoints' => $this->organizeEntitiesByComponent($exportedEndpoints),
                'mappings' => $this->organizeEntitiesByComponent($exportedMappings),
                'rules' => $this->organizeEntitiesByComponent($exportedRules),
                'jobs' => $this->organizeEntitiesByComponent($exportedJobs),
                'synchronizations' => $this->organizeEntitiesByComponent($exportedSynchronizations),
            ],
        ];

        return $components;
    }

    /**
     * Organize entities by their component type.
     *
     * @param array $entities Array of entities to organize
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
    }

    /**
     * Get the component type for an entity.
     *
     * @param mixed $entity The entity to get the component type for
     * @return string The component type
     */
    private function getEntityComponent($entity): string
    {
        if ($entity instanceof Source) {
            return $entity->getType() ?? 'default';
        }
        if ($entity instanceof Endpoint) {
            return $entity->getTargetType() ?? 'default';
        }
        if ($entity instanceof Mapping) {
            return 'mapping';
        }
        if ($entity instanceof Rule) {
            return $entity->getType() ?? 'default';
        }
        if ($entity instanceof Job) {
            return 'job';
        }
        if ($entity instanceof Synchronization) {
            return 'sync';
        }
        return 'default';
    }

    /**
     * Export a source to OpenAPI format
     *
     * @param Source $source The source to export
     * @return array The OpenAPI source specification
     */
    private function exportSource(Source $source): array
    {
        return $this->handlers['source']->export($source, $this->mappings);
    }

    /**
     * Export an endpoint to OpenAPI format
     *
     * @param Endpoint $endpoint The endpoint to export
     * @return array The OpenAPI endpoint specification
     */
    private function exportEndpoint(Endpoint $endpoint): array
    {
        return $this->handlers['endpoint']->export($endpoint, $this->mappings);
    }

    /**
     * Export a mapping to OpenAPI format
     *
     * @param Mapping $mapping The mapping to export
     * @return array The OpenAPI mapping specification
     */
    private function exportMapping(Mapping $mapping, array &$mappingIds = []): array
    {
        return $this->handlers['mapping']->export($mapping, $this->mappings, $mappingIds);
    }

    /**
     * Export a rule to OpenAPI format
     *
     * @param Rule $rule The rule to export
     * @return array The OpenAPI rule specification
     */
    private function exportRule(Rule $rule, array &$mappingIds = []): array
    {
        return $this->handlers['rule']->export($rule, $this->mappings, $mappingIds);
    }

    /**
     * Export a job to OpenAPI format
     *
     * @param Job $job The job to export
     * @return array The OpenAPI job specification
     */
    private function exportJob(Job $job): array
    {
        return $this->handlers['job']->export($job, $this->mappings);
    }

    /**
     * Export a synchronization to OpenAPI format
     *
     * @param Synchronization $synchronization The synchronization to export
     * @return array The OpenAPI synchronization specification
     */
    private function exportSynchronization(Synchronization $synchronization): array
    {
        return $this->handlers['synchronization']->export($synchronization, $this->mappings);
    }

    /**
     * Build mappings for registers and schemas
     *
     * @param array<string> $registerIds Array of register IDs
     * @param array<string> $schemaIds Array of schema IDs
     */
    private function buildRegisterAndSchemaMappings(array $registerIds = [], array $schemaIds = []): void
    {
        // Get register slugs and build mappings
        if (!empty($registerIds)) {
            $registers = $this->registerMapper->findAll(filters: ['id' => $registerIds]);
            foreach ($registers as $register) {
                $this->mappings['register']['idToSlug'][$register->getId()] = $register->getSlug();
                $this->mappings['register']['slugToId'][$register->getSlug()] = $register->getId();
            }
        }

        // Get schema slugs and build mappings
        if (!empty($schemaIds)) {
            $schemas = $this->schemaMapper->findAll(filters: ['id' => $schemaIds]);
            foreach ($schemas as $schema) {
                $this->mappings['schema']['idToSlug'][$schema->getId()] = $schema->getSlug();
                $this->mappings['schema']['slugToId'][$schema->getSlug()] = $schema->getId();
            }
        }
    }

    /**
     * Export all entities (endpoints and synchronizations) connected to a specific register.
     * Entities are organized by their type and indexed by slug.
     * Also includes related rules, mappings, and sources.
     *
     * @param string $registerId The ID of the register to export entities for
     * @param bool $includeEndpoints Whether to include endpoints in the export (default: true)
     * @param bool $includeSynchronizations Whether to include synchronizations in the export (default: true)
     * @param bool $searchSource Whether to search in source fields for synchronizations (default: true)
     * @param bool $searchTarget Whether to search in target fields for synchronizations (default: true)
     * @return array<string,array> JSON-serializable array containing all connected entities
     */
    public function exportRegister(
        string $registerId,
        bool $includeEndpoints = true,
        bool $includeSynchronizations = true,
        bool $searchSource = true,
        bool $searchTarget = true
    ): array {
        // Reset all mappings
        $this->resetMappings();

        $components = [
            'components' => [
                'mappings' => [],
                'sources' => [],
                'rules' => [],
                'endpoints' => [],
                'synchronizations' => [],
                'jobs' => []
            ],
        ];

        // Collect all entity IDs for batch processing
        $ruleIds = [];
        $mappingIds = [];
        $sourceIds = [];
        $endpointIds = [];
        $synchronizationIds = [];
        $registerIds = [$registerId];
        $schemaIds = [];

        // Get and organize endpoints if requested
        if ($includeEndpoints) {
            $endpoints = $this->endpointMapper->getByTarget(registerId: $registerId);
            foreach ($endpoints as $endpoint) {
                $endpointIds[] = $endpoint->getId();

                // Collect related IDs
                if ($endpoint->getInputMapping() !== null) {
                    $mappingIds[] = $endpoint->getInputMapping();
                }
                if ($endpoint->getOutputMapping() !== null) {
                    $mappingIds[] = $endpoint->getOutputMapping();
                }
                if ($endpoint->getTargetType() === 'api') {
                    $sourceIds[] = $endpoint->getTargetId();
                }
                
                // Collect register and schema IDs from register/schema type targets
                if ($endpoint->getTargetType() === 'register/schema' && str_contains($endpoint->getTargetId(), '/')) {
                    [$targetRegisterId, $targetSchemaId] = explode('/', $endpoint->getTargetId());
                    $registerIds[] = $targetRegisterId;
                    $schemaIds[] = $targetSchemaId;
                }
                
                // Check if endpoint has rules and collect rule IDs
                if (property_exists($endpoint, 'rules') && is_array($endpoint->getRules())) {
                    $ruleIds = array_merge($ruleIds, $endpoint->getRules());
                }
            }
        }

        // Get and organize synchronizations if requested
        if ($includeSynchronizations) {
            $synchronizations = $this->synchronizationMapper->getByTarget(
                registerId: $registerId,
                searchSource: $searchSource,
                searchTarget: $searchTarget
            );
            foreach ($synchronizations as $synchronization) {
                $synchronizationIds[] = $synchronization->getId();

                // Collect related IDs
                if ($synchronization->getSourceTargetMapping() !== null) {
                    $mappingIds[] = $synchronization->getSourceTargetMapping();
                }
                if ($synchronization->getTargetSourceMapping() !== null) {
                    $mappingIds[] = $synchronization->getTargetSourceMapping();
                }
                if ($synchronization->getSourceType() === 'api') {
                    $sourceIds[] = $synchronization->getSourceId();
                }
                if ($synchronization->getTargetType() === 'api') {
                    $sourceIds[] = $synchronization->getTargetId();
                }
                
                // Collect register and schema IDs from register/schema type sources and targets
                if ($synchronization->getSourceType() === 'register/schema' && str_contains($synchronization->getSourceId(), '/')) {
                    [$sourceRegisterId, $sourceSchemaId] = explode('/', $synchronization->getSourceId());
                    $registerIds[] = $sourceRegisterId;
                    $schemaIds[] = $sourceSchemaId;
                }
                if ($synchronization->getTargetType() === 'register/schema' && str_contains($synchronization->getTargetId(), '/')) {
                    [$targetRegisterId, $targetSchemaId] = explode('/', $synchronization->getTargetId());
                    $registerIds[] = $targetRegisterId;
                    $schemaIds[] = $targetSchemaId;
                }
                
                // Check if synchronization has actions and collect rule IDs
                if (property_exists($synchronization, 'actions') && is_array($synchronization->getActions())) {
                    $ruleIds = array_merge($ruleIds, $synchronization->getActions());
                }
            }
        }

        // Remove duplicates from collected IDs and unset any empty values
        $ruleIds = array_filter(array_unique($ruleIds));
        $mappingIds = array_filter(array_unique($mappingIds));
        $sourceIds = array_filter(array_unique($sourceIds));
        $endpointIds = array_filter(array_unique($endpointIds));
        $synchronizationIds = array_filter(array_unique($synchronizationIds));
        $registerIds = array_filter(array_unique($registerIds));
        $schemaIds = array_filter(array_unique($schemaIds));

        // Build initial ID to slug maps for registers and schemas BEFORE exporting entities
        $this->buildRegisterAndSchemaMappings($registerIds, $schemaIds);
        
        // Re-export synchronizations now that we have the register/schema mappings
        if ($includeSynchronizations) {
            $synchronizations = $this->synchronizationMapper->getByTarget(
                registerId: $registerId,
                searchSource: $searchSource,
                searchTarget: $searchTarget
            );
            foreach ($synchronizations as $synchronization) {
                $components['components']['synchronizations'][$synchronization->getSlug()] = $this->exportSynchronization($synchronization);
            }
        }
        
        // Re-export endpoints now that we have the register/schema mappings
        if ($includeEndpoints) {
            $endpoints = $this->endpointMapper->getByTarget(registerId: $registerId);
            foreach ($endpoints as $endpoint) {
                $components['components']['endpoints'][$endpoint->getSlug()] = $this->exportEndpoint($endpoint);
            }
        }


        if (!empty($sourceIds)) {
            $sources = $this->sourceMapper->findAll(ids: ['id' => $sourceIds]);
            $indexedSources = [];
            foreach ($sources as $source) {
                $indexedSources[$source->getSlug()] = $this->exportSource($source);
            }
            $components['components']['sources'] = $indexedSources;
        }

        if (!empty($ruleIds)) {
            $rules = $this->ruleMapper->findAll(ids: ['id' => $ruleIds]);
            $indexedRules = [];
            foreach ($rules as $rule) {
                $indexedRules[$rule->getSlug()] = $this->exportRule($rule, $mappingIds, $mappingIds);
            }
            $components['components']['rules'] = $indexedRules;
        }

		$mappingIds = array_map(function(string|int$mappingId) {
			if (is_int($mappingId)) {
				return $mappingId;
			}

			return (int) $mappingId;
		}, $mappingIds);

		// Batch fetch and export related entities
		if (!empty($mappingIds)) {
			$mappings = $this->mappingMapper->findAll(ids: ['id' => $mappingIds]);
			$indexedMappings = [];
			$additionalMappingIds = [];
			foreach ($mappings as $mapping) {
				$indexedMappings[$mapping->getSlug()] = $this->exportMapping($mapping, $additionalMappingIds);
			}
			$components['components']['mappings'] = $indexedMappings;
		}

		while (empty($additionalMappingIds) === false) {
			$additionalMappings = $this->mappingMapper->findAll(ids: ['id' => $additionalMappingIds]);
			$additionalMappingIds = [];
			$indexedAdditionalMappings = array_combine(
				array_map(function(Mapping $mapping) {
					return $mapping->getSlug();
					}, $additionalMappings),
				array_map(function (Mapping $mapping) use ($additionalMappingIds){
					return $this->exportMapping($mapping, $additionalMappingIds);
				}, $additionalMappings));

			$components['components']['mappings'] = array_merge($components['components']['mappings'], $indexedAdditionalMappings);
		}

        // Get and export related jobs
        if (!empty($endpointIds) || !empty($synchronizationIds) || !empty($sourceIds)) {
            $jobs = $this->jobMapper->findByArgumentIds(
                synchronizationIds: $synchronizationIds,
                endpointIds: $endpointIds,
                sourceIds: $sourceIds
            );
            $indexedJobs = [];
            foreach ($jobs as $job) {
                $slug = $job->getSlug();
                if (empty($slug)) {
                    // Generate slug if not set
                    $slug = 'job-' . $job->getId();
                }
                $indexedJobs[$slug] = $this->exportJob($job);
            }
            $components['components']['jobs'] = $indexedJobs;
        }

        return $components;
    }

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
     * @param array $oas The OpenAPI Specification array containing components
     * @return array<string,array> Array containing all imported entities grouped by type
     * @throws \InvalidArgumentException If required components are missing or invalid
     */
    public function importConfiguration(array $oas): array
    {
        // Reset all mappings.
        $this->resetMappings();

        // Initialize result array.
        $result = [
            'sources' => [],
            'mappings' => [],
            'rules' => [],
            'endpoints' => [],
            'synchronizations' => [],
            'jobs' => []
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
    }
}
