<?php

namespace OCA\OpenConnector\Service;

use Adbar\Dot;
use DateTime;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use JWadhams\JsonLogic;
use OC\User\NoUserException;
use OCA\OpenConnector\Db\CallLog;
use OCA\OpenConnector\Db\Mapping;
use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\Rule;
use OCA\OpenConnector\Db\RuleMapper;
use OCA\OpenConnector\Db\Source;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Db\Synchronization;
use OCA\OpenConnector\Db\SynchronizationContract;
use OCA\OpenConnector\Db\SynchronizationContractLogMapper;
use OCA\OpenConnector\Db\SynchronizationContractMapper;
use OCA\OpenConnector\Db\SynchronizationLog;
use OCA\OpenConnector\Db\SynchronizationLogMapper;
use OCA\OpenConnector\Db\SynchronizationMapper;
use OCA\OpenConnector\Service\Helper\FlowToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\GenericFileException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\Lock\LockedException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use React\Promise\Timer;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Uid\Uuid;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * SynchronizationService
 *
 * Service for handling synchronization operations between internal and external data sources.
 * Provides functionality for mapping, transforming, and synchronizing data with support for
 * asynchronous file fetching using ReactPHP for improved performance.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 * @author   Conduction b.v.
 * @copyright 2024 Conduction b.v.
 * @license  AGPL-3.0-or-later
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/OpenConnector
 *
 * @SuppressWarnings(PHPMD)
 */
class SynchronizationService
{
    private int $errorRetention;
    private int $errorContractRetention;
    private int $successRetention;

    const EXTRA_DATA_CONFIGS_LOCATION           = 'extraDataConfigs';
    const EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION  = 'dynamicEndpointLocation';
    const EXTRA_DATA_STATIC_ENDPOINT_LOCATION   = 'staticEndpoint';
    const KEY_FOR_EXTRA_DATA_LOCATION           = 'keyToSetExtraData';
    const MERGE_EXTRA_DATA_OBJECT_LOCATION      = 'mergeExtraData';
    const UNSET_CONFIG_KEY_LOCATION             = 'unsetConfigKey';
    const EXTRA_DATA_BEFORE_CONDITIONS_LOCATION = 'fetchExtraDataBeforeConditions';
    const EXTEND_BEFORE_CONDITIONS_LOCATION     = 'extendInputBeforeConditions';
    const EXTEND_BEFORE_CONDITIONS_FETCH_OBJECT = 'extendInputFetchObjectBeforeConditions';
    const FILE_TAG_TYPE                         = 'files';
    const VALID_MUTATION_TYPES                  = ['create', 'update', 'delete'];
    const DEFAULT_MAX_PAGES                     = 50; // Safety limit to prevent infinite page requesting loop


    private const DEFAULT_SUCCESS_LOG_RETENTION = 3600000;
    private const DEFAULT_ERROR_LOG_RETENTION = 259200000;

	public function __construct(
		private readonly CallService                      $callService,
		private readonly MappingService                   $mappingService,
		private readonly ContainerInterface               $containerInterface,
		private readonly SourceMapper                     $sourceMapper,
		private readonly MappingMapper                    $mappingMapper,
		private readonly SynchronizationMapper            $synchronizationMapper,
		private readonly SynchronizationLogMapper         $synchronizationLogMapper,
		private readonly SynchronizationContractMapper    $synchronizationContractMapper,
		private readonly SynchronizationContractLogMapper $synchronizationContractLogMapper,
		private readonly ObjectService                    $objectService,
        private readonly StorageService                   $storageService,
        private readonly RuleMapper                       $ruleMapper,
        private readonly LoggerInterface                  $logger,
        IAppConfig $appConfig,
	)
	{
        if($appConfig->hasKey(app: 'openconnector', key: 'retention') === true) {
            $this->errorRetention = json_decode($appConfig->getValueString(app: 'openconnector', key: 'retention'), true)['syncLogRetention'] ?? self::DEFAULT_ERROR_LOG_RETENTION;
            $this->errorContractRetention = json_decode($appConfig->getValueString(app: 'openconnector', key: 'retention'), true)['syncContractLogRetention'] ?? self::DEFAULT_ERROR_LOG_RETENTION;
            $this->successRetention = json_decode($appConfig->getValueString(app: 'openconnector', key: 'retention'), true)['successLogRetention'] ?? self::DEFAULT_SUCCESS_LOG_RETENTION;;
        } else {
            $this->errorRetention = self::DEFAULT_ERROR_LOG_RETENTION;
            $this->errorContractRetention = self::DEFAULT_ERROR_LOG_RETENTION;
            $this->successRetention = self::DEFAULT_SUCCESS_LOG_RETENTION;
        }
	}

    /**
     * Calculates the used retention for created logs. Consists of the maximum of the retention from the source, and the global retention, unless either of both is 0, in which case retention is indefinite.
     *
     * @param int[] $retentions The list of retentions in milliseconds to find the maximum duration for.
     * @return DateTime|null The calculated expiry
     * @throws \DateMalformedStringException
     *
     * @TODO: At a later point in time this should be changed to using the most specific source for expiration
     */
    private function calculateExpires(...$retentions): ?\DateTime
    {
        if (in_array(0, $retentions, true) === true) {
            return null;
        }

        return new \DateTime('now +' .max($retentions).'milliseconds');
    }

    /**
	 * Finds all synchronizations by the given source ID, which is a combination of register and schema.
	 *
	 * @param $register The register id.
	 * @param $schema The schema id.
	 *
	 * @return array The list of records matching the source ID.
	 */
	public function findAllBySourceId($register, $schema) {
		$sourceId = "$register/$schema";
		return $this->synchronizationMapper->findAll(limit: null, offset: null, filters: ['source_id' => $sourceId]);
	}

    /**
     * Handle synchronization for object create/update/delete events.
     *
     * This centralizes event listener behavior:
     * - run direct source synchronizations for the object's register/schema
     * - run related-object trigger synchronizations
     *
     * @param ObjectEntity $object The object from the event.
     * @param string $eventMutationType The triggering mutation: create|update|delete
     *
     * @return void
     */
    public function handleObjectEventSynchronization(ObjectEntity $object, string $eventMutationType): void
    {
        if (in_array($eventMutationType, self::VALID_MUTATION_TYPES, true) === false) {
            return;
        }

        $register = $object->getRegister();
        $schema = $object->getSchema();
        if ($register === null || $schema === null) {
            return;
        }

        $objectArray = $object->jsonSerialize();
        $processedSynchronizationIds = [];

        $directSynchronizations = $this->findAllBySourceId(register: $register, schema: $schema);
        foreach ($directSynchronizations as $synchronization) {
            try {
                if ($eventMutationType === 'delete') {
                    $this->synchronize(
                        synchronization: $synchronization,
                        force: true,
                        object: $object,
                        mutationType: 'delete'
                    );
                } else {
                    $this->synchronize(
                        synchronization: $synchronization,
                        force: true,
                        object: $objectArray
                    );
                }

                $processedSynchronizationIds[] = $synchronization->getId();
            } catch (\Exception $e) {
                $this->logger->error('Failed to process object event: ' . $e->getMessage() . ' for synchronization ' . $synchronization->getId(), [
                    'exception' => $e,
                    'eventMutationType' => $eventMutationType,
                    'register' => $register,
                    'schema' => $schema,
                ]);
            }
        }

        $triggeredSynchronizations = $this->synchronizationMapper->findAllByRelatedObjectTrigger(
            register: $register,
            schema: $schema,
            mutationType: $eventMutationType
        );

        foreach ($triggeredSynchronizations as $synchronization) {
            if (in_array($synchronization->getId(), $processedSynchronizationIds, true) === true) {
                continue;
            }

            try {
                $parentObjectArray = $this->resolveParentObjectForRelatedObjectTrigger(
                    synchronization: $synchronization,
                    triggerObject: $objectArray,
                    triggerRegister: $register,
                    triggerSchema: $schema,
                    mutationType: $eventMutationType
                );

                if ($parentObjectArray === null) {
                    continue;
                }

                $this->synchronize(
                    synchronization: $synchronization,
                    force: true,
                    object: $parentObjectArray
                );
            } catch (\Exception $e) {
                $this->logger->error('Failed to process related-object trigger: ' . $e->getMessage() . ' for synchronization ' . $synchronization->getId(), [
                    'exception' => $e,
                    'eventMutationType' => $eventMutationType,
                    'register' => $register,
                    'schema' => $schema,
                ]);
            }
        }
    }

    /**
     * Resolve and fetch the parent object for a related-object trigger.
     *
     * @param Synchronization $synchronization The synchronization that should run.
     * @param array $triggerObject The related object payload from the event.
     * @param string|int $triggerRegister The register of the related object source.
     * @param string|int $triggerSchema The schema of the related object source.
     *
     * @return array|null The fetched parent object as array, or null when it cannot be resolved.
     */
    public function resolveParentObjectForRelatedObjectTrigger(
        Synchronization $synchronization,
        array $triggerObject,
        string|int $triggerRegister,
        string|int $triggerSchema,
        ?string $mutationType = null
    ): ?array {
        if ($synchronization->getSourceType() !== 'register/schema') {
            return null;
        }

        $sourceId = $synchronization->getSourceId();
        if (empty($sourceId) === true || str_contains($sourceId, '/') === false) {
            return null;
        }

        $triggerSourceId = "$triggerRegister/$triggerSchema";
        $sourceConfig = $this->callService->applyConfigDot($synchronization->getSourceConfig());
        $triggerConfig = $sourceConfig['triggerFromRelatedObjects'];

        $relationKeys = [];
        if (isset($triggerConfig[$triggerSourceId]) === true && is_array($triggerConfig[$triggerSourceId]) === true) {
            $relationConfig = $triggerConfig[$triggerSourceId];
            $firstRelationKey = array_key_first($relationConfig);
            if (is_string($firstRelationKey) === true && trim($firstRelationKey) !== '') {
                $relationKeys[] = trim($firstRelationKey);
            }
        }

        if ($relationKeys === []) {
            return null;
        }

        $triggerObjectDot = new Dot($triggerObject);
        $relationReference = null;
        foreach ($relationKeys as $relationKey) {
            $candidateReference = $triggerObjectDot->get($relationKey);
            if (is_string($candidateReference) === true && trim($candidateReference) !== '') {
                $relationReference = trim($candidateReference);
                break;
            }
        }

        if ($relationReference === null) {
            return null;
        }

        $parentObjectId = null;
        if (Uuid::isValid($relationReference) === true) {
            $parentObjectId = $relationReference;
        } elseif (filter_var($relationReference, FILTER_VALIDATE_URL) !== false) {
            $path = trim((string) parse_url($relationReference, PHP_URL_PATH), '/');
            $segments = array_values(array_filter(explode('/', $path), static fn ($segment) => $segment !== ''));
            $lastSegment = end($segments) ?: null;
            if (is_string($lastSegment) === true && Uuid::isValid($lastSegment) === true) {
                $parentObjectId = $lastSegment;
            }
        }

        if ($parentObjectId === null) {
            return null;
        }

        [$parentRegister, $parentSchema] = explode('/', $sourceId, 2);
        $openRegisters = $this->objectService->getOpenRegisters();
        if ($openRegisters === null) {
            return null;
        }

        try {
            $parentObject = $openRegisters->find(
                id: $parentObjectId,
                register: $parentRegister,
                schema: $parentSchema
            );

            return $parentObject?->jsonSerialize();
        } catch (\Throwable $e) {
            $this->logger->debug('Failed resolving related parent object via configured relation key', [
                'synchronizationId' => $synchronization->getId(),
                'sourceId' => $sourceId,
                'triggerSourceId' => $triggerSourceId,
                'relationKeys' => $relationKeys,
                'relationReference' => $relationReference,
                'parentObjectId' => $parentObjectId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

	/**
	 * Synchronizes internal data to external sources based on synchronization rules.
	 *
	 * @param Synchronization $synchronization The synchronization configuration.
	 * @param \OCA\OpenRegister\Db\ObjectEntity|array $object The object to be synchronized, also referenced so its updated in parent objects.
     * @param SynchronizationLog $log The log object to record synchronization details and results.
	 * @param bool 		      $isTest Whether this is a test run (does not persist data if true).
	 * @param bool|null       $force Whether to force the synchronization regardless of changes.
	 * @param string|null $mutationType If dealing with single object synchronization, the type of the mutation that will be handled, 'create', 'update' or 'delete'. Used for syncs to extern sources.
	 *
	 * @return SynchronizationContract|array|null Returns a synchronization contract, an array for test cases, or null if conditions are not met.
	 */
	private function synchronizeInternToExtern(
		Synchronization $synchronization,
		\OCA\OpenRegister\Db\ObjectEntity|array &$object,
		SynchronizationLog $log,
        FlowToken &$flowToken,
		?bool $isTest = false,
		?bool $force = false,
		?string $mutationType = null,
	): SynchronizationContract|array|null
	{
        $serializedObject = $object;
        if($object instanceof \OCA\OpenRegister\Db\ObjectEntity === true) {
            $serializedObject = $object->jsonSerialize();
        }

        $sourceConfig = $this->callService->applyConfigDot($synchronization->getSourceConfig());
        if (isset($sourceConfig[self::EXTEND_BEFORE_CONDITIONS_LOCATION]) === true) {
            $this->logger->debug('internToExtern before extendInputBeforeConditions', [
                'objectId' => $serializedObject['@self']['id'] ?? $serializedObject['id'] ?? null,
                'betrokkeneIdentificatie' => $serializedObject['betrokkeneIdentificatie'] ?? null,
            ]);

            $fetchObject = false;
            if (isset($sourceConfig[self::EXTEND_BEFORE_CONDITIONS_FETCH_OBJECT]) === true) {
                $fetchObject = filter_var(
                    $sourceConfig[self::EXTEND_BEFORE_CONDITIONS_FETCH_OBJECT],
                    FILTER_VALIDATE_BOOLEAN
                ) === true;
            }

            $serializedObject = $this->processExtendInputRule(
                config: [
                    'extend_input' => [
                        'properties' => $sourceConfig[self::EXTEND_BEFORE_CONDITIONS_LOCATION],
                        'fetchObject' => $fetchObject,
                    ]
                ],
                data: $serializedObject
            );

            $this->logger->debug('internToExtern after extendInputBeforeConditions', [
                'objectId' => $serializedObject['@self']['id'] ?? $serializedObject['id'] ?? null,
                'betrokkeneIdentificatie' => $serializedObject['betrokkeneIdentificatie'] ?? null,
            ]);
        }


		if ($synchronization->getConditions() !== [] && !JsonLogic::apply($synchronization->getConditions(), $serializedObject)) {
			return null;
		}

		// Keep the working object in sync with pre-condition enrichment so mapping
		// and target payload generation use the same extended data.
		if (is_array($serializedObject) === true) {
			$object = $serializedObject;
		}

		$targetConfig = $synchronization->getTargetConfig();

		$originId = null;
		if (is_array($object) === true && isset($object['@self']['id']) === true) {
			$originId = $object['@self']['id'];
		} elseif (is_array($object) === true && isset($object['id']) === true) {
			$originId = $object['id'];
		}

		if ($object instanceof \OCA\OpenRegister\Db\ObjectEntity === true && $object->getUuid()) {
			$originId = $object->getUuid();
			$object = $object->getObject();
		}

		if (isset($targetConfig['extend_input']) === true) {
            $fetchObject = false;

            // If we allow the object to be fetched again, fetch including extends (so we hook into the existing extend functionality).
            if (isset($targetConfig['extend_input_fetch_object']) === true) {
                switch($targetConfig['extend_input_fetch_object']) {
                    case 0:
                    case true:
                    case 'true':
                        $fetchObject = true;
                        break;
                    default:
                        $fetchObject = false;
                        break;
                }
            }

			$object = array_merge($object, $this->processExtendInputRule(['extend_input' => ['properties' => $targetConfig['extend_input'], 'fetchObject' => $fetchObject]], $object));
		}

		// If the source configuration contains a dot notation for the id position, we need to extract the id from the source object

		$synchronizationContract = null;
		// Get the synchronization contract for this object
		if ($originId !== null) {
			$synchronizationContract = $this->synchronizationContractMapper->findSyncContractByOriginId(synchronizationId: $synchronization->id, originId: $originId);
		}

		if ($synchronizationContract instanceof SynchronizationContract === false) {
			// Only persist if not test
			if ($isTest === false) {
				$synchronizationContract = $this->synchronizationContractMapper->createFromArray([
					'synchronizationId' => $synchronization->getId(),
					'originId' => $originId,
				]);
			} else {
				$synchronizationContract = new SynchronizationContract();
				$synchronizationContract->setSynchronizationId($synchronization->getId());
				$synchronizationContract->setOriginId($originId);
			}

			$synchronizationContract = $this->synchronizeContract(synchronizationContract: $synchronizationContract, synchronization: $synchronization,  flowToken: $flowToken, object: $object, isTest: $isTest, force: $force, log: $log, mutationType: $mutationType);

			if ($isTest === true && is_array($synchronizationContract) === true) {
				// If this is a log and contract array return for the test endpoint.
				$logAndContractArray = $synchronizationContract;

				return $logAndContractArray;
			}
		} else {
			// @todo this is wierd
			$synchronizationContract = $this->synchronizeContract(synchronizationContract: $synchronizationContract, synchronization: $synchronization, flowToken: $flowToken, object: $object, isTest: $isTest, force: $force, log: $log, mutationType: $mutationType);
			if ($isTest === false && $synchronizationContract instanceof SynchronizationContract === true) {
				// If this is a regular synchronizationContract update it to the database.
				$this->synchronizationContractMapper->update(entity: $synchronizationContract);
			} elseif ($isTest === true && is_array($synchronizationContract) === true) {
				// If this is a log and contract array return for the test endpoint.
				$logAndContractArray = $synchronizationContract;

				return $logAndContractArray;
			}
		}

        if ($synchronizationContract instanceof SynchronizationContract === true) {
            $synchronizationContract = $this->synchronizationContractMapper->update($synchronizationContract);
        }
        return $synchronizationContract;
	}

    /**
     * Synchronizes external source data to the internal system.
     *
     * This method retrieves objects from the external source as configured in the `Synchronization` object.
     * Each object is processed and mapped internally, and optionally, invalid internal objects are deleted.
     * If the synchronization is part of a chain, any defined follow-ups are also executed.
     *
     * If a rate limit error occurs during the external request, a `TooManyRequestsHttpException` is thrown.
     *
     * @param Synchronization     $synchronization The synchronization configuration and state.
     * @param SynchronizationLog  $log             The log object to record synchronization details and results.
     * @param bool|null           $isTest          Optional flag to run the synchronization in test mode (no deletions, no persistence).
     * @param bool|null           $force           Optional flag to bypass change checks and force synchronization of all objects.
     * @param string|null         $source          The source to synchronize, if not provided, the synchronization's source will be used
     * @param array|null          $data            The data to add to synchronize, if not provided, the synchronization's data will be used
     * @param string|null         $mutationType    The current type of mutation we are doing this::VALID_MUTATION_TYPES
     *
     * @return SynchronizationLog Returns the updated synchronization log with processing results.
     *
     * @throws TooManyRequestsHttpException If the external source responds with a rate limiting error.
     * @throws Exception If the source ID is empty or synchronization cannot proceed.
     */
    private function synchronizeExternToIntern(
        Synchronization $synchronization,
        SynchronizationLog $log,
        FlowToken &$flowToken,
        ?bool $isTest = false,
        ?bool $force = false,
        ?string $source = null,
        ?array $data = null,
        ?string $mutationType = null
    ): SynchronizationLog {
        // Start overall timing measurement
        $overallStartTime = microtime(true);
        $rateLimitException = null;

        // Initialize timing data in result
        $result = $log->getResult();
        $result['timing'] = [
            'stages' => [],
            'total_ms' => 0
        ];

        // Stage 1: Configuration and validation
        $stageStartTime = microtime(true);
        $sourceConfig = $this->callService->applyConfigDot($synchronization->getSourceConfig());

		// If a source is provided, use it instead of the synchronization's source
		if ($source !== null) {
			$source = $this->sourceMapper->findOrCreateByLocation(location: $source);
			$synchronization->setSourceId($source->getId());
		}

        if (empty($synchronization->getSourceId()) === true && $source === null) {
            $log->setMessage('sourceId of synchronization cannot be empty. Canceling synchronization...');
            $this->synchronizationLogMapper->update($log);
            throw new Exception('sourceId of synchronization cannot be empty. Canceling synchronization...');
        }

        $result['timing']['stages']['configuration_validation'] = [
            'duration_ms' => round((microtime(true) - $stageStartTime) * 1000, 2),
            'description' => 'Configuration loading and source validation'
        ];


        if ($data !== null && $mutationType === 'delete') {
            $processResult = $this->processSynchronizationObject(
                synchronization: $synchronization,
                object: $data,
                result: $result,
                isTest: $isTest,
                force: $force,
                log: $log,
                flowToken: $flowToken,
                mutationType: $mutationType
            );
        } else {
            // Stage 2: Fetching objects from source
            $stageStartTime = microtime(true);
            try {
                $objectList = $this->getAllObjectsFromSource($synchronization, $isTest, $data);
            } catch (TooManyRequestsHttpException $e) {
                $rateLimitException = $e;
                $objectList = []; // Ensure it's defined
            }

            $fetchDuration = round((microtime(true) - $stageStartTime) * 1000, 2);
            $result['timing']['stages']['fetch_objects'] = [
                'duration_ms' => $fetchDuration,
                'description' => 'Fetching objects from external source (optimized pagination)',
                'objects_fetched' => count($objectList),
                'rate_limited' => $rateLimitException !== null,
                'fetch_method' => 'optimized_sequential'
            ];

            // Stage 3: Object list preparation
            $stageStartTime = microtime(true);
            $result['objects']['found'] = count($objectList);

            if ($sourceConfig['resultsPosition'] === '_object') {
                $objectList = [$objectList];
                $result['objects']['found'] = count($objectList);
            }

            $result['timing']['stages']['object_preparation'] = [
                'duration_ms' => round((microtime(true) - $stageStartTime) * 1000, 2),
                'description' => 'Object list preparation and counting',
                'final_object_count' => count($objectList)
            ];

            // Stage 4: Processing individual objects
            $stageStartTime = microtime(true);
            $synchronizedTargetIds = [];
            $objectProcessingTimes = [];

            foreach ($objectList as $index => $object) {
                $objectStartTime = microtime(true);

                $processResult = $this->processSynchronizationObject(
                    synchronization: $synchronization,
                    flowToken: $flowToken,
                    object: $object,
                    result: $result,
                    isTest: $isTest,
                    force: $force,
                    log: $log
                );

                $objectProcessingTime = round((microtime(true) - $objectStartTime) * 1000, 2);
                $objectProcessingTimes[] = $objectProcessingTime;

                $result = $processResult['result'];
                $result['_embed']['contracts'] = array_map(function($contractId) {
                    $contracts = $this->synchronizationContractMapper->findAll(filters: ['uuid' => $contractId]);
                    $contract = array_shift($contracts);
                    return $contract !== null ? $contract->jsonSerialize() : null;
                }, $result['contracts']);

                if ($processResult['targetId'] !== null) {
                    $synchronizedTargetIds[] = $processResult['targetId'];
                }
            }

            $totalProcessingDuration = round((microtime(true) - $stageStartTime) * 1000, 2);
            $result['timing']['stages']['process_objects'] = [
                'duration_ms' => $totalProcessingDuration,
                'description' => 'Processing and synchronizing individual objects',
                'objects_processed' => count($objectList),
                'average_per_object_ms' => count($objectList) > 0 ? round($totalProcessingDuration / count($objectList), 2) : 0,
                'min_object_ms' => count($objectProcessingTimes) > 0 ? min($objectProcessingTimes) : 0,
                'max_object_ms' => count($objectProcessingTimes) > 0 ? max($objectProcessingTimes) : 0,
                'median_object_ms' => count($objectProcessingTimes) > 0 ? $this->calculateMedian($objectProcessingTimes) : 0
            ];

            // Stage 5: Cleanup - Delete invalid objects
            $stageStartTime = microtime(true);
            $deletedCount = $this->deleteInvalidObjects(synchronization: $synchronization, synchronizedTargetIds: $synchronizedTargetIds, deleteRestriction: isset($sourceConfig['restrictDeletion']) === true && (bool)$sourceConfig['restrictDeletion'], data: isset($data) === true ? $data : []);
            $result['objects']['deleted'] = $deletedCount;

            $result['timing']['stages']['cleanup_invalid'] = [
                'duration_ms' => round((microtime(true) - $stageStartTime) * 1000, 2),
                'description' => 'Deleting invalid/orphaned objects',
                'objects_deleted' => $deletedCount
            ];
        }

        // Stage 6: Follow-up synchronizations
        $stageStartTime = microtime(true);
        $followUpCount = 0;
        foreach ($synchronization->getFollowUps() as $followUp) {
            $followUpSynchronization = $this->synchronizationMapper->find($followUp);
            $this->synchronize(synchronization: $followUpSynchronization, isTest: $isTest, force: $force);
            $followUpCount++;
        }

        $result['timing']['stages']['follow_ups'] = [
            'duration_ms' => round((microtime(true) - $stageStartTime) * 1000, 2),
            'description' => 'Executing follow-up synchronizations',
            'follow_ups_executed' => $followUpCount
        ];

        // Calculate total timing
        $result['timing']['total_ms'] = round((microtime(true) - $overallStartTime) * 1000, 2);

        // Add performance summary
        $objectsPerSecond = 0;
        if (isset($objectList) === true && count($objectList) > 0) {
            $objectsPerSecond = round(count($objectList) / ($result['timing']['total_ms'] / 1000), 2);
        }
        $result['timing']['summary'] = [
            'slowest_stage' => $this->getSlowestStage($result['timing']['stages']),
            'efficiency_ratio' => $this->calculateEfficiencyRatio($result['timing']['stages']),
            'objects_per_second' => $objectsPerSecond
        ];

        $log->setResult($result);

        if ($rateLimitException !== null) {
            $log->setMessage($rateLimitException->getMessage());
            $this->synchronizationLogMapper->update($log);

            throw new TooManyRequestsHttpException(
                $rateLimitException->getMessage(),
                429,
                $rateLimitException->getHeaders()
            );
        }

        $synchronization->setTargetLastSynced(new DateTime());
        $this->synchronizationMapper->update($synchronization);

        return $log;
    }


	/**
	 * Synchronizes a given synchronization (or a complete source).
	 *
	 * @param Synchronization $synchronization
	 * @param bool|null $isTest False by default, currently added for synchronziation-test endpoint
	 * @param bool|null $force False by default, if true, the object will be updated regardless of changes
	 * @param array|\OCA\OpenRegister\Db\ObjectEntity|null $object Object to synchronize, updated by reference
	 * @param string|null $mutationType If dealing with single object synchronization, the type of the mutation that will be handled, 'create', 'update' or 'delete'. Used for syncs to extern sources.
	 * @param string|null $source The source to synchronize, if not provided, the synchronization's source will be used
	 * @param array|null $data The data to add to synchronize, if not provided, the synchronization's data will be used
	 *
	 * @return array|SynchronizationContract|array|null
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 * @throws Exception
	 * @throws TooManyRequestsHttpException
	 */
	public function synchronize(
		Synchronization $synchronization,
		?bool $isTest = false,
		?bool $force = false,
        array|\OCA\OpenRegister\Db\ObjectEntity|null &$object = null,
		?string $mutationType = null,
		?string $source = null,
		?array $data = null,
        ?FlowToken &$flowToken = null,
	): array|SynchronizationContract|null
	{
        if($flowToken === null) {
            $flowToken = new FlowToken();
        }

		if ($mutationType !== null && in_array($mutationType, $this::VALID_MUTATION_TYPES) === false) {
			throw new Exception(sprintf('Invalid mutation type: %s given. Allowed mutation types are: %s', $mutationType, implode(', ', $this::VALID_MUTATION_TYPES)));
		}


        // Start execution time measurement
        $startTime = microtime(true);

        // Prepare initial log array
        $log = [
            'synchronizationId' => $synchronization->getUuid(),
            'result' => [
                'objects' => [
                    'found' => 0,
                    'skipped' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                    'invalid' => 0
                ],
                'contracts' => [],
                'logs' => []
            ],
            'test' => $isTest,
            'force' => $force,
            'expires' => $this->calculateExpires($this->errorRetention)
        ];


        // Shortcut for intern-to-extern sync
        if ($synchronization->getSourceType() === 'register/schema' && $object !== null) {
            // lets always create the log entry first, because we need its uuid later on for contractLogs
            $log['result']['type'] = 'internToExtern';
            $log = $this->synchronizationLogMapper->createFromArray($log);

            return $this->synchronizeInternToExtern(
                synchronization: $synchronization,
                object: $object,
                log: $log,
                flowToken: $flowToken,
                force: $force,
                mutationType: $mutationType,
            );
        }

        $log['result']['type'] = 'externToIntern';

        // lets always create the log entry first, because we need its uuid later on for contractLogs
		$log = $this->synchronizationLogMapper->createFromArray($log);

        // Handle full extern-to-intern sync
        $log = $this->synchronizeExternToIntern(
            synchronization: $synchronization,
            log: $log,
            flowToken: $flowToken,
            isTest: $isTest,
            force: $force,
            source: $source,
            data: $data,
            mutationType: $mutationType
        );

        // Finalize log
        $executionTime = round((microtime(true) - $startTime) * 1000);
        $log->setExecutionTime($executionTime);
        $log->setMessage('Success');
        $log->setExpires($this->calculateExpires($this->successRetention, $this->successRetention));
        $this->synchronizationLogMapper->update($log);

        return $log->jsonSerialize();
    }

	/**
	 * Gets id from object as is in the origin
	 *
	 * @param Synchronization $synchronization
	 * @param array $object
	 *
	 * @return string|int id
	 * @throws Exception
	 */
	private function getOriginId(Synchronization $synchronization, array $object): int|string
	{
		// Default ID position is 'id' if not specified in source config
		$originIdPosition = 'id';
		$sourceConfig = $synchronization->getSourceConfig();

		// Check if a custom ID position is defined in the source configuration
		if (isset($sourceConfig['idPosition']) === true && empty($sourceConfig['idPosition']) === false) {
			// Override default with custom ID position from config
			$originIdPosition = $sourceConfig['idPosition'];
		}

		// Create Dot object for easy access to nested array values
		$objectDot = new Dot($object);

		// Try to get the ID value from the specified position in the object
		$originId = $objectDot->get($originIdPosition);

		// If no ID was found at the specified position, throw an error
		if ($originId === null) {
			throw new Exception('Could not find origin id in object for key: ' . $originIdPosition);
		}

		// Return the found ID value
		return $originId;
	}

	/**
	 * Fetch an object from a specific endpoint.
	 *
	 * @param Synchronization $synchronization The synchronization containing the source.
	 * @param string $endpoint The endpoint to request to fetch the desired object.
	 * @param string|int|null $source The source to request if object is in other source than synchronization.
	 *
	 * @return array The resulting object.
	 *
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 */
	public function getObjectFromSource(Synchronization $synchronization, string $endpoint, string|int|null $source = null): array
	{
		$sourceId = $synchronization->getSourceId();

		// If source passed down used that instead.
		if ($source !== null) {
			$sourceId = $source;
		}

		$source = $this->sourceMapper->find(id: $sourceId);

		// Let's get the source config
		$sourceConfig = $this->callService->applyConfigDot($synchronization->getSourceConfig());

		$config = [];
		if (empty($sourceConfig['headers']) === false) {
			$config['headers'] = $sourceConfig['headers'];
		}
		if (empty($sourceConfig['query']) === false) {
			$config['query'] = $sourceConfig['query'];
		}

		if (str_starts_with($endpoint, $source->getLocation()) === true) {
			$endpoint = str_replace(search: $source->getLocation(), replace: '', subject: $endpoint);
		}

		// Make the initial API call, read denotes that we call an endpoint for a single object (for config variations).
		$response = $this->callService->call(source: $source, endpoint: $endpoint, config: $config, read: true)->getResponse();

		return json_decode($response['body'], true);
	}

	/**
	 * Fetches additional data for a given object based on the synchronization configuration.
	 *
	 * This method retrieves extra data using either a dynamically determined endpoint from the object
	 * or a statically defined endpoint in the configuration. The extra data can be merged with the original
	 * object or returned as-is, based on the provided configuration.
	 *
	 * @param Synchronization $synchronization The synchronization instance containing configuration details.
	 * @param array $extraDataConfig The configuration array specifying how to retrieve and handle the extra data:
	 *      - EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION: The key to retrieve the dynamic endpoint from the object.
	 *      - EXTRA_DATA_STATIC_ENDPOINT_LOCATION: The statically defined endpoint.
	 *      - KEY_FOR_EXTRA_DATA_LOCATION: The key under which the extra data should be returned.
	 *      - MERGE_EXTRA_DATA_OBJECT_LOCATION: Boolean flag indicating whether to merge the extra data with the object.
	 * @param array $object The original object for which extra data needs to be fetched.
	 * @param string|null $originId
	 *
	 * @return array The original object merged with the extra data, or the extra data itself based on the configuration.
	 *
	 * @throws Exception|GuzzleException If both dynamic and static endpoint configurations are missing or the endpoint cannot be determined.
	 */
	private function fetchExtraDataForObject(
		Synchronization $synchronization,
		array $extraDataConfig,
		array $object, ?string
		$originId = null
	): array
	{
		if (isset($extraDataConfig[$this::EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION]) === false && isset($extraDataConfig[$this::EXTRA_DATA_STATIC_ENDPOINT_LOCATION]) === false) {
			return $object;
		}

		// Get endpoint from earlier fetched object.
		if (isset($extraDataConfig[$this::EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION]) === true) {
			$dotObject = new Dot($object);
			$endpoint = $dotObject->get($extraDataConfig[$this::EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION] ?? null);
		}

		// Get endpoint static defined in config.
		if (isset($extraDataConfig[$this::EXTRA_DATA_STATIC_ENDPOINT_LOCATION]) === true) {

			if ($originId === null) {
				$originId = $this->getOriginId($synchronization, $object);
			}

			if (isset($extraDataConfig['endpointIdLocation']) === true) {
				$dotObject = new Dot($object);
				$originId = $dotObject->get($extraDataConfig['endpointIdLocation']);
			}


			$endpoint = $extraDataConfig[$this::EXTRA_DATA_STATIC_ENDPOINT_LOCATION];

			if ($originId === null) {
				$originId = $this->getOriginId($synchronization, $object);
			}

			$endpoint = str_replace(search: '{{ originId }}', replace: $originId, subject: $endpoint);
			$endpoint = str_replace(search: '{{originId}}', replace: $originId, subject: $endpoint);

			if (isset($extraDataConfig['subObjectId']) === true) {
				$objectDot = new Dot($object);
				$subObjectId = $objectDot->get($extraDataConfig['subObjectId']);
				if ($subObjectId !== null) {
					$endpoint = str_replace(search: '{{ subObjectId }}', replace: $subObjectId, subject: $endpoint);
					$endpoint = str_replace(search: '{{subObjectId}}', replace: $subObjectId, subject: $endpoint);
				}
			}
		}

		if (!$endpoint) {
			throw new Exception(
				sprintf(
					'Could not get static or dynamic endpoint, object: %s',
					json_encode($object)
				)
			);
		}

        $sourceConfig = $synchronization->getSourceConfig();
        if (isset($extraDataConfig[$this::UNSET_CONFIG_KEY_LOCATION]) === true && isset($sourceConfig[$extraDataConfig[$this::UNSET_CONFIG_KEY_LOCATION]]) === true) {
            unset($sourceConfig[$extraDataConfig[$this::UNSET_CONFIG_KEY_LOCATION]]);
            $synchronization->setSourceConfig($sourceConfig);
        }

		$source = null;
		if (isset($extraDataConfig['source']) === true && is_scalar($extraDataConfig['source']) === true) {
			$source = $extraDataConfig['source'];
		}

        $extraData = $this->getObjectFromSource(synchronization: $synchronization, endpoint: $endpoint, source: $source);

		// Temporary fix,
		if (isset($extraDataConfig['extraDataConfigPerResult']) === true) {

			$results = $extraData;
			if (isset($extraDataConfig['resultsLocation']) === true) {
				$dotObject = new Dot($extraData);
				$results = $dotObject->get($extraDataConfig['resultsLocation']);
			}

			foreach ($results as $key => $result) {
				$results[$key] = $this->fetchExtraDataForObject(synchronization: $synchronization, extraDataConfig: $extraDataConfig['extraDataConfigPerResult'], object: $result, originId: $originId);
			}

			$extraData = $results;
		}

		// Set new key if configured.
		if (isset($extraDataConfig[$this::KEY_FOR_EXTRA_DATA_LOCATION]) === true) {
			$extraData = [$extraDataConfig[$this::KEY_FOR_EXTRA_DATA_LOCATION] => $extraData];
		}

		// Merge with earlier fetchde object if configured.
		if (isset($extraDataConfig[$this::MERGE_EXTRA_DATA_OBJECT_LOCATION]) === true && ($extraDataConfig[$this::MERGE_EXTRA_DATA_OBJECT_LOCATION] === true || $extraDataConfig[$this::MERGE_EXTRA_DATA_OBJECT_LOCATION] === 'true')) {
			return array_merge($object, $extraData);
		}

		return $extraData;
	}

	/**
	 * Fetches multiple extra data entries for an object based on the source configuration.
	 *
	 * This method iterates through a list of extra data configurations, fetches the additional data for each configuration,
	 * and merges it with the original object.
	 *
	 * @param Synchronization $synchronization The synchronization instance containing configuration details.
	 * @param array $sourceConfig The source configuration containing extra data retrieval settings.
	 * @param array $object The original object for which extra data needs to be fetched.
	 *
	 * @return array The updated object with all fetched extra data merged into it.
	 * @throws GuzzleException
	 */
	private function fetchMultipleExtraData(Synchronization $synchronization, array $sourceConfig, array $object): array
	{
		if (isset($sourceConfig[$this::EXTRA_DATA_CONFIGS_LOCATION]) === true) {
			foreach ($sourceConfig[$this::EXTRA_DATA_CONFIGS_LOCATION] as $extraDataConfig) {
				$object = array_merge($object, $this->fetchExtraDataForObject($synchronization, $extraDataConfig, $object));
			}
		}

		return $object;
	}

	/**
	 * Maps a given object using a source hash mapping configuration.
	 *
	 * This function retrieves a hash mapping configuration for a synchronization instance, if available,
	 * and applies it to the input object using the mapping service.
	 *
	 * @param Synchronization $synchronization The synchronization instance containing the hash mapping configuration.
	 * @param array $object The input object to be mapped.
	 *
	 * @return array|Exception The mapped object, or the original object if no mapping is found.
	 * @throws LoaderError
	 * @throws SyntaxError
	 */
	private function mapHashObject(Synchronization $synchronization, array $object): array|Exception
	{
		if (empty($synchronization->getSourceHashMapping()) === false) {
			try {
				$sourceHashMapping = $this->mappingMapper->find(id: $synchronization->getSourceHashMapping());
			} catch (DoesNotExistException $exception) {
				return new Exception($exception->getMessage());
			}

			// Execute mapping if found
			if ($sourceHashMapping) {
				return $this->mappingService->executeMapping(mapping: $sourceHashMapping, input: $object);
			}
		}

		return $object;
	}

	/**
	 * Deletes invalid objects associated with a synchronization.
	 *
	 * This function identifies and removes objects that are no longer valid or do not exist
	 * in the source data for a given synchronization. It compares the target IDs from the
	 * synchronization contract with the synchronized target IDs and deletes the unmatched ones.
	 *
	 * @param Synchronization $synchronization The synchronization entity to process.
	 * @param array|null $synchronizedTargetIds An array of target IDs that are still valid in the source.
     * @param bool $deleteRestriction Sets if the deletion of objects should be restricted to identifiers called in $data
     * @param array $data The data to be checked when $deleteRestriction is true for origin ids
	 *
	 * @return int The count of objects that were deleted.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface|\OCP\DB\Exception If any database or object deletion errors occur during execution.
	 */
	public function deleteInvalidObjects(Synchronization $synchronization, ?array $synchronizedTargetIds = [], bool $deleteRestriction = false, array $data = []): int
	{
		$deletedObjectsCount = 0;
		$type = $synchronization->getTargetType();

		switch ($type) {
			case 'register/schema':

				$targetIdsToDelete = [];
				[$registerId, $schemaId] = explode(separator: '/', string: $synchronization->getTargetId());
				$allContracts = $this->synchronizationContractMapper->findAllBySynchronizationAndSchema(synchronizationId: $synchronization->getId(), schemaId: $schemaId);
				$allContractTargetIds = [];
                $allContractSourceIds = [];
				foreach ($allContracts as $contract) {
					if ($contract->getTargetId() !== null) {
						$allContractTargetIds[] = $contract->getTargetId();
                        $allContractSourceIds[$contract->getTargetId()] = $contract->getOriginId();
					}
				}

				// Initialize $synchronizedTargetIds as empty array if null
				if ($synchronizedTargetIds === null) {
					$synchronizedTargetIds = [];
				}

                // Check if we have contracts that became invalid or do not exist in the source anymore
                $targetIdsToDelete = array_diff($allContractTargetIds, $synchronizedTargetIds);
                if ($deleteRestriction === true) {
                    $encodedData = json_encode($data);
                    $targetIdsToDelete = array_filter($targetIdsToDelete, function(string|int $targetId) use ($encodedData, $allContractSourceIds) {
                        $sourceId = $allContractSourceIds[$targetId];
                        return str_contains($encodedData, $sourceId);
                    });
                }

				foreach ($targetIdsToDelete as $targetIdToDelete) {
					try {
						$synchronizationContract = $this->synchronizationContractMapper->findOnTarget(synchronization: $synchronization->getId(), targetId: $targetIdToDelete);
						if ($synchronizationContract === null) {
							continue;
						}
						$synchronizationContract = $this->updateTarget(synchronizationContract: $synchronizationContract, action: 'delete');
						$this->synchronizationContractMapper->update($synchronizationContract);
						$deletedObjectsCount++;
					} catch (DoesNotExistException $exception) {
						// @todo log
					}
				}
				break;
		}

		return $deletedObjectsCount;
	}

    /**
     * Recursively sort an associative array by key.
     *
     * @param $array mixed The array to sort.
     * @return bool Whether or not the sort is successful.
     */
    public function sortNestedArray(mixed &$array): bool
    {
        if (!is_array($array)) {
            return false;
        }
        ksort($array);
        foreach ($array as $k=> $v) {
            $this->sortNestedArray($array[$k]);
        }
        return true;
    }

    /**
     * Hash an object in a unified order, so the order in which keys are given does not influence the hash.
     *
     * @param array $object The object to hash.
     * @return string The object hash.
     */
    private function hashObject (array $object): string
    {
        $this->sortNestedArray($object);

        return md5(serialize($object));
    }

	/**
	 * Synchronize a contract
	 *
	 * @param SynchronizationContract $synchronizationContract
	 * @param Synchronization|null $synchronization
	 * @param array $object
	 * @param bool|null $isTest False by default, currently added for synchronization-test endpoint
	 * @param bool|null $force False by default, if true, the object will be updated regardless of changes
	 * @param SynchronizationLog|null $log The log to update
	 * @param string|null $mutationType If dealing with single object synchronization, the type of the mutation that will be handled, 'create', 'update' or 'delete'. Used for syncs to extern sources.
     *
	 * @return SynchronizationContract|Exception|array
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws GuzzleException
	 */
	public function synchronizeContract(
		SynchronizationContract $synchronizationContract,
        Synchronization $synchronization = null,
        FlowToken &$flowToken,
        array &$object = [],
        ?bool $isTest = false,
        ?bool $force = false,
        ?SynchronizationLog $log = null,
		?string $mutationType = null
		): SynchronizationContract|Exception|array
	{
		$contractLog = null;

		// We are doing something so lets log it
        if ($synchronizationContract->getId() !== null) {
            $contractLog = $this->synchronizationContractLogMapper->createFromArray(
                [
                    'synchronizationId' => $synchronization->getId(),
                    'synchronizationContractId' => $synchronizationContract->getId(),
                    'source' => $object,
                    'test' => $isTest,
                    'force' => $force,
                    'expiry' => $this->calculateExpires($this->errorContractRetention)
                ]
            );
        }

		if (isset($contractLog) === true) {
			$contractLog->setSynchronizationLogId($log->getId());
		}

        $flowToken->setSyncInputOriginal($object);

		$sourceConfig = $this->callService->applyConfigDot($synchronization->getSourceConfig());

		// Check if extra data needs to be fetched
        // If not fetched before conditions, fetch now
        if (isset($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION]) === false || ($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION] !== true && $sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION] !== 'true')) {
		    $object = $this->fetchMultipleExtraData(synchronization: $synchronization, sourceConfig: $sourceConfig, object: $object);
        }

        $flowToken->setSyncOutputAmended($object);

		// Get mapped hash object (some fields can make it look the object has changed even if it hasn't)
		$hashObject = $this->mapHashObject(synchronization: $synchronization, object: $object);
		// Let create a source hash for the object
		$originHash = $this->hashObject($hashObject);

		// If no source target mapping is defined, use original object
		if (empty($synchronization->getSourceTargetMapping()) === true) {
            $sourceTargetMapping = null;
		} else {
			try {
				$sourceTargetMapping = $this->mappingMapper->find(id: $synchronization->getSourceTargetMapping());
			} catch (DoesNotExistException $exception) {
				return new Exception($exception->getMessage());
			}
		}

        // Let's prevent pointless updates by checking:
        // 1. If the origin hash matches (object hasn't changed)
        // 2. If the synchronization config hasn't been updated since last check
        // 3. If source target mapping exists, check it hasn't been updated since last check
        // 4. If target ID and hash exist (object hasn't been removed from target)
        // 5. Force parameter is false (otherwise always continue with update)
		if (
            $force === false &&
            $originHash === $synchronizationContract->getOriginHash() &&
            $synchronization->getUpdated() < $synchronizationContract->getSourceLastChecked() &&
            ($sourceTargetMapping === null ||
             $sourceTargetMapping->getUpdated() < $synchronizationContract->getSourceLastChecked()) &&
            $synchronizationContract->getTargetId() !== null &&
            $synchronizationContract->getTargetHash() !== null
            ) {
			// We checked the source so let log that
			$synchronizationContract->setSourceLastChecked(new DateTime());
            $contractLog->setExpires($this->calculateExpires($this->successRetention));
			// The object has not changed and neither config nor mapping have been updated since last check
			if (isset($contractLog) === true) {
				$contractLog = $this->synchronizationContractLogMapper->update($contractLog);
			}
			return [
				'log' => isset($contractLog) === true ? $contractLog->jsonSerialize() : null,
				'contract' => $synchronizationContract->jsonSerialize(),
				'resultAction' => 'skip'
			];
		}

		// The object has changed, oke let do mappig and set metadata
		$synchronizationContract->setOriginHash($originHash);
		$synchronizationContract->setSourceLastChanged(new DateTime());
		$synchronizationContract->setSourceLastChecked(new DateTime());

        // Execute mapping if found
		$objectBeforeMapping = $object;
        if ($sourceTargetMapping) {
            $flowToken->setSyncOutputOriginal($object);

            $object = $this->mappingService->executeMapping(mapping: $sourceTargetMapping, input: $object);
            $flowToken->setSyncOutputAmended($object);
        }

        if (isset($contractLog) === true) {
		    $contractLog->setTarget($object);
        }

        $object = $this->replaceRelatedOriginIds(object: $object, config: $sourceConfig['idsToReplaceWithTargetIdsBeforeRules'] ?? [], replaceIdWithTargetId: true);
        $flowToken->setSyncOutputAmended($object);

        if ($synchronization->getActions() !== []) {
            $object = $this->processRules(synchronization: $synchronization, data: $object, timing: 'before', flowToken: $flowToken);
            $flowToken->setSyncOutputAmended($object);
        }

            // set the target hash
        $targetHash = md5(serialize($object));

        $synchronizationContract->setTargetHash($targetHash);
		$synchronizationContract->setTargetLastChanged(new DateTime());
		$synchronizationContract->setTargetLastSynced(new DateTime());
		$synchronizationContract->setSourceLastSynced(new DateTime());


		// Handle synchronization based on test mode
		if ($isTest === true) {
			// Return test data without updating target
			if (isset($contractLog) === true) {
			    $contractLog->setTargetResult('test');
                $contractLog->setExpires($this->calculateExpires($this->successRetention));
			    $contractLog = $this->synchronizationContractLogMapper->update($contractLog);
			}
			return [
				'log' => isset($contractLog) === true ? $contractLog->jsonSerialize() : null,
				'contract' => $synchronizationContract->jsonSerialize(),
				'resultAction' => 'skip'
			];
		}

		// Update target and create log when not in test mode
		$synchronizationContract = $this->updateTarget(
			synchronizationContract: $synchronizationContract,
			targetObject: $object,
			mutationType: $mutationType
		);

        if ($synchronization->getTargetType() === 'register/schema') {
            [$registerId, $schemaId] = explode(separator: '/', string: $synchronization->getTargetId());
            $this->processRules(synchronization: $synchronization, data: array_merge($object, ['_objectBeforeMapping' => $objectBeforeMapping]), timing: 'after', objectId: $synchronizationContract->getTargetId(), registerId: $registerId, schemaId: $schemaId, flowToken: $flowToken);
        } else if ($synchronization->getTargetType() === 'api' && $synchronization->getSourceType() === 'register/schema') {
            [$registerId, $schemaId] = explode(separator: '/', string: $synchronization->getSourceId());
            $this->processRules(synchronization: $synchronization, data: array_merge($object, ['_objectBeforeMapping' => $objectBeforeMapping]), timing: 'after', objectId: $synchronizationContract->getSourceId(), registerId: $registerId, schemaId: $schemaId, flowToken: $flowToken);
		}


		// Create log entry for the synchronization
        if (isset($contractLog) === true) {
		    $contractLog->setTargetResult($synchronizationContract->getTargetLastAction());
            $contractLog->setExpires($this->calculateExpires($this->successRetention));
		    $contractLog = $this->synchronizationContractLogMapper->update($contractLog);
        }

        if ($synchronizationContract->getId()) {
            $synchronizationContract = $this->synchronizationContractMapper->update($synchronizationContract);
        } else {
            if ($synchronizationContract->getUuid() === null) {
                $synchronizationContract->setUuid(Uuid::v4());
            }
            $synchronizationContract = $this->synchronizationContractMapper->insertOrUpdate($synchronizationContract);
        }

		return [
			'log' => $contractLog ? $contractLog->jsonSerialize() : [],
			'contract' => $synchronizationContract->jsonSerialize(),
			'resultAction' => 'update' // /create
		];
	}

	/**
	 * Updates or deletes a target object in the Open Register system.
	 *
	 * This method updates a target object associated with a synchronization contract
	 * or deletes it based on the specified action. It extracts the register and schema
	 * from the target ID and performs the corresponding operation using the object service.
	 *
	 * @param SynchronizationContract $synchronizationContract The synchronization contract being updated.
	 * @param Synchronization $synchronization The synchronization entity containing the target ID.
	 * @param array|null $targetObject An optional array containing the data for the target object. Defaults to an empty array.
	 * @param string|null $action The action to perform: 'save' (default) to update or 'delete' to remove the target object.
	 *
	 * @return SynchronizationContract The updated synchronization contract with the modified target ID.
	 * @throws ContainerExceptionInterface|NotFoundExceptionInterface If an error occurs while interacting with the object service or processing the data.
	 */
	private function updateTargetOpenRegister(SynchronizationContract $synchronizationContract, Synchronization $synchronization, ?array &$targetObject = [], ?string $action = 'save'): SynchronizationContract
	{
		// Setup the object service
		$objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
		$sourceConfig = $this->callService->applyConfigDot($synchronization->getSourceConfig());

		// if we already have an id, we need to get the object and update it
		if ($synchronizationContract->getTargetId() !== null) {
			$targetObject['id'] = $synchronizationContract->getTargetId();
		}

		if (isset($sourceConfig['subObjects']) === true) {
			$targetObject = $this->updateIdsOnSubObjects(subObjectsConfig: $sourceConfig['subObjects'], synchronizationId: $synchronization->getId(), targetObject: $targetObject);
		}

		// Extract register and schema from the targetId
		// The targetId needs to be filled in as: {registerId} + / + {schemaId} for example: 1/1
		$targetId = $synchronization->getTargetId();
		list($register, $schema) = explode('/', $targetId);

		// Save the object to the target
		switch ($action) {
			case 'save':
				if (isset($targetObject['id']) === true && $synchronizationContract->getTargetId() === null) {
					$synchronizationContract->setTargetId($targetObject['id']);
				}

                $targetObject = $this->replaceRelatedOriginIds(object: $targetObject, config: $sourceConfig['originIdsToReplace'] ?? []);

				$target = $objectService->saveObject(register: $register, schema: $schema, object: $targetObject, uuid: $synchronizationContract->getTargetId());
				// Get the id form the target object
				$synchronizationContract->setTargetId($target->getUuid());

                //@TODO: Orphan cleanup is done also in the fetch file rule, this can be removed after a succesful test.
//				// Clean up orphaned files based on the attachments array
//				if (isset($targetObject['attachments']) && is_array($targetObject['attachments'])) {
//					try {
//						$deletedCount = $this->cleanupFilesFromAttachments($target->getUuid(), $targetObject['attachments']);
//						if ($deletedCount > 0) {
//							error_log("Cleaned up {$deletedCount} orphaned files for object {$target->getUuid()}");
//						}
//					} catch (Exception $e) {
//						error_log("Failed to cleanup orphaned files for object {$target->getUuid()}: " . $e->getMessage());
//					}
//				}

				// Handle sub-objects synchronization if sourceConfig is defined
				if (isset($sourceConfig['subObjects']) === true) {
					$targetObject = $objectService->renderEntity($target, ['all']);
					$this->updateContractsForSubObjects(subObjectsConfig: $sourceConfig['subObjects'], synchronizationId: $synchronization->getId(), targetObject: $targetObject);
				}

				// Set target last action based on whether we're creating or updating
				$synchronizationContract->setTargetLastAction($synchronizationContract->getTargetId() ? 'update' : 'create');
				break;
			case 'delete':
				$objectService->delete(object: ['id' => $synchronizationContract->getTargetId()]);
				$synchronizationContract->setTargetId(null);
				$synchronizationContract->setTargetLastAction('delete');
				break;
		}

		return $synchronizationContract;
	}

    /**
     * Recursively replaces 'originId' values with corresponding target IDs in the given object,
     * according to the provided config array. The config array defines which keys to traverse and replace with ObjectEntity uuids.
     *
     * The object can contain nested associative arrays (sub objects) or indexed arrays of associative arrays (array of multiple subobjects).
     * Only keys present in the config array are processed.
     * Beforehand the $object must be mapped so properties that are relations to other objects set as a 'originId' which are equal to existing originIds on SynchronizationContracts, so that we can take the targetId of
     * those found contracts so objects can be linked from earlier synchronizations.
     *
     * @param array $object                The object array to process (can be nested)
     * @param array $config                A nested config tree indicating which keys to process and replace.
     * @param bool  $replaceIdWithTargetId If we need to replace id with target id found by origin id if configured.
     *
     * @return array The processed data with 'originId' replaced with actual ObjectEntities their uuids where applicable.
     */
    public function replaceRelatedOriginIds(array $object, array $config, bool $replaceIdWithTargetId = false): array
    {
        foreach ($config as $key => $subConfig) {
            if (isset($object[$key]) === false) {
                continue;
            }

            if (
                is_array($object[$key]) === true &&
                $this->isAssociativeArray(reset($object[$key])) === true &&
                is_array($subConfig) === true
            ) {
                // It's a list of associative objects
                foreach ($object[$key] as $i => $item) {
                    if (is_array($item) === true) {
                        $object[$key][$i] = $this->replaceRelatedOriginIds(object: $item, config: $subConfig, replaceIdWithTargetId: $replaceIdWithTargetId);
                    }
                }

            } elseif ($this->isAssociativeArray($object[$key]) === true && is_array($subConfig) === true) {
                // Single nested associative object
                $object[$key] = $this->replaceRelatedOriginIds(object: $object[$key], config: $subConfig, replaceIdWithTargetId: $replaceIdWithTargetId);

            } elseif ($subConfig === 'true' && is_string($object[$key]) === true && $replaceIdWithTargetId === false) {
                // Leaf: value is a string, marked for replacement
            	$object[$key] = $this->replaceIdInString($object[$key]);
            }

            // Replace 'id' at this level if requested, demands originId to be set aswel
            if ($replaceIdWithTargetId === true && $key === 'id' && isset($object['originId']) && is_string($object['originId'])) {
                $targetId = $this->replaceIdInString($object['originId']);
                if ($targetId !== null && $targetId !== $object['originId']) {
                    $object['id'] = $targetId;
                }
            }

        }
        return $object;
    }

	/**
	 * Replaces a UUID within a string with a mapped target ID using the synchronization mapper.
	 *
	 * This method scans the input string for the first valid UUID (v4 or general format),
	 * validates it using Uuid::isValid(), and replaces only that UUID with the mapped target ID.
	 * If no valid UUID is found, the original string is returned unchanged.
	 *
	 * Examples:
	 * - '80c24f50-4dc9-4937-b99e-9c253b5dfe8a' → 'abc123'
	 * - 'https://example.com/entity/80c24f50-4dc9-4937-b99e-9c253b5dfe8a' → 'https://example.com/entity/abc123'
	 * - 'no-id-here' → 'no-id-here'
	 *
	 * @param string $value The string potentially containing a UUID to replace.
	 * @return string The string with the UUID replaced if found and valid, otherwise the original string.
	 */
    private function replaceIdInString(string $value): string
    {
        // First check if we already can find object with origin id as is.
        $targetId = $this->synchronizationContractMapper->findTargetIdByOriginId($value);
        if ($targetId !== null && $targetId !== $value) {
            return $targetId;
        }

        // If not a direct match, check for embedded UUID (used for uri relations)
        if (preg_match('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/', $value, $matches) && filter_var($value, FILTER_VALIDATE_URL)) {
            $originId = $matches[0];

            if (Uuid::isValid($originId) === true) {
                $targetId = $this->synchronizationContractMapper->findTargetIdByOriginId($originId);

                if ($targetId !== null && $targetId !== $originId) {
                    return str_replace($originId, $targetId, $value);
                }
            }
        }

        return $value;
    }

	/**
	 * Handles the synchronization of subObjects based on source configuration.
	 *
	 * @param array  $subObjectsConfig  The configuration for subObjects.
	 * @param string $synchronizationId The ID of the synchronization.
	 * @param array  $targetObject      The target object containing subObjects to be processed.
	 *
	 * @return void
	 */
	private function updateContractsForSubObjects(array $subObjectsConfig, string $synchronizationId,  array $targetObject): void
	{
		foreach ($subObjectsConfig as $propertyName => $subObjectConfig) {
			if (isset($targetObject[$propertyName]) === false) {
				continue;
			}

			$propertyData = $targetObject[$propertyName];

			// If property data is an array of subObjects, iterate and process
			if (is_array($propertyData) && $this->isAssociativeArray($propertyData)) {
				if (isset($propertyData['originId'])) {
					$this->processSyncContract($synchronizationId, $propertyData);
				}

				// Recursively process any nested subObjects within the associative array
				foreach ($propertyData as $key => $value) {
					if (is_array($value) === true && isset($subObjectConfig['subObjects']) === true) {
						$this->updateContractsForSubObjects($subObjectConfig['subObjects'], $synchronizationId, [$key => $value]);
					}
				}
			}

			// Process if it's an indexed array (list) of associative arrays
			if (is_array($propertyData) === true && !$this->isAssociativeArray($propertyData)) {
				foreach ($propertyData as $subObjectData) {
					if (is_array($subObjectData) === true && isset($subObjectData['originId']) === true) {
						$this->processSyncContract($synchronizationId, $subObjectData);
					}

					// Recursively process nested sub-objects
					if (is_array($subObjectData) === true && isset($subObjectConfig['subObjects']) === true) {
						$this->updateContractsForSubObjects($subObjectConfig['subObjects'], $synchronizationId, $subObjectData);
					}
				}
			}
		}
	}

	/**
	 * Processes a single synchronization contract for a subObject.
	 *
	 * @param string $synchronizationId The ID of the synchronization.
	 * @param array $subObjectData The data of the subObject to process.
	 *
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	private function processSyncContract(string $synchronizationId, array $subObjectData): void
	{
		$id = $subObjectData['id']['id']['id']['id'] ?? $subObjectData['id']['id']['id'] ?? $subObjectData['id']['id'] ?? $subObjectData['id'];
		$subContract = $this->synchronizationContractMapper->findByOriginId(
			originId: $subObjectData['originId']
		);

		if (!$subContract) {
			$subContract = new SynchronizationContract();
			$subContract->setSynchronizationId($synchronizationId);
			$subContract->setOriginId($subObjectData['originId']);
			$subContract->setTargetId($id);
			$subContract->setUuid(Uuid::V4());
			$subContract->setTargetHash(md5(serialize($subObjectData)));
			$subContract->setTargetLastChanged(new DateTime());
			$subContract->setTargetLastSynced(new DateTime());
			$subContract->setSourceLastSynced(new DateTime());

			$subContract = $this->synchronizationContractMapper->insert($subContract);
		} else {
			$subContract = $this->synchronizationContractMapper->updateFromArray(
				id: $subContract->getId(),
				object: [
					'synchronizationId' => $synchronizationId,
					'originId'   => $subObjectData['originId'],
					'targetId'   => $id,
					'targetHash' => md5(serialize($subObjectData)),
					'targetLastChanged' => new DateTime(),
					'targetLastSynced' => new DateTime(),
					'sourceLastSynced' => new DateTime()
				]
			);
		}

		$this->synchronizationContractLogMapper->createFromArray([
			'synchronizationId' => $subContract->getSynchronizationId(),
			'synchronizationContractId' => $subContract->getId(),
			'target' => $subObjectData,
			'expires' => $this->calculateExpires($this->successRetention, $this->successRetention),
		]);
	}

	/**
	 * Checks if an array is associative.
	 *
	 * @param mixed $array The array to check.
	 *
	 * @return bool True if the array is associative, false otherwise.
	 */
	private function isAssociativeArray(mixed $array): bool
	{
		// Check if the array is associative
		return (is_array($array) && count(array_filter(array_keys($array), 'is_string')) > 0);
	}

	/**
	 * Processes subObjects update their arrays with existing targetId's so OpenRegister can update the objects instead of duplicate them.
	 *
	 * @param array     $subObjectsConfig The configuration for subObjects.
	 * @param string    $synchronizationId The ID of the synchronization.
	 * @param array     $targetObject The target object containing subObjects to be processed.
	 * @param bool|null $parentIsNumericArray Whether the parent object is a numeric array (default false).
	 *
	 * @return array The updated target object with IDs updated on subObjects.
	 */
	private function updateIdsOnSubObjects(array $subObjectsConfig, string $synchronizationId, array $targetObject): array
	{
        $targetObject = $this->updateIdOnSubObject(synchronizationId: $synchronizationId, subObject: $targetObject);

        foreach ($targetObject as $propertyName => $value) {
            if (is_array($value) === false) {
                continue;
            }

            if ($this->isAssociativeArray($value) === true) {
                $targetObject[$propertyName] = $this->updateIdsOnSubObjects(subObjectsConfig: $subObjectsConfig, synchronizationId: $synchronizationId, targetObject: $value);
                continue;
            }

            if (is_array(reset($value)) === true && $this->isAssociativeArray(reset($value)) === true) {
                foreach ($value as $key => $subValue) {
                    if (is_array($subValue) === false) {
                        continue;
                    }

                    $targetObject[$propertyName][$key] = $this->updateIdsOnSubObjects(subObjectsConfig: $subObjectsConfig, synchronizationId: $synchronizationId, targetObject: $subValue);
                }
            }
        }

		return $targetObject;
	}

	/**
	 * Updates the ID of a single subObject based on its synchronization contract so OpenRegister can update the object .
	 *
	 * @param string $synchronizationId The ID of the synchronization.
	 * @param array $subObject The subObject to update.
	 *
	 * @return array The updated subObject with the ID set based on the synchronization contract.
	 * @throws MultipleObjectsReturnedException
	 * @throws \OCP\DB\Exception
	 */
	private function updateIdOnSubObject(string $synchronizationId, array $subObject): array
	{
		if (isset($subObject['originId']) === true) {
			$subObjectContract = $this->synchronizationContractMapper->findSyncContractByOriginId(
				synchronizationId: $synchronizationId,
				originId: $subObject['originId']
			);

			if ($subObjectContract !== null) {
				$subObject['id'] = $subObjectContract->getTargetId();
			}
		}

		return $subObject;
	}
	/**
	 * Write the data to the target
	 *
	 * @param SynchronizationContract $synchronizationContract
	 * @param array|null $targetObject
	 * @param string|null $action Determines what needs to be done with the target object, defaults to 'save'
	 * @param string|null $mutationType If dealing with single object synchronization, the type of the mutation that will be handled, 'create', 'update' or 'delete'. Used for syncs to extern sources.
	 *
	 * @return SynchronizationContract
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 * @throws Exception
	 */
	public function updateTarget(SynchronizationContract $synchronizationContract, ?array &$targetObject = [], ?string $action = 'save', ?string $mutationType = null): SynchronizationContract
	{
		// The function can be called solo set let's make sure we have the full synchronization object
		if (isset($synchronization) === false) {
			$synchronization = $this->synchronizationMapper->find($synchronizationContract->getSynchronizationId());
		}

		// Let's check if we need to create or update
		$update = false;
		if ($synchronizationContract->getTargetId()) {
			$update = true;
		}

		$type = $synchronization->getTargetType();

        if ($mutationType === 'delete') {
            $action = 'delete';
        }

		switch ($type) {
			case 'register/schema':
				$synchronizationContract = $this->updateTargetOpenRegister(synchronizationContract: $synchronizationContract, synchronization: $synchronization, targetObject: $targetObject, action: $action);
				break;
			case 'api':
				$targetConfig = $synchronization->getTargetConfig();
				$synchronizationContract = $this->writeObjectToTarget(synchronization: $synchronization, contract: $synchronizationContract, endpoint: $targetConfig['endpoint'] ?? '', targetObject: $targetObject, mutationType: $mutationType);
				break;
			case 'database':
				//@todo: implement
				break;
			default:
				throw new Exception("Unsupported target type: $type");
		}

		return $synchronizationContract;
	}

	/**
	 * Get all the object from a source
	 *
	 * @param Synchronization $synchronization
	 * @param bool|null $isTest False by default, currently added for synchronziation-test endpoint
	 * @param array|null $data The data to add to synchronize, if not provided, the synchronization's data will be used
	 *
	 * @return array
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
	 * @throws \OCP\DB\Exception
	 */
	public function getAllObjectsFromSource(Synchronization $synchronization, ?bool $isTest = false, ?array $data = null): array
	{
		$objects = [];

		$type = $synchronization->getSourceType();


		switch ($type) {
            case 'register/schema':
                //@todo: implement
				break;
			case 'api':
				$objects = $this->getAllObjectsFromApi(synchronization: $synchronization, isTest: $isTest, data: $data);
				break;
			case 'database':
				//@todo: implement
				break;
		}

		return $objects;
	}

	/**
	 * Fetches all objects from an API source for a given synchronization.
	 *
	 * @param Synchronization $synchronization The synchronization object containing source information.
	 * @param bool|null $isTest If true, only a single object is returned for testing purposes.
	 * @param array|null $data The data to add to synchronize, if not provided, the synchronization's data will be used
	 *
	 * @return array An array of all objects retrieved from the API.
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 */
	public function getAllObjectsFromApi(Synchronization $synchronization, ?bool $isTest = false, ?array $data = null): array
	{
		//@todo this is an nuessesery db call, we should refactor this
		$source = $this->sourceMapper->find($synchronization->getSourceId());

		// Check rate limit before proceeding
		$this->checkRateLimit($source);

		// Extract source configuration
		$sourceConfig = $this->callService->applyConfigDot($synchronization->getSourceConfig()); // TODO; This is the second time this function is called in the synchonysation flow, needs further refactoring investigation
		$endpoint = $sourceConfig['endpoint'] ?? '';
		$headers = $sourceConfig['headers'] ?? [];
		$query = $sourceConfig['query'] ?? [];
        $usesPagination = true;
        if (isset($sourceConfig['usesPagination']) === true) {
            $usesPagination = filter_var($sourceConfig['usesPagination'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if ($sourceConfig['resultsPosition'] === '_object') {
            $usesPagination = false;
        }

		$config = [];
		if (empty($headers) === false) {
			$config['headers'] = $headers;
		}
		if (empty($query) === false) {
			$config['query'] = $query;
		}

		if (isset($sourceConfig['useDataAsRequestBody']) === true) {
			$useDataAsRequestBody = $sourceConfig['useDataAsRequestBody'];
		} else {
			$useDataAsRequestBody = null;
		}

		if ($useDataAsRequestBody === '#') {
			$config['body'] = json_encode($data);
		} else if (empty($useDataAsRequestBody) === false) {
			$config['body'] = json_encode((new Dot($data))->get($useDataAsRequestBody));
		}

		$currentPage = 1;

		// Start with the current page
        if ($source->getRateLimitLimit() !== null) {
            $currentPage = $synchronization->getCurrentPage() ?? 1;
        }

		// Fetch all pages recursively
		$objects = $this->fetchAllPages(
			source: $source,
			endpoint: $endpoint,
			config: $config,
			synchronization: $synchronization,
			currentPage: $currentPage,
			isTest: $isTest,
            usesPagination: $usesPagination
		);

		if (array_is_list($objects) === false) {
			$objects = [$objects];
		}

		// Merge additional data into each object if $data is provided
		if ($data !== null
			&& empty($data) === false
			&& $useDataAsRequestBody === false
		) {
			foreach ($objects as &$object) {
				$object = array_merge($object, $data);
			}
		}

		// Reset the current page after synchronization if not a test
		if ($isTest === false) {
			$synchronization->setCurrentPage(1);
			$this->synchronizationMapper->update($synchronization);
		}

		return $objects;
	}

	/**
	 * Recursively fetches all pages of data from the API.
	 *
	 * @param Source $source The source object containing rate limit and configuration details.
	 * @param string $endpoint The API endpoint to fetch data from.
	 * @param array $config Configuration for the API call (e.g., headers and query parameters).
	 * @param Synchronization $synchronization The synchronization object containing state information.
	 * @param int $currentPage The current page number for pagination.
	 * @param bool $isTest If true, stops after fetching the first object from the first page.
	 * @param bool $usesNextEndpoint If true, doesnt use normal pagination but next endpoint.
	 *
	 * @return array An array of objects retrieved from the API.
	 * @throws GuzzleException
	 * @throws TooManyRequestsHttpException
	 * @throws LoaderError
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 */
	/**
	 * Fetches all pages from a paginated API endpoint with optimized sequential processing.
	 *
	 * This method uses an optimized approach to fetch paginated data more efficiently
	 * than the original recursive implementation, reducing overhead and improving performance.
	 *
	 * @param Source $source The data source configuration
	 * @param string $endpoint The API endpoint to fetch from
	 * @param array $config The request configuration
	 * @param Synchronization $synchronization The synchronization context
	 * @param int $currentPage The starting page number
	 * @param bool $isTest Whether this is a test run (returns only first object)
	 * @param bool|null $usesNextEndpoint Whether the API uses next endpoint URLs
	 * @param bool $usesPagination Whether pagination is enabled
	 *
	 * @return array Combined objects from all pages
	 * @throws TooManyRequestsHttpException When rate limit is exceeded
	 */
	private function fetchAllPages(Source $source, string $endpoint, array $config, Synchronization $synchronization, int $currentPage, bool $isTest = false, ?bool $usesNextEndpoint = null, ?bool $usesPagination = true): array
	{
		// Return objects if we don't paginate
		if ($usesPagination === false) {
			return $this->fetchSinglePage($source, $endpoint, $config, $synchronization);
		}

		// Use optimized sequential fetching (much faster than the original recursive approach)
		return $this->fetchAllPagesOptimized($source, $endpoint, $config, $synchronization, $currentPage, $isTest, $usesNextEndpoint);
	}

	/**
	 * Fetches all pages using an optimized sequential approach.
	 *
	 * This method eliminates the recursive overhead of the original implementation
	 * and uses a simple iterative approach that's much faster and more reliable.
	 *
	 * @param Source $source The data source configuration
	 * @param string $endpoint The API endpoint to fetch from
	 * @param array $config The request configuration
	 * @param Synchronization $synchronization The synchronization context
	 * @param int $currentPage The starting page number
	 * @param bool $isTest Whether this is a test run
	 * @param bool|null $usesNextEndpoint Whether the API uses next endpoint URLs
	 *
	 * @return array Combined objects from all pages
	 * @throws TooManyRequestsHttpException When rate limit is exceeded
	 */
	private function fetchAllPagesOptimized(Source $source, string $endpoint, array $config, Synchronization $synchronization, int $currentPage, bool $isTest = false, ?bool $usesNextEndpoint = null): array
	{
		$allObjects = [];
		$currentEndpoint = $endpoint;
		$sourceConfig = $synchronization->getSourceConfig();
		$maxPages = $sourceConfig['maxPages'] ?? $this::DEFAULT_MAX_PAGES;
		$pageCount = 0;

		for ($i = 0; $i < $maxPages; $i++) {
			// Fetch the current page
			$pageData = $this->fetchSinglePageData($source, $currentEndpoint, $config, $synchronization);
			$pageObjects = $pageData['objects'];
			$pageCount++;

			// If test mode is enabled, return only the first object from the first page
			if ($isTest === true && empty($pageObjects) === false) {
				return [$pageObjects[0]];
			}

			// If no objects found, we've reached the end
			if (empty($pageObjects) === true) {
				break;
			}

			// Add objects to our collection
			$allObjects = array_merge($allObjects, $pageObjects);

			// Determine the next page URL/config
			$nextInfo = $this->getNextPageInfo($source, $currentEndpoint, $config, $synchronization, $currentPage, $pageData['result'], $usesNextEndpoint);

			if ($nextInfo === null) {
				// No more pages
				break;
			}

			// Update for next iteration
			$currentEndpoint = $nextInfo['endpoint'];
			$config = $nextInfo['config'];
			$currentPage = $nextInfo['page'];
			$usesNextEndpoint = $nextInfo['usesNextEndpoint'];

			// Update synchronization current page
			$synchronization->setCurrentPage($currentPage);
			$this->synchronizationMapper->update($synchronization);
		}

		return $allObjects;
	}

	/**
	 * Gets information for the next page in pagination.
	 *
	 * This method determines the next page URL and configuration based on the current
	 * page response and pagination pattern.
	 *
	 * @param Source $source The data source configuration
	 * @param string $currentEndpoint The current page endpoint
	 * @param array $config The current request configuration
	 * @param Synchronization $synchronization The synchronization context
	 * @param int $currentPage The current page number
	 * @param bool|null $usesNextEndpoint Whether the API uses next endpoint URLs
	 *
	 * @return array|null Next page information or null if no more pages
	 */
	private function getNextPageInfo(Source $source, string $currentEndpoint, array $config, Synchronization $synchronization, int $currentPage, array $result, ?bool $usesNextEndpoint = null): ?array
	{
		if (empty($result)) {
			return null;
		}

		// Determine pagination method if not already known
		if ($usesNextEndpoint === null && array_key_exists('next', $result)) {
			$usesNextEndpoint = true;
		}

		if ($usesNextEndpoint === true) {
			// Use next endpoint URL pagination
			$nextEndpoint = $this->getNextEndpoint(body: $result, url: $source->getLocation(), currentEndpoint: $currentEndpoint);
			if ($nextEndpoint === null || $nextEndpoint === $currentEndpoint) {
				return null; // No more pages
			}

			return [
				'endpoint' => $nextEndpoint,
				'config' => $config,
				'page' => $currentPage + 1,
				'usesNextEndpoint' => true
			];
		} else {
			// Use page number pagination
			$nextPage = $currentPage + 1;
			$nextConfig = $this->getNextPage(config: $config, sourceConfig: $synchronization->getSourceConfig(), currentPage: $nextPage);

			return [
				'endpoint' => $currentEndpoint, // Base endpoint stays the same
				'config' => $nextConfig,
				'page' => $nextPage,
				'usesNextEndpoint' => false
			];
		}
	}

	/**
	 * Fetches a single page synchronously.
	 *
	 * This method handles the actual HTTP request and response parsing for a single page,
	 * used both in parallel and sequential fetching scenarios.
	 *
	 * @param Source $source The data source configuration
	 * @param string $endpoint The page endpoint to fetch
	 * @param array $config The request configuration
	 * @param Synchronization $synchronization The synchronization context
	 *
	 * @return array Objects from the page
	 * @throws TooManyRequestsHttpException When rate limit is exceeded
	 */
	private function fetchSinglePage(Source $source, string $endpoint, array $config, Synchronization $synchronization): array
	{
		$pageData = $this->fetchSinglePageData($source, $endpoint, $config, $synchronization);

		return $pageData['objects'];
	}

	/**
	 * Fetches and parses a single page.
	 *
	 * @param Source $source The data source configuration
	 * @param string $endpoint The page endpoint to fetch
	 * @param array $config The request configuration
	 * @param Synchronization $synchronization The synchronization context
	 *
	 * @return array{objects: array, result: array}
	 * @throws TooManyRequestsHttpException When rate limit is exceeded
	 */
	private function fetchSinglePageData(Source $source, string $endpoint, array $config, Synchronization $synchronization): array
	{
		// Make the API call
		$callLog = $this->callService->call(source: $source, endpoint: $endpoint, config: $config);
		$response = $callLog->getResponse();

		// Check for rate limiting
		if ($response === null && $callLog->getStatusCode() === 429) {
			throw new TooManyRequestsHttpException(
				message: "Rate Limit on Source exceeded.",
				code: 429,
				headers: $this->getRateLimitHeaders($source)
			);
		}

		if ($response === null) {
			return ['objects' => [], 'result' => []];
		}

		$body = $response['body'];

		// Try parsing the response body in different formats, starting with JSON
		$result = json_decode($body, true);

		// If JSON parsing failed, try XML
		if (empty($result) === true) {
			libxml_use_internal_errors(true);
			$xml = simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);

			if ($xml !== false) {
				$result = $this->xmlToArray($xml);
			}
		}

		if (empty($result) === true) {
			return ['objects' => [], 'result' => []];
		}

		// Process and return the objects from this page
		return [
			'objects' => $this->getAllObjectsFromArray(array: $result, synchronization: $synchronization),
			'result' => $result
		];
	}

	/**
	 * Fallback method for sequential page fetching.
	 *
	 * This method provides the original sequential fetching behavior as a fallback
	 * when parallel fetching fails or is not suitable.
	 *
	 * @param Source $source The data source configuration
	 * @param string $endpoint The API endpoint to fetch from
	 * @param array $config The request configuration
	 * @param Synchronization $synchronization The synchronization context
	 * @param int $currentPage The starting page number
	 * @param bool $isTest Whether this is a test run
	 * @param bool|null $usesNextEndpoint Whether the API uses next endpoint URLs
	 *
	 * @return array Combined objects from all pages
	 */
	private function fetchAllPagesSequential(Source $source, string $endpoint, array $config, Synchronization $synchronization, int $currentPage, bool $isTest = false, ?bool $usesNextEndpoint = null): array
	{
		$allObjects = [];
		$currentEndpoint = $endpoint;
		$maxPages = 50; // Safety limit

		for ($i = 0; $i < $maxPages; $i++) {
			$pageData = $this->fetchSinglePageData($source, $currentEndpoint, $config, $synchronization);
			$pageObjects = $pageData['objects'];

			// If test mode is enabled, return only the first object
			if ($isTest === true && !empty($pageObjects)) {
				return [$pageObjects[0]];
			}

			if (empty($pageObjects)) {
				break;
			}

			$allObjects = array_merge($allObjects, $pageObjects);

			$result = $pageData['result'];
			if (empty($result)) {
				break;
			}

			// Determine pagination method
			if ($usesNextEndpoint === null && array_key_exists('next', $result)) {
				$usesNextEndpoint = true;
			}

			if ($usesNextEndpoint === true) {
				$nextEndpoint = $this->getNextEndpoint(body: $result, url: $source->getLocation(), currentEndpoint: $currentEndpoint);
				if ($nextEndpoint === null || $nextEndpoint === $currentEndpoint) {
					break;
				}
				$currentEndpoint = $nextEndpoint;
			} else {
				$currentPage++;
				$config = $this->getNextPage(config: $config, sourceConfig: $synchronization->getSourceConfig(), currentPage: $currentPage);
			}
		}

		return $allObjects;
	}


	/**
	 * Checks if the source has exceeded its rate limit and throws an exception if true.
	 *
	 * @param Source $source The source object containing rate limit details.
	 *
	 * @throws TooManyRequestsHttpException
	 */
	private function checkRateLimit(Source $source): void
	{
		if ($source->getRateLimitRemaining() !== null &&
			$source->getRateLimitReset() !== null &&
			$source->getRateLimitRemaining() <= 0 &&
			$source->getRateLimitReset() > time()
		) {
			throw new TooManyRequestsHttpException(
				message: "Rate Limit on Source has been exceeded. Canceling synchronization...",
				code: 429,
				headers: $this->getRateLimitHeaders($source)
			);
		}
	}

	/**
	 * Retrieves rate limit information from a given source and formats it as HTTP headers.
	 *
	 * This function extracts rate limit details from the provided source object and returns them
	 * as an associative array of headers. The headers can be used for communicating rate limit status
	 * in API responses or logging purposes.
	 *
	 * @param Source $source The source object containing rate limit details, such as limits, remaining requests, and reset times.
	 *
	 * @return array An associative array of rate limit headers:
	 *               - 'X-RateLimit-Limit' (int|null): The maximum number of allowed requests.
	 *               - 'X-RateLimit-Remaining' (int|null): The number of requests remaining in the current window.
	 *               - 'X-RateLimit-Reset' (int|null): The Unix timestamp when the rate limit resets.
	 *               - 'X-RateLimit-Used' (int|null): The number of requests used so far.
	 *               - 'X-RateLimit-Window' (int|null): The duration of the rate limit window in seconds.
	 */
	private function getRateLimitHeaders(Source $source): array
	{
		return [
			'X-RateLimit-Limit' => $source->getRateLimitLimit(),
			'X-RateLimit-Remaining' => $source->getRateLimitRemaining(),
			'X-RateLimit-Reset' => $source->getRateLimitReset(),
			'X-RateLimit-Used' => 0,
			'X-RateLimit-Window' => $source->getRateLimitWindow(),
		];
	}

	/**
	 * Updates the API request configuration with pagination details for the next page.
	 *
	 * @param array $config The current request configuration.
	 * @param array $sourceConfig The source configuration containing pagination settings.
	 * @param int $currentPage The current page number for pagination.
	 *
	 * @return array Updated configuration with pagination settings.
	 */
	private function getNextPage(array $config, array $sourceConfig, int $currentPage): array
	{
		$config['pagination'] = [
			'paginationQuery' => $sourceConfig['paginationQuery'] ?? 'page',
			'page' => $currentPage
		];

		return $config;
	}

	/**
	 * Extracts the next API endpoint for pagination from the response body.
	 *
	 * @param array $body The decoded JSON response body from the API.
	 * @param string $url The base URL of the API source.
	 *
	 * @return string|null The next endpoint URL if available, or null if there is no next page.
	 */
	private function getNextEndpoint(array $body, string $url, ?string $currentEndpoint = null): ?string
	{
		$nextLink = $this->getNextlinkFromCall($body);
		if ($nextLink === null || $nextLink === '') {
			return null;
		}

		if (str_starts_with($nextLink, $url)) {
			$nextLink = substr($nextLink, strlen($url));
		}

		// Preserve missing query params from current endpoint (e.g. expand=...)
		// when the API next link only contains paging information.
		if ($currentEndpoint !== null) {
			$nextParts = parse_url($nextLink);
			$currentParts = parse_url($currentEndpoint);

			$nextQuery = [];
			parse_str($nextParts['query'] ?? '', $nextQuery);
			$currentQuery = [];
			parse_str($currentParts['query'] ?? '', $currentQuery);

			foreach ($currentQuery as $key => $value) {
				if (array_key_exists($key, $nextQuery) === false) {
					$nextQuery[$key] = $value;
				}
			}

			$path = $nextParts['path'] ?? $nextLink;
			$query = http_build_query($nextQuery);

			if ($query !== '') {
				return $path.'?'.$query;
			}

			return $path;
		}

		return $nextLink;
	}

	/**
	 * Retrieves the next link for pagination from the API response body.
	 *
	 * @param array $body The decoded JSON body of the API response.
	 *
	 * @return string|null The URL for the next page of results, or null if there is no next page.
	 */
	public function getNextlinkFromCall(array $body): ?string
	{
		return $body['next'] ?? null;
	}

	/**
	 * Extracts all objects from the API response body.
	 *
	 * @param array $array The decoded JSON body of the API response.
	 * @param Synchronization $synchronization The synchronization object containing source configuration.
	 *
	 * @return array An array of items extracted from the response body.
	 * @throws Exception If the position of objects in the return body cannot be determined.
	 */
	public function getAllObjectsFromArray(array $array, Synchronization $synchronization): array
	{
		// Get the source configuration from the synchronization object
		$sourceConfig = $synchronization->getSourceConfig();

		// Check if a specific objects position is defined in the source configuration
		if (empty($sourceConfig['resultsPosition']) === false) {
			$position = $sourceConfig['resultsPosition'];
			// if position is root, return the array
			if ($position === '_root' || $position === '_object') {
				return $array;
			}
			// Use Dot notation to access nested array elements
			$dot = new Dot($array);
			if ($dot->has($position) === true) {
				// Return the objects at the specified position
				return $dot->get($position);
			} else {
				// Throw an exception if the specified position doesn't exist

				return [];
				// @todo log error
				// throw new Exception("Cannot find the specified position of objects in the return body.");
			}
		}

		// Define common keys to check for objects
		$commonKeys = ['items', 'result', 'results'];

		// Loop through common keys and return first match found
		foreach ($commonKeys as $key) {
			if (isset($array[$key]) === true) {
				return $array[$key];
			}
		}

		// If no objects can be found, throw an exception
		throw new Exception("Cannot determine the position of objects in the return body.");
	}

	/**
     * Write an created, updated or deleted object to an external target.
     *
	 * @param Synchronization $synchronization The synchronization to run.
	 * @param SynchronizationContract $contract The contract to enforce.
	 * @param string $endpoint The endpoint to write the object to.
	 * @param array|null $targetObject Update referenced targetObject so we can return response here.
	 * @param string|null $mutationType If dealing with single object synchronization, the type of the mutation that will be handled, 'create', 'update' or 'delete'. Used for syncs to extern sources.
     *
	 * @return SynchronizationContract The updated contract.
     *
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 */
	private function writeObjectToTarget(
		Synchronization         $synchronization,
		SynchronizationContract $contract,
		string                  $endpoint,
        ?array                  &$targetObject = null,
		?string 				$mutationType = null
	): SynchronizationContract
	{
		$targetId = $contract->getTargetId();
		$target = $this->sourceMapper->find(id: $synchronization->getTargetId());

        if ($targetObject !== null) {
            $object = $targetObject;
        }

		$sourceId = $synchronization->getSourceId();
		if ($synchronization->getSourceType() === 'register/schema' && $contract->getOriginId() !== null) {
			$sourceIds = explode(separator: '/', string: $sourceId);

			$this->objectService->getOpenRegisters()->setRegister($sourceIds[0]);
			$this->objectService->getOpenRegisters()->setSchema($sourceIds[1]);

			if ($targetObject === null) {
				$object = $this->objectService->getOpenRegisters()->find(
					id: $contract->getOriginId(),
				)->jsonSerialize();
			}
		}

		$targetConfig = $this->callService->applyConfigDot($synchronization->getTargetConfig());

		if (str_starts_with($endpoint, $target->getLocation()) === true) {
			$endpoint = str_replace(search: $target->getLocation(), replace: '', subject: $endpoint);
		}

		if ($mutationType === 'delete') {
			$method = 'DELETE';

			// @todo check for {{targetId}} in endpoint and replace
			if (isset($targetConfig['deleteEndpoint']) === true) {
				$endpoint = $targetConfig['deleteEndpoint'];
				$endpoint = str_replace(search: '{{ originId }}', replace: $sourceId, subject: $endpoint);
				$endpoint = str_replace(search: '{{originId}}', replace: $sourceId, subject: $endpoint);
			} else {
				$endpoint .= "/$targetId";
			}

			if (isset($targetConfig['deleteMethod']) === true) {
				$method = $targetConfig['deleteMethod'];
			}

			if (isset($targetConfig['deleteMapping']) === true) {
				$deleteMapping = $this->mappingService->getMapping($targetConfig['deleteMapping']);
				$targetConfig['json'] = $this->mappingService->executeMapping(mapping: $deleteMapping, input: $object);
			}

			$response = $this->callService->call(source: $target, endpoint: $endpoint, method: $method, config: $targetConfig)->getResponse();


			$contract->setTargetHash(md5(serialize($response['body'])));
			$contract->setTargetId(null);

			return $contract;
		}

		// @TODO For now only JSON APIs are supported
		$targetConfig['json'] = $object;

		if ($targetId === null) {
            if (isset($targetConfig['idInRequestBody']) === true) {
                $targetId = $targetConfig['json'][$targetConfig['idInRequestBody']];
            }
			$response = $this->callService->call(source: $target, endpoint: $endpoint, method: 'POST', config: $targetConfig)->getResponse();

			$body = json_decode($response['body'], true);

            $bodyDot = new Dot($body);

			if (isset($targetConfig['idPosition']) === true) {
				$targetId = $bodyDot->get($targetConfig['idPosition']);
			} else if (isset($targetConfig['idposition']) === true) {
                // Backwards compatible if older sync still use idposition (lowercase)
				$targetId = $bodyDot->get($targetConfig['idposition']);
            } else if (isset($body['id']) === true) {
				$targetId = $body['id'];
			} else {
					throw new Exception('Could not determine an id from target synchronization');
				}

			$contract->setTargetId($targetId);
			return $contract;
		}

		$method = 'PUT';

		if (isset($targetConfig['updateEndpoint']) === true) {
			$endpoint = $targetConfig['updateEndpoint'];
			$endpoint = str_replace(search: '{{ originId }}', replace: $targetId, subject: $endpoint);
			$endpoint = str_replace(search: '{{originId}}', replace: $targetId, subject: $endpoint);
		} else {
			$endpoint .= "/$targetId";
		}

		if (isset($targetConfig['updateMethod']) === true) {
			$method = $targetConfig['updateMethod'];
		}

		if (isset($targetConfig['updateMapping']) === true) {
        	$mapping = $this->mappingService->getMapping($targetConfig['updateMapping']);
            $targetConfig['json'] = $this->processMapping(mapping: $mapping, data: $targetConfig['json']);
		}

			$response = $this->callService->call(source: $target, endpoint: $endpoint, method: $method, config: $targetConfig)->getResponse();

            $decodedResponseBody = json_decode($response['body'] ?? '', true);
            if (is_array($decodedResponseBody) === false) {
                $decodedResponseBody = [];
            }

			$body = array_merge($decodedResponseBody, ['targetId' => $targetId]);
	        $targetObject = $body;

		return $contract;
	}

	/**
	 * Synchronize data to a target.
	 *
	 * The synchronizationContract should be given if the normal procedure to find the contract (on originId) is not available to the contract that should be updated.
	 *
	 * @param ObjectEntity $object The object to synchronize
	 * @param SynchronizationContract|null $synchronizationContract If given: the synchronization contract that should be updated.
	 * @param bool|null $force If true, the object will be updated regardless of changes
	 * @return array The updated synchronizationContracts
	 *
	 * @throws ContainerExceptionInterface
	 * @throws LoaderError
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 * @throws GuzzleException
	 */
	public function synchronizeToTarget(
		ObjectEntity $object,
		?SynchronizationContract $synchronizationContract = null,
		?bool $force = false,
		?bool $test = false,
		?SynchronizationLog $log = null
	): array
	{
		$objectId = $object->getUuid();

		if ($synchronizationContract === null) {
			$synchronizationContract = $this->synchronizationContractMapper->findByOriginId($objectId);
		}

		$synchronizations = $this->synchronizationMapper->findAll(filters: [
			'source_type' => 'register/schema',
			'source_id' => "{$object->getRegister()}/{$object->getSchema()}",
		]);
		if (count($synchronizations) === 0) {
			return [];
		}

		$synchronization = $synchronizations[0];

		if ($synchronizationContract instanceof SynchronizationContract === false) {
			$synchronizationContract = $this->synchronizationContractMapper->createFromArray([
				'synchronizationId' => $synchronization->getId(),
				'originId' => $objectId,
			]);

		}

		$serializedObject = $object->jsonSerialize();

		$synchronizationContract = $this->synchronizeContract(
			synchronizationContract: $synchronizationContract,
			synchronization: $synchronization,
			object: $serializedObject,
			isTest: $test,
			force: $force,
			log: $log
		);

		if ($synchronizationContract instanceof SynchronizationContract === true) {
			// If this is a regular synchronizationContract update it to the database.
			$synchronizationContract = $this->synchronizationContractMapper->update(entity: $synchronizationContract);
		}

		$synchronizationContract = $this->synchronizationContractMapper->update($synchronizationContract);

		return [$synchronizationContract];

	}

    /**
     * Saves object to OpenRegister
     *
     * @param Rule $rule
     * @param array $data
     *
     * @return array $data
     */
    private function processSaveObjectRule(Rule $rule, array $data): array
    {
        $configuration = $rule->getConfiguration();
        $register = $configuration['save_object']['register'];
        $schema = $configuration['save_object']['schema'];
        $mapping = $configuration['save_object']['mapping'] ?? null;
        $patch = $configuration['save_object']['patch'] ?? false;

		if ($mapping) {

			if (isset($data['_objectBeforeMapping']['id']) === true) {
				$id = $data['_objectBeforeMapping']['id'];
				unset($data['_objectBeforeMapping']);
			}

        	$mapping = $this->mappingService->getMapping($mapping);
            $data = $this->processMapping(mapping: $mapping, data: $data);
		}

        $objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
		if ($patch === true || $patch === 'true') {
            $object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find($id);
			$data = array_merge($object->getObject(), ['id' => $object->getId()], $data);
		}

		$object = $objectService->saveObject(register: $register, schema: $schema, object: $data)->jsonSerialize();

		return $object;
    }

    /**
     * Extends input for performing business logic
     *
     * @param array $config The rule configuration which parameters could be extended
     * @param array $data The data array containing the input parameters.
     *
     * @return array The data array with the extended parameters in the 'extendedParameters' key.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function processExtendInputRule(array $config, array $data): array
    {
        $parameters = new Dot($data);
        $extendedParameters = new Dot();

        // If the extends are given as a comma separated string, separate them into an array.
        if (is_string($config['extend_input']['properties']) === true) {
            $config['extend_input']['properties'] = explode(separator: ',', string: $config['extend_input']['properties']);
        }

        if (isset($data['id']) === true || isset($data['@self']['id']) === true || $data['uuid'] === true) {
            $id = $data['@self']['id'] ?? $data['id'] ?? $data['uuid'];
        }

        // If we can fetch the object to extend again, use OpenRegister to fetch the extended object.
        if (isset($id) === true && isset($config['extend_input']['fetchObject']) === true && ($config['extend_input']['fetchObject'] === true || $config['extend_input']['fetchObject'] === 'true')) {
            $object = $this->objectService->getOpenRegisters()->find(id: $id, extend: $config['extend_input']['properties']);
            return $object->jsonSerialize();
        }

        foreach ($config['extend_input']['properties'] as $property) {
            $value = $parameters->get($property);

            if(filter_var($value, FILTER_VALIDATE_URL) !== false) {
                $exploded = explode(separator: '/', string: $value);
                $value = end($exploded);
            }

            try {
                $object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find(identifier: $value);
            } catch (DoesNotExistException $exception) {
                continue;
            }
            $extendedParameters->add($property, $object->jsonSerialize());

        }

        return array_merge($data, $extendedParameters->all());
    }

	/**
	 * Processes rules for an endpoint request
	 *
	 * @param Synchronization $synchronization The endpoint being processed
	 * @param array $data Current request data
	 * @param string $timing
	 * @param string|null $objectId
	 * @param int|null $registerId
	 * @param int|null $schemaId
	 *
	 * @return array|JSONResponse Returns modified data or error response if rule fails
	 * @throws ContainerExceptionInterface
	 * @throws GuzzleException
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 */
    private function processRules(Synchronization $synchronization, array $data, string $timing, ?string $objectId = null, ?int $registerId = null, ?int $schemaId = null, ?FlowToken $flowToken = null): array|JSONResponse
    {
        $rules = $synchronization->getActions();
        if (empty($rules) === true) {
            return $data;
        }

        try {
            // Get all rules at once and sort by order
            $ruleEntities = array_filter(
                array_map(
                    fn($ruleId) => $this->getRuleById($ruleId),
                    $rules
                )
            );

            // Sort rules by order
            usort($ruleEntities, fn($a, $b) => $a->getOrder() - $b->getOrder());

            // Process each rule in order
            foreach ($ruleEntities as $rule) {
                if($flowToken !== null) {
                    $data['flowToken'] = $flowToken->__serialize();
                }

                // Check rule conditions
                if ($this->checkRuleConditions($rule, $data) === false || $rule->getTiming() !== $timing) {
                    $this->logger->info('Rule condition check failed for synchronization ' . $synchronization->getName() . ' and rule ' . $rule->getName() . ' of type: ' . $rule->getType());
                    unset ($data['flowToken']);
                    continue;
                }
                unset ($data['flowToken']);

                $this->logger->info('Applying rule for synchronization ' . $synchronization->getName() . ' with rule ' . $rule->getName() . ' of type ' . $rule->getType());

                // Process rule based on type
                $result = match ($rule->getType()) {
                    'error' => $this->processErrorRule($rule),
                    'mapping' => $this->processMappingRule($rule, $data),
                    'synchronization' => $this->processSyncRule($rule, $data),
                    'save_object' => $this->processSaveObjectRule($rule, $data),
                    'fetch_file' => $this->processFetchFileRule($rule, $data, $objectId),
                    'write_file' => $this->processWriteFileRule($rule, $data, $objectId, $registerId, $schemaId),
                    'extend_input' => $this->processExtendInputRule(config: $rule->getConfig(), data: $data),
                    default => throw new Exception('Unsupported rule type: ' . $rule->getType()),
                };

                // If result is JSONResponse, return error immediately
                if ($result instanceof JSONResponse) {
                    return $result;
                }

                // Update data with rule result
                $data = $result;

                $this->logger->info('Successfully applied rule for synchronization ' . $synchronization->getName() . ' with rule ' . $rule->getName() . ' of type ' . $rule->getType());
            }

            return $data;
        } catch (Exception $e) {
            $this->logger->error('Error processing rules for synchronization ' . $synchronization->getName() . ': ' . $e->getMessage());
            return new JSONResponse(['error' => 'Rule processing failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get a rule by its ID using RuleMapper
     *
     * @param string $id The unique identifier of the rule
     *
     * @return Rule|null The rule object if found, or null if not found
     */
    private function getRuleById(string $id): ?Rule
    {
        try {
            return $this->ruleMapper->find((int)$id);
        } catch (Exception $e) {
//            $this->logger->error('Error fetching rule: ' . $e->getMessage());
            return null;
        }
    }

	/**
	 * Write a file to the filesystem
	 *
	 * @param string $fileName The filename
	 * @param string $content The content of the file
	 * @param string $objectId The id of the object the file belongs to.
	 *
	 * @return File|bool File or false.
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws GenericFileException
	 * @throws LockedException
	 */
    private function writeFile(string $fileName, string $content, string $objectId): mixed
    {
        $object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find($objectId);

        try {
            $file = $this->storageService->writeFile(
                path: $object->getFolder(),
                fileName: $fileName,
                content: $content
            );
        } catch (NotFoundException|NotPermittedException|NoUserException $e) {
            return false;
        }

        return $file;
    }

	/**
	 * Fetch a file from a source.
	 *
	 * @param Source $source The source to fetch the file from.
	 * @param string $endpoint The endpoint for the file.
	 * @param array $config The configuration of the action.
	 * @param string $objectId The id of the object the file belongs to.
	 * @param int| $objectId The id of the object the file belongs to.
     * @param array $tags Tags to assign to the file.
     * @param string|null $filename Filename to assign to the file.
	 * @param int|string|null $registerId The id of the register the object belongs to.
	 *
	 * @return string If write is enabled: the url of the file, if write is disabled: the base64 encoded file.
	 * @throws ContainerExceptionInterface
	 * @throws GenericFileException
	 * @throws GuzzleException
	 * @throws LoaderError
	 * @throws LockedException
	 * @throws NotFoundExceptionInterface
	 * @throws SyntaxError
	 * @throws \OCP\DB\Exception
	 */
	private function fetchFile(Source $source, string $endpoint, array $config, string $objectId, ?array $tags = [], ?string &$filename = null, ?string $published = null, int|string|null $registerId = null): string
	{

		$originalEndpoint = $endpoint;
		$endpoint = str_contains(haystack: $endpoint, needle: $source->getLocation()) === true
			? substr(string: $endpoint, offset: strlen(string: $source->getLocation()))
			: $endpoint;

		$sourceConfig = json_encode($config['sourceConfiguration']);
		if (isset($config['originId']) === true) {
			$sourceConfig = str_replace(search: "{{ originId }}", replace: $config['originId'], subject: $sourceConfig);
		}
		$sourceConfig = json_decode($sourceConfig, true);

        if(isset($sourceConfig['body']) === true
            || (isset($config['method']) === true && $config['method'] !== 'GET')) {
            $sourceConfig['body'] = json_encode($sourceConfig['body'] ?? []);
        }

		$config['sourceConfiguration'] = $sourceConfig;

		$result = $this->callService->call(
			source: $source,
			endpoint: $endpoint,
			method: $config['method'] ?? 'GET',
			config: $config['sourceConfiguration'] ?? []
		);
		$response = $result->getResponse();

		$body = $response['body'];


		if (($decodedBody = json_decode(json: $body, associative: true)) !== null
            && isset($response['headers']['Content-Disposition']) === false) {
			$body = $decodedBody;
		} else if (($decodedBody = base64_decode(string: $body, strict: true)) !== false) {
			$body = $decodedBody;
		}

		if (isset($config['contentPath']) === true && empty($config['contentPath']) === false) {
			$content = base64_decode((new Dot($body))->get($config['contentPath']));
		}
		if (isset($config['filenamePath']) === true && empty($config['filenamePath']) === false) {
			$filename = (new Dot($body))->get($config['filenamePath']);
		}

		if (isset($config['fileExtension']) === true && empty($config['fileExtension']) === false) {
			$filename = $filename.$config['fileExtension'];
		}

		// Check if response is valid
		if ($response === null) {
			throw new Exception("Failed to fetch file from endpoint: {$originalEndpoint}. No response received.");
		}

		if (isset($config['write']) === true && $config['write'] === false) {
            return base64_encode($body);
        }

		if ($filename === null) {
            // Get a filename from the response. First try to do this using the Content-Disposition header
            $filename = $this->getFilenameFromHeaders(response: $response, result: $result);
        }

		if ($filename === null) {
            throw new Exception("Could not write file from endpoint {$originalEndpoint}: no filename could be determined");
        }

		// Validate objectId format (should be a UUID)
		if (empty($objectId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $objectId)) {
			throw new Exception("Invalid object ID format: {$objectId}. Expected a valid UUID.");
		}
        $fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');

		if (isset($content) === false) {
			$content = $body;
		}

        $shouldShare = !empty($tags) && isset($config['autoShare']) ? $config['autoShare'] : false;

		// Determine if file should be published based on the published parameter
		$shouldPublish = $this->shouldPublishFile($published);

		try {
			$objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
			$objectEntity = $objectService->findByUuid(uuid: $objectId);
			$file = $fileService->saveFile(
				objectEntity: $objectEntity,
				fileName: $filename,
				content: $content,
				share: $shouldShare,
				tags: $tags
			);

			// Publish the file if needed
			if ($shouldPublish && $file !== null) {
				try {
					$fileService->publishFile(object: $objectEntity, file: $filename);
				} catch (Exception $e) {
					// Log but don't fail the entire operation
					error_log("Failed to publish file {$filename} for object {$objectId}: " . $e->getMessage());
				}
			}
		} catch (DoesNotExistException $exception) {
			// If the object cannot be found, continue with register/schema/objectId combination
			$register = $config['register'] ?? null;
			$schema   = $config['schema'] ?? null;
			$file = $fileService->addFile(objectEntity: $objectId, fileName: $filename, content: $response['body'], share: isset($config['autoShare']) ? $config['autoShare'] : false, tags: $tags, register: $register, schema: $schema, registerId: $registerId);

			// For the addFile case, we'll need to get the object entity to publish
			if ($shouldPublish && $file !== null) {
				try {
					$objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
					$objectEntity = $objectService->findByUuid(uuid: $objectId);
					$fileService->publishFile(object: $objectEntity, file: $filename);
				} catch (Exception $e) {
					// Log but don't fail the entire operation
					error_log("Failed to publish file {$filename} for object {$objectId}: " . $e->getMessage());
				}
			}
		} catch (Exception $e) {
			throw new Exception("Failed to save file {$filename} for object {$objectId}: " . $e->getMessage());
		}

		return $originalEndpoint;
	}

    private function getFilenameFromHeaders(array $response, CallLog $result): ?string
    {
        $filename = null;
        // Get a filename from the response. First try to do this using the Content-Disposition header
		if (isset($response['headers']['Content-Disposition']) === true
		&& str_contains($response['headers']['Content-Disposition'][0], 'filename')) {
		$explodedContentDisposition = explode('=', $response['headers']['Content-Disposition'][0]);

		 $filename = trim(string: $explodedContentDisposition[1], characters: '"');
		} else {
			// Otherwise, parse the url and content type header.
			$parsedUrl = parse_url($result->getRequest()['url']);
			$path = explode(separator:'/', string: $parsedUrl['path']);
			$filename = end($path);

			if (count(explode(separator: '.', string: $filename)) === 1
				&& (isset($response['headers']['Content-Type']) === true || isset($response['headers']['content-type']) === true)
			) {
				$explodedMimeType = isset($response['headers']['Content-Type']) === true
					? explode(separator: '/', string: explode(separator: ';', string: $response['headers']['Content-Type'][0])[0])
					: explode(separator: '/', string: explode(separator: ';', string: $response['headers']['content-type'][0])[0]);


				$filename = $filename.'.'.end($explodedMimeType);
			}
		}

        return $filename;
    }

	/**
	 * Extracts an endpoint from the given data and optionally retrieves a filename and tags.
	 *
	 * This function checks if a sub-object file path exists in the configuration and retrieves
	 * the relevant endpoint using dot notation. It also extracts filename and tag information
	 * if available.
	 *
	 * @param array  $config   The configuration array, which may include 'subObjectFilepath', 'tags', 'useLabelsAsTags', and 'allowedLabels'.
	 * @param mixed  $endpoint The data containing the endpoint, which can be a string or an array.
	 * @param string|null &$filename A reference to the filename (if available) that will be updated.
	 * @param array|null  &$tags     A reference to an array of tags (if available) that will be updated.
	 * @param string|null  &$objectId     A reference to the object id (if available) that the file will be attached to.
	 * @param string|null  &$published     A reference to the published status (if available) that will be updated.
	 * @param int|string|null  &$registerId     A reference to the registerId (if available) that will be updated.
	 *
	 * @return string The extracted endpoint from the data.
	 */
	private function getFileContext(array $config, mixed $endpoint, ?string &$filename = null, ?array &$tags = [], ?string &$objectId = null, ?string &$published = null, int|string|null &$registerId = null)
	{
		$dataDot = new Dot($endpoint);
		if (isset($config['objectIdPath']) === true && empty($config['objectIdPath']) === false) {
			$objectId = $dataDot->get($config['objectIdPath']);
		}

		if (isset($config['subObjectFilepath']) === true && empty($config['subObjectFilepath']) === false) {
			$endpoint = $dataDot->get($config['subObjectFilepath']);
		}

		if (is_array($endpoint) === true) {
			// Handle labels/tags with support for multiple property names
			$extractedTags = [];

			// Check for various tag/label property names and extract values
			$tagProperties = ['label', 'labels', 'tag', 'tags'];
			foreach ($tagProperties as $property) {
				if (isset($endpoint[$property]) === true && !empty($endpoint[$property])) {
					$value = $endpoint[$property];

					// Handle both single values and arrays
					if (is_array($value)) {
						$extractedTags = array_merge($extractedTags, array_filter($value, function($item) {
							return !empty($item) && is_string($item);
						}));
					} elseif (is_string($value) && !empty($value)) {
						$extractedTags[] = $value;
					}
				}
			}

			// Remove duplicates and apply tag filtering logic
			$extractedTags = array_unique($extractedTags);

			// Check if we have meaningful tag configuration
			$hasUseLabelsAsTags = isset($config['useLabelsAsTags']) && $config['useLabelsAsTags'] === true;
			$hasAllowedLabels = isset($config['allowedLabels']) && is_array($config['allowedLabels']) && !empty($config['allowedLabels']);
			$hasLegacyTags = isset($config['tags']) && is_array($config['tags']) && !empty($config['tags']);
			$hasMeaningfulTagConfig = $hasUseLabelsAsTags || $hasAllowedLabels || $hasLegacyTags;

			foreach ($extractedTags as $tagValue) {
				// If useLabelsAsTags is explicitly enabled, always use the tag
				if ($hasUseLabelsAsTags) {
					$tags[] = $tagValue;
				}
				// If config has specific allowed labels, check if this tag is allowed
				elseif ($hasAllowedLabels && in_array($tagValue, $config['allowedLabels'], true)) {
					$tags[] = $tagValue;
				}
				// Legacy behavior - if config has non-empty tags array and tag is in it
				elseif ($hasLegacyTags && in_array($tagValue, $config['tags'], true)) {
					$tags[] = $tagValue;
				}
				// If no meaningful tag configuration is provided, use all tags (default behavior)
				elseif (!$hasMeaningfulTagConfig) {
					$tags[] = $tagValue;
				}
			}

			// Extract filename if available
			if (isset($endpoint['filename']) === true && empty($endpoint['filename']) === false) {
				$filename = $endpoint['filename'];
			}

			// Extract published status if available
			if (isset($endpoint['published']) === true) {
				$published = $endpoint['published'];
			}

			// Extract published status if available
			if (isset($endpoint['registerId']) === true) {
				$registerId = $endpoint['registerId'];
			}

			// Check if endpoint exists before returning it
			if (isset($endpoint['endpoint']) === true) {
				return $endpoint['endpoint'];
			}

			// If no endpoint is found, return null
			return null;
		}

		return $endpoint;
	}

	/**
	 * Determines the type of a given array.
	 *
	 * This function identifies whether the given input is:
	 * - Not an array
	 * - An associative array (keys are not sequential numeric values)
	 * - A multidimensional array (contains nested arrays)
	 * - A simple indexed array (sequential numeric keys)
	 *
	 * @param mixed $array The input to be checked.
	 *
	 * @return string A string indicating the type of the array:
	 *                "Not array", "Associative array", "Multidimensional array", or "Indexed array".
	 */
	private function getArrayType(mixed $array): string
	{
		// Check if not array
		if (is_array($array) === false) {
			return "Not array";
		}

        if ($array === []) {
            return 'Empty array';
        }

		// Check for an associative array
		if (array_keys($array) !== range(0, count($array) - 1)) {
			return "Associative array";
		}

		// Check for a multidimensional array
		if (count($array) !== count($array, COUNT_RECURSIVE)) {
			return "Multidimensional array";
		}

		// Otherwise, it's an indexed array
		return "Indexed array";
	}


	/**
	 * Process a rule to fetch a file from an external source using fire-and-forget ReactPHP execution.
	 *
	 * This method initiates file fetching operations asynchronously without blocking the main execution flow.
	 * The actual file fetching happens in the background, allowing the synchronization to continue immediately.
	 *
	 * @param Rule $rule The rule to process containing fetch_file configuration.
	 * @param array $data The data written to the object.
	 * @param string|null $objectId The UUID of the object to attach files to.
	 *
	 * @return array The resulting object data with placeholder values for file paths.
	 * @throws Exception If OpenRegister app is not available or configuration is missing.
	 *
	 * @psalm-return array<string, mixed>
	 * @phpstan-return array<string, mixed>
	 */
	private function processFetchFileRule(Rule $rule, array $data, ?string $objectId = null): array
	{
        // Check if OpenRegister app is available
        $appManager = \OC::$server->get(\OCP\App\IAppManager::class);
        if ($appManager->isEnabledForUser('openregister') === false) {
			throw new Exception('OpenRegister app is required for the fetch file rule and not installed');
        }

        // Validate rule configuration
		if (isset($rule->getConfiguration()['fetch_file']) === false) {
			throw new Exception('No configuration found for fetch_file');
		}

		$config = $rule->getConfiguration()['fetch_file'];

		$dataDot = new Dot($data);
        $endpoint = isset($config['filePath']) === true && $config['filePath'] !== '' ? $dataDot->get($config['filePath']) : $config['endpoint'];

		if ($objectId === null && isset($config['objectIdPath']) === true) {
			$objectId = $dataDot->get($config['objectIdPath']);
		}

		if (isset($config['originIdPath']) === true) {
			$config['originId'] = $dataDot->get($config['originIdPath']);
		}


        // If no endpoint is found, return data unchanged
        if ($endpoint === null) {
            return $dataDot->jsonSerialize();
        }

        // Get source for file fetching
        try {
            $source = $this->sourceMapper->find($config['source']);
        } catch (Exception $e) {
            // Log error but don't block synchronization
            error_log("Failed to find source for fetch file rule: " . $e->getMessage());
            return $dataDot->jsonSerialize();
        }

		// $filename = null;
		// $tags = [];
		// $published = null;
        // $registerId = null;

//		switch ($this->getArrayType($endpoint)) {
//			// Single file endpoint
//			case 'Not array':
//				$this->fetchFile(source: $source, endpoint: $endpoint, config: $config, objectId: $objectId, tags: $tags, published: $published);
//				break;
//			// Array of object that has file(s)
//			case 'Associative array':
//				$actualEndpoint = $this->getFileContext(config: $config, endpoint: $endpoint, filename: $filename, tags: $tags, objectId: $objectId, published: $published, registerId: $registerId);
//
//				if ($actualEndpoint === null) {
//                    return $dataDot->jsonSerialize();
//				}
//				$this->fetchFile(source: $source, endpoint: $actualEndpoint, config: $config, objectId: $objectId, registerId: $registerId, tags: $tags, filename: $filename, published: $published);
//				break;
//			// Array of object(s) that has file(s)
//			case "Multidimensional array":
//				foreach ($endpoint as $object) {
//					$filename = null;
//					$tags = [];
//					$published = null;
//					$registerId = null;
//					$actualEndpoint = $this->getFileContext(config: $config, endpoint: $object, filename: $filename, tags: $tags, objectId: $objectId, published: $published, registerId: $registerId);
//					if ($actualEndpoint === null) {
//                        continue;
//					}
//					$this->fetchFile(source: $source, endpoint: $actualEndpoint, config: $config, objectId: $objectId, registerId: $registerId, tags: $tags, filename: $filename, published: $published);
//				}
//				break;
//			// Array of just endpoints
//			case "Indexed array":
//				foreach ($endpoint as $key => $childEndpoint) {
//					$filename = null;
//					$tags = [];
//					$published = null;
//					$registerId = null;
//					$this->fetchFile(source: $source, endpoint: $childEndpoint, config: $config, objectId: $objectId, registerId: $registerId, tags: $tags, published: $published);
//				}
//				break;
//		}

        // Start fire-and-forget file fetching based on endpoint type
        $this->startAsyncFileFetching(source: $source, config: $config, endpoint: $endpoint, ruleId: $rule->getId(), objectId: $objectId);

        // Return data immediately with placeholder values
        if (isset($config['setPlaceholder']) === false || (isset($config['setPlaceholder']) === true && $config['setPlaceholder'] != false)) {
            $dataDot[$config['filePath']] = $this->generatePlaceholderValues($endpoint);
        }

		return $dataDot->jsonSerialize();
	}

	/**
	 * Starts asynchronous file fetching operations using ReactPHP promises.
	 *
	 * This method creates fire-and-forget promises that handle file fetching in the background
	 * without blocking the main synchronization process.
	 *
	 * @param Source $source The source to fetch files from.
	 * @param array $config The fetch_file rule configuration.
	 * @param mixed $endpoint The endpoint(s) to fetch files from.
	 * @param int $ruleId The ID of the rule for error logging.
	 * @param string|null $objectId The UUID of the object to attach files to.
	 *
	 * @return void
	 *
	 * @psalm-param array<string, mixed> $config
	 */
	private function startAsyncFileFetching(Source $source, array $config, mixed $endpoint, int $ruleId, ?string $objectId = null): void
	{
        // Execute file fetching immediately but with error isolation
        // This provides "fire-and-forget" behavior without complex ReactPHP setup
        $this->executeAsyncFileFetching(source: $source, config: $config, endpoint: $endpoint, ruleId: $ruleId, objectId: $objectId);
	}

	/**
	 * Executes the actual file fetching operations asynchronously.
	 *
	 * This method handles different types of endpoints (single, associative array, multidimensional array, indexed array)
	 * and fetches files accordingly. All operations are wrapped in try-catch blocks to prevent errors from
	 * affecting the main synchronization process.
	 *
	 * @param Source $source The source to fetch files from.
	 * @param array $config The fetch_file rule configuration.
	 * @param mixed $endpoint The endpoint(s) to fetch files from.
	 * @param int $ruleId The ID of the rule for error logging.
	 * @param string|null $objectId The UUID of the object to attach files to.
	 *
	 * @return void
	 *
	 * @psalm-param array<string, mixed> $config
	 */
	private function executeAsyncFileFetching(Source $source, array $config, mixed $endpoint, int $ruleId, ?string $objectId = null): void
	{
        try {
            $filename = null;
            $tags = [];
            $published = null;
            $registerId = null;

            switch ($this->getArrayType($endpoint)) {
                // Single file endpoint
                case 'Not array':
                    $this->fetchFileSafely($source, $endpoint, $config, $objectId);
                    break;

                // Array of object that has file(s)
                case 'Associative array':
                    $contextObjectId = null; // Separate variable to avoid overwriting the original
                    $actualEndpoint = $this->getFileContext(config: $config, endpoint: $endpoint, filename: $filename, tags: $tags, objectId: $contextObjectId, published: $published, registerId: $registerId);
                    // Use context object ID if specified, otherwise fall back to the original object ID
                    $targetObjectId = $contextObjectId ?? $objectId;
                    if ($actualEndpoint !== null) {
                        $this->fetchFileSafely(source: $source, endpoint: $actualEndpoint, config: $config, objectId: $targetObjectId, filename: $filename, tags: $tags, published: $published, registerId: $registerId);
                    }
                    break;

                    // Array is empty
                case 'Empty array':
                // Array of object(s) that has file(s) - use cleanup logic
                case "Multidimensional array":
                // Array of just endpoints - use cleanup logic
                case "Indexed array":
                    $this->processMultipleFilesWithCleanup($source, $config, $endpoint, $objectId);
                    break;
            }
        } catch (Exception $e) {
            // Log error but don't throw - this is fire-and-forget
            error_log("Async file fetching failed for rule {$ruleId}: " . $e->getMessage());
        }
	}

	/**
	 * Fetches a single file with comprehensive error handling.
	 *
	 * This method wraps the existing fetchFile method with error isolation to enable
	 * fire-and-forget execution. Errors are caught and logged without affecting the main process.
	 *
	 * @param Source $source The source to fetch the file from.
	 * @param string $endpoint The endpoint for the file.
	 * @param array $config The configuration of the action.
	 * @param string $objectId The UUID of the object the file belongs to.
	 * @param string|null $filename Optional filename to assign to the file.
	 * @param array $tags Optional tags to assign to the file.
	 * @param string|null $published Optional published status to determine if file should be published.
	 * @param int|string|null $registerId Optional published status to determine if file should be published.
	 *
	 * @return void
	 *
	 * @psalm-param array<string, mixed> $config
	 * @psalm-param array<string> $tags
	 */
	private function fetchFileSafely(Source $source, string $endpoint, array $config, string $objectId, ?string $filename = null, array $tags = [], int|string|null $published = null, int|string|null $registerId = null): void
	{
        try {
            // Execute the file fetching operation
            $result = $this->fetchFile(
                source: $source,
                endpoint: $endpoint,
                config: $config,
                objectId: $objectId,
                tags: $tags,
                filename: $filename,
                published: $published,
                registerId: $registerId
            );
        } catch (Exception $e) {
            // Log error with detailed information but don't throw
            error_log("File fetch failed for endpoint {$endpoint}, objectId {$objectId}: " . $e->getMessage());
        }
	}

	/**
	 * Generates placeholder values for file paths based on endpoint type.
	 *
	 * This method creates appropriate placeholder values that match the expected structure
	 * of the file paths, allowing the synchronization to continue with meaningful placeholders
	 * while files are being fetched asynchronously.
	 *
	 * @param mixed $endpoint The endpoint(s) to generate placeholders for.
	 *
	 * @return mixed Placeholder values matching the endpoint structure.
	 */
	private function generatePlaceholderValues(mixed $endpoint): mixed
	{
        switch ($this->getArrayType($endpoint)) {
            case 'Not array':
                return 'file://fetching-async';

            case 'Associative array':
                return 'file://fetching-async';

            case "Multidimensional array":
                return array_fill(0, count($endpoint), 'file://fetching-async');

            case "Indexed array":
                return array_fill(0, count($endpoint), 'file://fetching-async');

            default:
                return 'file://fetching-async';
        }
	}

	/**
	 * Process a rule to write files.
	 *
	 * @param Rule $rule The rule to process.
	 * @param array $data The data to write.
	 * @param string $objectId The object to write the data to.
	 * @param int $registerId The register the object is in.
	 * @param int $schemaId The schema the object is in.
	 *
	 * @return array
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 */
    private function processWriteFileRule(Rule $rule, array $data, string $objectId, int $registerId, int $schemaId): array
    {
        if (isset($rule->getConfiguration()['write_file']) === false) {
            throw new Exception('No configuration found for write_file');
        }

        $config  = $rule->getConfiguration()['write_file'];
        $dataDot = new Dot($data);
        $files = $dataDot[$config['filePath']];
        if (isset($files) === false || empty($files) === true) {
            return $dataDot->jsonSerialize();
        }

        // Get the object entity and file service
        $objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
        $objectEntity = $objectService->findByUuid(uuid: $objectId);
        $fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');

        // Check if associative array (multiple files with metadata)
        if (is_array($files) === true && isset($files[0]) === true && array_keys($files[0]) !== range(0, count($files[0]) - 1)) {
            $result = [];
			foreach ($files as $key => $value) {
                $content = '';
                $fileName = '';
                $tags = [];

                // Extract file data
                if (is_array($value) === true) {
                    $content = $value['content'];
                    $fileName = $value['filename'] ?? "file_$key";

                    // Handle tags from config and value labels
                    if (isset($value['label']) === true && isset($config['tags']) === true &&
                        in_array(needle: $value['label'], haystack: $config['tags']) === true) {
                        $tags = [$value['label']];
                    }
                } else {
                    $content = $value;
                    $fileName = "file_$key";
                }

                // Merge with configured tags
                $allTags = array_unique(array_merge($config['tags'] ?? [], $tags));

                // Determine if we should share the file - only if there are user-defined tags
                $shouldShare = !empty($allTags);

                try {
                    // Use the new saveFile method
                    $file = $fileService->saveFile(
                        objectEntity: $objectEntity,
                        fileName: $fileName,
                        content: $content,
                        share: $shouldShare,
                        tags: $allTags
                    );

                    $result[$key] = $file->getPath();
                } catch (Exception $exception) {
                    error_log("Failed to save file $fileName: " . $exception->getMessage());
                    $result[$key] = null;
                }
            }
            $dataDot[$config['filePath']] = $result;
        } else {
            // Single file case
            $content = $files;
            $fileName = $dataDot[$config['fileNamePath']] ?? 'default_file';

            // Get configured tags
            $tags = $config['tags'] ?? [];

            // Determine if we should share the file - only if there are user-defined tags
            $shouldShare = !empty($tags);

            try {
                // Use the new saveFile method
                $file = $fileService->saveFile(
                    objectEntity: $objectEntity,
                    fileName: $fileName,
                    content: $content,
                    share: $shouldShare,
                    tags: $tags
                );

                $dataDot[$config['filePath']] = $file->getPath();
            } catch (Exception $exception) {
                error_log("Failed to save file $fileName: " . $exception->getMessage());
                $dataDot[$config['filePath']] = null;
            }
        }

        return $dataDot->jsonSerialize();
    }



    /**
     * Processes an error rule
     *
     * @param Rule $rule The rule object containing error details
     *
     * @return JSONResponse Response containing error details and HTTP status code
     */
    private function processErrorRule(Rule $rule): JSONResponse
    {
        $config = $rule->getConfiguration();
        return new JSONResponse(
            [
                'error' => $config['error']['name'],
                'message' => $config['error']['message']
            ],
            $config['error']['code']
        );
    }

    /**
     * Processes a mapping rule
     *
     * @param Rule $rule The rule object containing mapping details
     * @param array $data The data to be processed through the mapping rule
     *
     * @return array The processed data after applying the mapping rule
     * @throws DoesNotExistException When the mapping configuration does not exist
     * @throws MultipleObjectsReturnedException When multiple mapping objects are returned unexpectedly
     * @throws LoaderError When there is an error loading the mapping
     * @throws SyntaxError When there is a syntax error in the mapping configuration
     */
    private function processMappingRule(Rule $rule, array $data): array
    {
        $config = $rule->getConfiguration();
        $mapping = $this->mappingService->getMapping($config['mapping']);

        return $this->processMapping(mapping: $mapping, data: $data);
    }

    /**
     * Executes mapping on data from endpoint flow
     *
     * @param mapping $mapping
     * @param array $data
     *
     * @return array $data
     */
    private function processMapping(Mapping $mapping, array $data): array
    {
        return $this->mappingService->executeMapping($mapping, $data);
    }

    /**
     * Processes a synchronization rule
     *
     * @param Rule $rule The rule object containing synchronization details
     * @param array $data The data to be synchronized
     *
     * @return array The data after synchronization processing
     */
    private function processSyncRule(Rule $rule, array $data): array
    {
        $config = $rule->getConfiguration();
        // Here you would implement the synchronization logic
        // For now, just return the data unchanged
        return $data;
    }

    /**
     * Checks if rule conditions are met
     *
     * @param Rule $rule The rule object containing conditions to be checked
     * @param array $data The input data against which the conditions are evaluated
     *
     * @return bool True if conditions are met, false otherwise
     * @throws Exception
     */
    private function checkRuleConditions(Rule $rule, array $data): bool
    {
        $conditions = $rule->getConditions();
        if (empty($conditions) === true) {
            return true;
        }

        return JsonLogic::apply($conditions, $data) === true;
    }

    /**
     * Replaces strings in array keys, helpful for characters like . in array keys.
     *
     * @param array  $array       The array to encode the array keys for.
     * @param string $toReplace   The character to encode.
     * @param string $replacement The encoded character.
     *
     * @return array The array with encoded array keys
     */
    public function encodeArrayKeys(array $array, string $toReplace, string $replacement): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = str_replace($toReplace, $replacement, $key);

            if (is_array($value) === true && $value !== []) {
                $result[$newKey] = $this->encodeArrayKeys($value, $toReplace, $replacement);
                continue;
            }

            $result[$newKey] = $value;
        }

        return $result;

    }//end encodeArrayKeys()

	/**
	 * Convert SimpleXMLElement to array while preserving namespaced attributes
	 *
	 * @param \SimpleXMLElement $xml The XML element to convert
	 * @return array The array representation with preserved namespaced attributes
	 */
	private function xmlToArray(\SimpleXMLElement $xml): array
	{
		$result = [];

		// Handle attributes - this preserves namespaced attributes with colons
		$attributes = $xml->attributes();
		if (count($attributes) > 0) {
			$result['@attributes'] = [];
			foreach ($attributes as $attrName => $attrValue) {
				$result['@attributes'][(string)$attrName] = (string)$attrValue;
			}
		}

		// Handle namespaced attributes
		$namespaces = $xml->getNamespaces(true);
		foreach ($namespaces as $prefix => $namespace) {
			$nsAttributes = $xml->attributes($namespace);
			if (count($nsAttributes) > 0) {
				if (!isset($result['@attributes'])) {
					$result['@attributes'] = [];
				}

				foreach ($nsAttributes as $attrName => $attrValue) {
					// Preserve the namespace prefix in the attribute name (with colon)
					$nsAttrName = $prefix ? "$prefix:$attrName" : $attrName;
					$result['@attributes'][$nsAttrName] = (string)$attrValue;
				}
			}
		}

		// Handle child elements
		foreach ($xml->children() as $childName => $child) {
			$childArray = $this->xmlToArray($child);

			if (isset($result[$childName])) {
				// If this child name already exists, convert to or add to array
				if (!is_array($result[$childName]) || !isset($result[$childName][0])) {
					$result[$childName] = [$result[$childName]];
				}
				$result[$childName][] = $childArray;
			} else {
				$result[$childName] = $childArray;
			}
		}

		// Handle text content
		$text = trim((string)$xml);
		if (count($result) === 0 && $text !== '') {
			return ['#text' => $text];
		} elseif ($text !== '') {
			$result['#text'] = $text;
		}

		return $result;
	}

	/**
	 * Process a single object during synchronization
	 *
	 * @param Synchronization $synchronization The synchronization being processed
	 * @param array $object The object to synchronize
	 * @param array $result The current result tracking data
	 * @param bool $isTest Whether this is a test run
	 * @param bool $force Whether to force synchronization regardless of changes
	 * @param SynchronizationLog $log The synchronization log
	 * @param string|null $mutationType The type of object mutation
	 *
	 * @return array Contains updated result data and the targetId ['result' => array, 'targetId' => string|null]
	 */
	private function processSynchronizationObject(
		Synchronization $synchronization,
		array $object,
		array $result,
		bool $isTest,
		bool $force,
		SynchronizationLog $log,
        FlowToken &$flowToken,
        ?string $mutationType = null
	): array {
		// We can only deal with arrays (based on the source empty values or string might be returned)
		if (is_array($object) === false) {
			$result['objects']['invalid']++;
			return ['result' => $result, 'targetId' => null];
		}

        $sourceConfig = $this->callService->applyConfigDot($synchronization->getSourceConfig());
        // Optional to fetch extra data now instead of later in ->synchronizeContract
        if (isset($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION]) === true && ($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION] === true || $sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION] === 'true')) {
            $object = $this->fetchMultipleExtraData(synchronization: $synchronization, sourceConfig: $sourceConfig, object: $object);
        }

		$conditionsObject = $this->encodeArrayKeys($object, '.', '&#46;');

		// Add flow token to conditions object if it exists
		if ($flowToken !== null) {
			$conditionsObject['flowToken'] = $flowToken->__serialize();
		}

		// Check if object adheres to conditions.
		// Take note, JsonLogic::apply() returns a range of return types, so checking it with '=== false' or '!== true' does not work properly.
		
		if ($synchronization->getConditions() !== [] && !JsonLogic::apply($synchronization->getConditions(), $conditionsObject)) {
			// Increment skipped count in log since object doesn't meet conditions
			$result['objects']['skipped']++;
			return ['result' => $result, 'targetId' => null];
		}

		// If the source configuration contains a dot notation for the id position, we need to extract the id from the source object
		$originId = $this->getOriginId($synchronization, $object);

		// Get the synchronization contract for this object
        $findContractByOriginId = false;
        if (isset($sourceConfig['findContractByOriginIdOnly']) === true && filter_var($sourceConfig['findContractByOriginIdOnly'], FILTER_VALIDATE_BOOLEAN) === true) {
            $findContractByOriginId = true;
        }

		$synchronizationContract = $this->synchronizationContractMapper->findSyncContractByOriginId(
			synchronizationId: $synchronization->id,
			originId: $originId,
            justByOriginId: $findContractByOriginId

		);

		if ($synchronizationContract instanceof SynchronizationContract === false) {
			// Only persist if not test
			$synchronizationContract = new SynchronizationContract();
			$synchronizationContract->setSynchronizationId($synchronization->getId());
			$synchronizationContract->setOriginId($originId);

			$synchronizationContractResult = $this->synchronizeContract(
				synchronizationContract: $synchronizationContract,
                synchronization: $synchronization,
                flowToken: $flowToken,
                object: $object,
                isTest: $isTest,
                force: $force,
                log: $log,
                mutationType: $mutationType
			);

			$synchronizationContract = $synchronizationContractResult['contract'];
			$result['contracts'][] = isset($synchronizationContractResult['contract']['uuid']) ?
				$synchronizationContractResult['contract']['uuid'] : null;
			$result['logs'][] = isset($synchronizationContractResult['log']['uuid']) ?
				$synchronizationContractResult['log']['uuid'] : null;
			$resultAction = $synchronizationContractResult['resultAction'] ?? null;
			if ($resultAction === 'update') {
				$resultAction = 'create';
			}
		} else {
			// @todo this is weird
			$synchronizationContractResult = $this->synchronizeContract(
				synchronizationContract: $synchronizationContract,
				synchronization: $synchronization,
                flowToken: $flowToken,
				object: $object,
				isTest: $isTest,
				force: $force,
				log: $log,
                mutationType: $mutationType
            );

			$synchronizationContract = $synchronizationContractResult['contract'];
			$result['contracts'][] = isset($synchronizationContractResult['contract']['uuid']) === true ?
				$synchronizationContractResult['contract']['uuid'] : null;
			$result['logs'][] = isset($synchronizationContractResult['log']['uuid']) === true ?
				$synchronizationContractResult['log']['uuid'] : null;
			$resultAction = $synchronizationContractResult['resultAction'] ?? null;
		}

		switch ($resultAction) {
			case 'update':
				$result['objects']['updated']++;
				break;
			case 'create':
				$result['objects']['created']++;
				break;
			case 'delete':
				$result['objects']['deleted']++;
				break;
			case 'skip':
				$result['objects']['skipped']++;
				break;
			default:
				$result['objects']['invalid']++;
				break;
		}

		$targetId = $synchronizationContract['targetId'] ?? null;

		return ['result' => $result, 'targetId' => $targetId];
	}

    /**
     * Fetch an synchronization by id or other characteristics.
     * Prevents other services from having to interact with the synchronizationmapper directly.
     *
     * @param string|int|null $id The id of the synchronization.
     * @param array $filters Other filters to find the synchronization by.
     * @return Synchronization The resulting synchronization
     * @throws DoesNotExistException Thrown if the synchronization does not exist.
     */
    public function getSynchronization(null|string|int $id = null, array $filters = []) :Synchronization
    {
        if ($id !== null) {
            $id = intval($id);
            return $this->synchronizationMapper->find($id);
        }

        /** @var Synchronization[] $synchronizations */
        $synchronizations = $this->synchronizationMapper->findAll(filters: $filters);

        if(count($synchronizations) === 0) {
            throw new DoesNotExistException('The synchronization you are looking for does not exist');
        }

        return $synchronizations[0];
    }

    /**
     * Calculates the median value from an array of numbers.
     *
     * This method sorts the input array and returns the middle value for odd-length arrays
     * or the average of the two middle values for even-length arrays.
     *
     * @param array $numbers Array of numeric values to calculate median from.
     *
     * @return float The median value, or 0 if the array is empty.
     *
     * @psalm-param array<float|int> $numbers
     * @phpstan-param array<float|int> $numbers
     */
    private function calculateMedian(array $numbers): float
    {
        if (empty($numbers)) {
            return 0.0;
        }

        // Sort the array to find the median
        sort($numbers);
        $count = count($numbers);

        // If odd number of elements, return the middle one
        if ($count % 2 === 1) {
            return (float) $numbers[intval($count / 2)];
        }

        // If even number of elements, return average of two middle values
        $middle1 = $numbers[intval($count / 2) - 1];
        $middle2 = $numbers[intval($count / 2)];
        return ($middle1 + $middle2) / 2.0;
    }

    /**
     * Identifies the slowest stage from timing data.
     *
     * This method analyzes the timing stages and returns information about
     * the stage that took the longest to execute.
     *
     * @param array $stages Array of timing stage data with duration_ms values.
     *
     * @return array Information about the slowest stage including name, duration, and description.
     *
     * @psalm-param array<string, array{duration_ms: float, description: string}> $stages
     * @phpstan-param array<string, array{duration_ms: float, description: string}> $stages
     * @psalm-return array{name: string, duration_ms: float, description: string}
     * @phpstan-return array{name: string, duration_ms: float, description: string}
     */
    private function getSlowestStage(array $stages): array
    {
        if (empty($stages)) {
            return [
                'name' => 'none',
                'duration_ms' => 0.0,
                'description' => 'No stages recorded'
            ];
        }

        $slowestStage = '';
        $slowestDuration = 0.0;
        $slowestDescription = '';

        foreach ($stages as $stageName => $stageData) {
            if ($stageData['duration_ms'] > $slowestDuration) {
                $slowestDuration = $stageData['duration_ms'];
                $slowestStage = $stageName;
                $slowestDescription = $stageData['description'];
            }
        }

        return [
            'name' => $slowestStage,
            'duration_ms' => $slowestDuration,
            'description' => $slowestDescription
        ];
    }

    /**
     * Calculates the efficiency ratio of the synchronization process.
     *
     * This method determines how much time was spent on actual object processing
     * versus overhead operations like fetching, configuration, and cleanup.
     * A higher ratio indicates more efficient processing.
     *
     * @param array $stages Array of timing stage data with duration_ms values.
     *
     * @return float Efficiency ratio between 0 and 1, where 1 means 100% of time spent on processing.
     *
     * @psalm-param array<string, array{duration_ms: float}> $stages
     * @phpstan-param array<string, array{duration_ms: float}> $stages
     */
    private function calculateEfficiencyRatio(array $stages): float
    {
        if (empty($stages)) {
            return 0.0;
        }

        $totalDuration = 0.0;
        $processingDuration = 0.0;

        foreach ($stages as $stageName => $stageData) {
            $totalDuration += $stageData['duration_ms'];

            // Consider 'process_objects' as the core processing stage
            if ($stageName === 'process_objects') {
                $processingDuration = $stageData['duration_ms'];
            }
        }

        if ($totalDuration === 0.0) {
            return 0.0;
        }

        return round($processingDuration / $totalDuration, 4);
    }

	/**
	 * Cleans up files that are currently attached to an object but not present in the new file set.
	 *
	 * This method compares the currently attached files to an object with the new set of files
	 * being processed and removes any files that are no longer needed.
	 *
	 * @param string $objectId The UUID of the object to clean up files for.
	 * @param array $newFileNames Array of filenames that should remain attached to the object.
	 *
	 * @return int The number of files that were deleted.
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 */
	private function cleanupOrphanedFiles(string $objectId, array $newFileNames): int
	{
		$deletedCount = 0;

		try {
			// Get the object entity
			$objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
			try {
				$objectEntity = $objectService->findByUuid(uuid: $objectId);
			} catch (DoesNotExistException $e) {
				// It is possible we are trying to delete files for an object id where the object has not been persisted yet (for example a zgw informatieobject can have a beforehand generated uuid)
				return 0;
			}

			// Get the file service
			$fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');

			// Get all currently attached files for this object
			$currentFiles = $fileService->getFiles($objectEntity);

			// Check each current file to see if it should be kept
			foreach ($currentFiles as $file) {
				$fileName = $file->getName();

				// If this file is not in the new set, delete it
				if (!in_array($fileName, $newFileNames, true)) {
					try {
						// Use FileService's deleteFile method instead of direct deletion
						$result = $fileService->deleteFile($file, $objectEntity);

						if ($result === true) {
							$deletedCount++;
						}
					} catch (Exception $e) {
						error_log("FAILED to delete orphaned file {$fileName}: " . $e->getMessage());
					}
				}
			}

		} catch (Exception $e) {
			error_log("FATAL ERROR during file cleanup for object {$objectId}: " . $e->getMessage());
		}

		return $deletedCount;
	}

	/**
	 * Processes file fetching for multiple files and handles cleanup of orphaned files.
	 *
	 * This method fetches multiple files for an object and ensures that any files
	 * currently attached to the object but not in the new set are removed.
	 *
	 * @param Source $source The source to fetch files from.
	 * @param array $config The fetch_file rule configuration.
	 * @param array $endpoints Array of endpoints/file data to process.
	 * @param string|null $objectId The UUID of the object to attach files to.
	 *
	 * @return void
	 */
	private function processMultipleFilesWithCleanup(Source $source, array $config, array $endpoints, ?string $objectId = null): void
	{
		$newFileNames = [];

        if ($endpoints === [] && $objectId !== null) {
            $targetObjectId = $objectId;
        } elseif ($endpoints === []) {
            return;
        }

		// Process all files first and collect their filenames
		foreach ($endpoints as $endpoint) {
			$filename = null;
			$tags = [];
			$contextObjectId = null;
			$actualEndpoint = null;
			$registerId = null;
            $published = null;

			// Handle different endpoint types
			if (is_array($endpoint)) {
				// This is an object with file metadata (multidimensional array case)
				$actualEndpoint = $this->getFileContext(
					config: $config,
					endpoint: $endpoint,
					filename: $filename,
					tags: $tags,
					objectId: $contextObjectId,
                    published: $published,
                    registerId: $registerId
				);
			} else {
				// This is a simple endpoint string (indexed array case)
				$actualEndpoint = $endpoint;
			}

			// Use context object ID if specified, otherwise fall back to the original object ID
			$targetObjectId = $contextObjectId ?? $objectId;

			if ($actualEndpoint !== null) {
				// Determine filename for tracking BEFORE attempting fetch


				try {
					// Fetch the file
					$this->fetchFile(
						source: $source,
						endpoint: $actualEndpoint,
						config: $config,
						objectId: $targetObjectId,
						tags: $tags,
						filename: $filename,
                        published: $published,
                        registerId: $registerId
					);
				} catch (Exception $e) {
					error_log("Failed to fetch file from endpoint {$actualEndpoint}: " . $e->getMessage());
					// Note: We still keep the filename in tracking array even if fetch fails
					// This prevents cleanup from deleting files that should exist
				}

                $trackingFilename = $filename;

                if ($trackingFilename === null) {
                    // Try to extract filename from endpoint URL
                    $pathParts = explode('/', $actualEndpoint);
                    $trackingFilename = end($pathParts);

                    // If still no clear filename, generate a fallback
                    if (empty($trackingFilename) || strpos($trackingFilename, '?') !== false) {
                        $trackingFilename = 'file_' . md5($actualEndpoint);
                    }
                }

                // Add to tracking array BEFORE attempting fetch (so failures don't affect cleanup)
                if (!empty($trackingFilename)) {
                    $newFileNames[] = $trackingFilename;
                }
			}
		}

		// Always run cleanup, even if newFileNames is empty
		// This handles the case where all files should be removed from an object
		$this->cleanupOrphanedFiles($targetObjectId, $newFileNames);
	}

	/**
	 * Cleans up files for an object based on the current attachments array.
	 *
	 * This method compares the files currently attached to an object with the files
	 * that should exist based on the attachments array from the synchronized data.
	 * Files that are no longer referenced in the attachments will be removed.
	 *
	 * @param string $objectId The UUID of the object to clean up files for.
	 * @param array $attachments Array of attachment objects with filename properties.
	 *
	 * @return int The number of files that were deleted.
	 * @throws ContainerExceptionInterface
	 * @throws NotFoundExceptionInterface
	 * @throws Exception
	 */
	public function cleanupFilesFromAttachments(string $objectId, array $attachments): int
	{
		// Extract filenames from attachments array
		$expectedFileNames = [];
		foreach ($attachments as $attachment) {
			if (isset($attachment['filename']) && !empty($attachment['filename'])) {
				$expectedFileNames[] = $attachment['filename'];
			}
		}

		// Remove duplicates in case the same file appears multiple times with different labels
		$expectedFileNames = array_unique($expectedFileNames);

		// Use the existing cleanup method
		return $this->cleanupOrphanedFiles($objectId, $expectedFileNames);
	}

	/**
	 * Determines if a file should be published based on the published parameter.
	 *
	 * This method checks if the published parameter indicates that a file should be published.
	 * It supports boolean values (true/false), string values ("true"/"false"), and date strings.
	 * For date strings, it assumes the file should be published if a date is provided.
	 *
	 * @param string|null $published The published parameter from the attachment data.
	 *
	 * @return bool True if the file should be published, false otherwise.
	 */
	private function shouldPublishFile(?string $published): bool
	{
		if ($published === null) {
			return false;
		}

		// Handle boolean true values
		if ($published === true || $published === 'true' || $published === '1') {
			return true;
		}

		// Handle boolean false values
		if ($published === false || $published === 'false' || $published === '0') {
			return false;
		}

		// Handle date strings - if it's a valid date string, consider it as published
		if (is_string($published) && !empty($published)) {
			// Try to parse as a date
			$date = \DateTime::createFromFormat(\DateTime::ATOM, $published);
			if ($date !== false) {
				return true;
			}

			// Try other common date formats
			$formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s'];
			foreach ($formats as $format) {
				$date = \DateTime::createFromFormat($format, $published);
				if ($date !== false) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Fetches a file from a given endpoint and saves it to the OpenRegister system.
	 *
	 * This method downloads a file from a remote source and stores it in the OpenRegister
	 * file system, optionally applying tags and sharing settings.
	 *
	 * @param Source $source The source configuration for the API call.
	 * @param string $endpoint The endpoint URL to fetch the file from.
	 * @param array $config Configuration array containing method, write settings, etc.
	 * @param string $objectId The UUID of the object to attach the file to.
	 * @param array|null $tags Optional array of tags to apply to the file.
	 * @param string|null $filename Optional filename to use for the saved file.
	 * @param string|null $published Optional published status to determine if file should be published.
	 *
	 * @return string The original endpoint URL.
	 * @throws Exception If the file cannot be fetched or saved.
	 * @throws \OCP\DB\Exception
	 */
}
