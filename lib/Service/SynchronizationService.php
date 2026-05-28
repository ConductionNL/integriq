<?php
/**
 * OpenConnector Synchronization Service.
 *
 * Service for handling synchronization operations between internal and external
 * data sources. Provides functionality for mapping, transforming, and synchronizing
 * data with support for asynchronous file fetching using ReactPHP for improved
 * performance.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service;

use Adbar\Dot;
use DateTime;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use JWadhams\JsonLogic;
use OC\User\NoUserException;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
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
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * Service for handling synchronization operations between internal and external data sources.
 *
 * @SuppressWarnings(PHPMD)
 */
class SynchronizationService
{

    /**
     * Retention period in milliseconds for error logs.
     *
     * @var integer
     */

    /**
     * In-memory accumulator of contract log payloads for the active synchronize() pass.
     *
     * Append-only `synchronization_contract_log` schemas reject UPDATE (#1007). We
     * therefore build the complete contract log payload in memory across the
     * synchronizeContract() body and persist it ONCE — at the end of synchronize() —
     * with the parent `synchronizationLogId` filled in from the sync log row that
     * is also created exactly once at the same finalize step.
     *
     * Cleared on synchronize() entry and after each finalize.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $pendingContractLogs = [];

    /**
     * Retention period in milliseconds for error logs.
     *
     * @var integer
     */
    private int $errorRetention;

    /**
     * Retention period in milliseconds for synchronization contract error logs.
     *
     * @var integer
     */
    private int $errorContractRetention;

    /**
     * Retention period in milliseconds for success logs.
     *
     * @var integer
     */
    private int $successRetention;

    const EXTRA_DATA_CONFIGS_LOCATION          = 'extraDataConfigs';
    const EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION = 'dynamicEndpointLocation';
    const EXTRA_DATA_STATIC_ENDPOINT_LOCATION  = 'staticEndpoint';
    const KEY_FOR_EXTRA_DATA_LOCATION          = 'keyToSetExtraData';
    const MERGE_EXTRA_DATA_OBJECT_LOCATION     = 'mergeExtraData';
    const UNSET_CONFIG_KEY_LOCATION            = 'unsetConfigKey';

    const EXTRA_DATA_ENDPOINT_TEMPLATE_LOCATION = 'endpointTemplate';

    const EXTRA_DATA_BEFORE_CONDITIONS_LOCATION = 'fetchExtraDataBeforeConditions';
    const EXTEND_BEFORE_CONDITIONS_LOCATION     = 'extendInputBeforeConditions';
    const EXTEND_BEFORE_CONDITIONS_FETCH_OBJECT = 'extendInputFetchObjectBeforeConditions';
    const FILE_TAG_TYPE        = 'files';
    const VALID_MUTATION_TYPES = ['create', 'update', 'delete'];
    // Safety limit to prevent infinite page requesting loop.
    const DEFAULT_MAX_PAGES = 50;
    private const DEFAULT_SUCCESS_LOG_RETENTION = 3600000;
    private const DEFAULT_ERROR_LOG_RETENTION   = 259200000;

    /**
     * Constructor.
     *
     * @param CallService        $callService        Service used to perform HTTP calls.
     * @param MappingService     $mappingService     Service used to map source data to target shape.
     * @param ContainerInterface $containerInterface Service container for lazy resolution.
     * @param ORObjectService    $orObjectService    OpenRegister object service.
     * @param ObjectService      $objectService      Local object service.
     * @param StorageService     $storageService     Storage service for file persistence.
     * @param LoggerInterface    $logger             Logger used for error reporting.
     * @param IAppConfig         $appConfig          App configuration provider.
     */
    public function __construct(
        private readonly CallService $callService,
        private readonly MappingService $mappingService,
        private readonly ContainerInterface $containerInterface,
        private readonly ORObjectService $orObjectService,
        private readonly ObjectService $objectService,
        private readonly StorageService $storageService,
        private readonly LoggerInterface $logger,
        IAppConfig $appConfig,
    ) {
        if ($appConfig->hasKey(app: 'openconnector', key: 'retention') === true) {
            $retentionRaw         = $appConfig->getValueString(app: 'openconnector', key: 'retention');
            $retentionDecoded     = json_decode($retentionRaw, true);
            $this->errorRetention = $retentionDecoded['syncLogRetention'] ?? self::DEFAULT_ERROR_LOG_RETENTION;
            $this->errorContractRetention = $retentionDecoded['syncContractLogRetention'] ?? self::DEFAULT_ERROR_LOG_RETENTION;
            $this->successRetention       = $retentionDecoded['successLogRetention'] ?? self::DEFAULT_SUCCESS_LOG_RETENTION;
        } else {
            $this->errorRetention         = self::DEFAULT_ERROR_LOG_RETENTION;
            $this->errorContractRetention = self::DEFAULT_ERROR_LOG_RETENTION;
            $this->successRetention       = self::DEFAULT_SUCCESS_LOG_RETENTION;
        }

    }//end __construct()

    /**
     * Calculates the used retention for created logs.
     *
     * Consists of the maximum of the retention from the source, and the global
     * retention, unless either of both is 0, in which case retention is indefinite.
     *
     * @param int[] ...$retentions The list of retentions in milliseconds to find the maximum duration for.
     *
     * @return \DateTime|null The calculated expiry.
     *
     * @throws \DateMalformedStringException When the date string cannot be parsed.
     *
     * @TODO: At a later point in time this should be changed to using the most specific source for expiration.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    private function calculateExpires(...$retentions): ?\DateTime
    {
        if (in_array(0, $retentions, true) === true) {
            return null;
        }

        return new \DateTime('now +'.max($retentions).'milliseconds');
    }//end calculateExpires()

    /**
     * Finds all synchronizations by the given source ID, which is a combination of register and schema.
     *
     * @param mixed $register The register id.
     * @param mixed $schema   The schema id.
     *
     * @return array The list of records matching the source ID.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    public function findAllBySourceId($register, $schema)
    {
        $sourceId = "$register/$schema";
        $filters  = [
            'register' => 'openconnector',
            'schema'   => 'synchronization',
            'sourceId' => $sourceId,
        ];
        $result   = $this->orObjectService->findAll(config: ['filters' => $filters]);
        return $result['results'] ?? $result;

    }//end findAllBySourceId()

    /**
     * Handle synchronization for object create/update/delete events.
     *
     * This centralizes event listener behavior:
     * - run direct source synchronizations for the object's register/schema
     * - run related-object trigger synchronizations
     *
     * @param ObjectEntity $object            The object from the event.
     * @param string       $eventMutationType The triggering mutation: create|update|delete
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    public function handleObjectEventSynchronization(ObjectEntity $object, string $eventMutationType): void
    {
        if (in_array($eventMutationType, self::VALID_MUTATION_TYPES, true) === false) {
            return;
        }

        $register = $object->getRegister();
        $schema   = $object->getSchema();
        if ($register === null || $schema === null) {
            return;
        }

        $objectArray = $object->jsonSerialize();
        $processedSynchronizationIds = [];

        $directSynchronizations = $this->findAllBySourceId(register: $register, schema: $schema);
        foreach ($directSynchronizations as $synchronization) {
            if ($this->shouldTriggerOnEvent(synchronization: $synchronization, eventMutationType: $eventMutationType) === false) {
                continue;
            }

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

                $processedSynchronizationIds[] = $synchronization->getUuid();
            } catch (\Exception $e) {
                $this->logger->error(
                        'Failed to process object event: '.$e->getMessage().' for synchronization '.$synchronization->getUuid(),
                        [
                            'exception'         => $e,
                            'eventMutationType' => $eventMutationType,
                            'register'          => $register,
                            'schema'            => $schema,
                        ]
                        );
            }//end try
        }//end foreach

        $triggeredFilters          = [
            'register'                              => 'openconnector',
            'schema'                                => 'synchronization',
            'sourceType'                            => 'register/schema',
            'triggerFromRelatedObjectsRegister'     => $register,
            'triggerFromRelatedObjectsSchema'       => $schema,
            'triggerFromRelatedObjectsMutationType' => $eventMutationType,
        ];
        $triggeredMatches          = $this->orObjectService->findAll(config: ['filters' => $triggeredFilters]);
        $triggeredSynchronizations = $triggeredMatches['results'] ?? $triggeredMatches;

        foreach ($triggeredSynchronizations as $synchronization) {
            if (in_array($synchronization->getUuid(), $processedSynchronizationIds, true) === true) {
                continue;
            }

            if ($this->shouldTriggerOnEvent(synchronization: $synchronization, eventMutationType: $eventMutationType) === false) {
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
                $this->logger->error(
                        'Failed to process related-object trigger: '.$e->getMessage().' for synchronization '.$synchronization->getUuid(),
                        [
                            'exception'         => $e,
                            'eventMutationType' => $eventMutationType,
                            'register'          => $register,
                            'schema'            => $schema,
                        ]
                        );
            }//end try
        }//end foreach
    }//end handleObjectEventSynchronization()

    /**
     * Determines whether a synchronization should run for the given mutation type.
     *
     * Checks sourceConfig['triggerOnlyOnEvents'] (case-insensitive). When the key
     * is absent or empty the synchronization always runs; when present it runs only
     * if $eventMutationType is listed.
     *
     * @param ObjectEntity $synchronization   The synchronization to evaluate.
     * @param string       $eventMutationType One of create|update|delete.
     *
     * @return bool True when the synchronization should run, false when it should be skipped.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    private function shouldTriggerOnEvent(ObjectEntity $synchronization, string $eventMutationType): bool
    {
        $sourceConfig  = (array) ($synchronization->getSourceConfig() ?? []);
        $allowedEvents = ($sourceConfig['triggerOnlyOnEvents'] ?? []);

        if (empty($allowedEvents) === true) {
            return true;
        }

        $normalised = array_map('strtolower', (array) $allowedEvents);
        return in_array(strtolower($eventMutationType), $normalised, true);

    }//end shouldTriggerOnEvent()

    /**
     * Resolve and fetch the parent object for a related-object trigger.
     *
     * @param ObjectEntity $synchronization The synchronization that should run.
     * @param array        $triggerObject   The related object payload from the event.
     * @param string|int   $triggerRegister The register of the related object source.
     * @param string|int   $triggerSchema   The schema of the related object source.
     * @param string|null  $mutationType    The mutation type that triggered the call.
     *
     * @return array|null The fetched parent object as array, or null when it cannot be resolved.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    public function resolveParentObjectForRelatedObjectTrigger(
        ObjectEntity $synchronization,
        array $triggerObject,
        string|int $triggerRegister,
        string|int $triggerSchema,
        ?string $mutationType=null
    ): ?array {
        $syncData = $synchronization->getObject();
        if (($syncData['sourceType'] ?? '') !== 'register/schema') {
            return null;
        }

        $sourceId = $syncData['sourceId'] ?? null;
        if (empty($sourceId) === true || str_contains($sourceId, '/') === false) {
            return null;
        }

        $triggerSourceId = "$triggerRegister/$triggerSchema";
        $sourceConfig    = $this->callService->applyConfigDot($syncData['sourceConfig'] ?? []);
        $triggerConfig   = $sourceConfig['triggerFromRelatedObjects'];

        $relationKeys = [];
        if (isset($triggerConfig[$triggerSourceId]) === true && is_array($triggerConfig[$triggerSourceId]) === true) {
            $relationConfig   = $triggerConfig[$triggerSourceId];
            $firstRelationKey = array_key_first($relationConfig);
            if (is_string($firstRelationKey) === true && trim($firstRelationKey) !== '') {
                $relationKeys[] = trim($firstRelationKey);
            }
        }

        if ($relationKeys === []) {
            return null;
        }

        $triggerObjectDot  = new Dot($triggerObject);
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
        } else if (filter_var($relationReference, FILTER_VALIDATE_URL) !== false) {
            $path        = trim((string) parse_url($relationReference, PHP_URL_PATH), '/');
            $segments    = array_values(array_filter(explode('/', $path), static fn ($segment) => $segment !== ''));
            $lastSegment = end($segments);
            if ($lastSegment === false) {
                $lastSegment = null;
            }

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
            $this->logger->debug(
                    'Failed resolving related parent object via configured relation key',
                    [
                        'synchronizationId' => $synchronization->getUuid(),
                        'sourceId'          => $sourceId,
                        'triggerSourceId'   => $triggerSourceId,
                        'relationKeys'      => $relationKeys,
                        'relationReference' => $relationReference,
                        'parentObjectId'    => $parentObjectId,
                        'error'             => $e->getMessage(),
                    ]
                    );

            return null;
        }//end try
    }//end resolveParentObjectForRelatedObjectTrigger()

    /**
     * Synchronizes internal data to external sources based on synchronization rules.
     *
     * @param ObjectEntity                            $synchronization The synchronization configuration.
     * @param \OCA\OpenRegister\Db\ObjectEntity|array $object          The object to be synchronized, also referenced.
     * @param array                                   $logData         The log data accumulator recording synchronization details.
     * @param FlowToken                               $flowToken       The flow token tracking the operation.
     * @param bool|null                               $isTest          Whether this is a test run (does not persist data).
     * @param bool|null                               $force           Whether to force the synchronization regardless of changes.
     * @param string|null                             $mutationType    Single object mutation type: 'create', 'update' or 'delete'.
     *
     * @return ObjectEntity|array|null Returns a synchronization contract, an array for test cases, or null if conditions are not met.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    private function synchronizeInternToExtern(
        ObjectEntity $synchronization,
        \OCA\OpenRegister\Db\ObjectEntity|array &$object,
        array &$logData,
        FlowToken &$flowToken,
        ?bool $isTest=false,
        ?bool $force=false,
        ?string $mutationType=null,
    ): ObjectEntity|array|null {
        $syncData         = $synchronization->getObject();
        $serializedObject = $object;
        if ($object instanceof \OCA\OpenRegister\Db\ObjectEntity === true) {
            $serializedObject = $object->jsonSerialize();
        }

        $sourceConfig = $this->callService->applyConfigDot($syncData['sourceConfig'] ?? []);
        if (isset($sourceConfig[self::EXTEND_BEFORE_CONDITIONS_LOCATION]) === true) {
            $this->logger->debug(
                    'internToExtern before extendInputBeforeConditions',
                    [
                        'objectId'                => $serializedObject['@self']['id'] ?? $serializedObject['id'] ?? null,
                        'betrokkeneIdentificatie' => $serializedObject['betrokkeneIdentificatie'] ?? null,
                    ]
                    );

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
                     'properties'  => $sourceConfig[self::EXTEND_BEFORE_CONDITIONS_LOCATION],
                     'fetchObject' => $fetchObject,
                 ],
             ],
             data: $serializedObject
            );

            $this->logger->debug(
                    'internToExtern after extendInputBeforeConditions',
                    [
                        'objectId'                => $serializedObject['@self']['id'] ?? $serializedObject['id'] ?? null,
                        'betrokkeneIdentificatie' => $serializedObject['betrokkeneIdentificatie'] ?? null,
                    ]
                    );
        }//end if

        $syncConditions = $syncData['conditions'] ?? [];
        if ($syncConditions !== [] && JsonLogic::apply($syncConditions, $serializedObject) !== true) {
            return null;
        }

        // Keep the working object in sync with pre-condition enrichment so mapping.
        // And target payload generation use the same extended data.
        if (is_array($serializedObject) === true) {
            $object = $serializedObject;
        }

        $targetConfig = $syncData['targetConfig'] ?? [];

        $originId = null;
        if (is_array($object) === true && isset($object['@self']['id']) === true) {
            $originId = $object['@self']['id'];
        } else if (is_array($object) === true && isset($object['id']) === true) {
            $originId = $object['id'];
        }

        if ($object instanceof \OCA\OpenRegister\Db\ObjectEntity === true && empty($object->getUuid()) === false) {
            $originId = $object->getUuid();
            $object   = $object->getObject();
        }

        if (isset($targetConfig['extend_input']) === true) {
            $fetchObject = false;

            // If we allow the object to be fetched again, fetch including extends (so we hook into the existing extend functionality).
            if (isset($targetConfig['extend_input_fetch_object']) === true) {
                switch ($targetConfig['extend_input_fetch_object']) {
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

            $extendConfig = [
                'extend_input' => [
                    'properties'  => $targetConfig['extend_input'],
                    'fetchObject' => $fetchObject,
                ],
            ];
            $object       = array_merge($object, $this->processExtendInputRule(config: $extendConfig, data: $object));
        }//end if

        // If the source configuration contains a dot notation for the id position, we need to extract the id from the source object.
        $synchronizationContract = null;
        // Get the synchronization contract for this object.
        if ($originId !== null) {
            $contractFilters = [
                'register'          => 'openconnector',
                'schema'            => 'synchronization_contract',
                'synchronizationId' => $synchronization->getUuid(),
                'originId'          => $originId,
            ];
            $matches         = $this->orObjectService->findAll(config: ['filters' => $contractFilters]);
            $contracts       = $matches['results'] ?? $matches;
            if (count($contracts) > 0) {
                $synchronizationContract = $contracts[0];
            } else {
                $synchronizationContract = null;
            }
        }

        if ($synchronizationContract instanceof ObjectEntity === false) {
            // Cast originId to string — the synchronization_contract schema
            // declares originId as string|null but it can also be an integer
            // from numeric source ids.
            $contractPayload = [
                'synchronizationId' => $synchronization->getUuid(),
                'originId'          => (string) $originId,
            ];
            // The controller docblock for sync-test guarantees zero persistent
            // writes (#1008). Build the contract in-memory only under $isTest.
            if ($isTest === true) {
                $synchronizationContract = new ObjectEntity();
                // Positional arg only — Entity::__call's setter() uses $args[0].
                // Named args on Entity magic setters silently miscompose (memory rule).
                $synchronizationContract->setObject($contractPayload);
            } else {
                $synchronizationContract = $this->orObjectService->saveObject(
                    object: $contractPayload,
                    register: 'openconnector',
                    schema: 'synchronization_contract',
                );
            }

            $synchronizationContract = $this->synchronizeContract(
                synchronizationContract: $synchronizationContract,
                synchronization: $synchronization,
                flowToken: $flowToken,
                object: $object,
                isTest: $isTest,
                force: $force,
                mutationType: $mutationType
            );

            if ($isTest === true && is_array($synchronizationContract) === true) {
                // If this is a log and contract array return for the test endpoint.
                $logAndContractArray = $synchronizationContract;

                return $logAndContractArray;
            }
        } else {
            // @todo this is wierd.
            $synchronizationContract = $this->synchronizeContract(
                synchronizationContract: $synchronizationContract,
                synchronization: $synchronization,
                flowToken: $flowToken,
                object: $object,
                isTest: $isTest,
                force: $force,
                mutationType: $mutationType
            );
            if ($isTest === false && $synchronizationContract instanceof ObjectEntity === true) {
                // If this is a regular synchronizationContract update it to the database.
                $this->orObjectService->saveObject(
                    object: $synchronizationContract->getObject(),
                    register: 'openconnector',
                    schema: 'synchronization_contract',
                    uuid: $synchronizationContract->getUuid()
                );
            } else if ($isTest === true && is_array($synchronizationContract) === true) {
                // If this is a log and contract array return for the test endpoint.
                $logAndContractArray = $synchronizationContract;

                return $logAndContractArray;
            }
        }//end if

        if ($synchronizationContract instanceof ObjectEntity === true) {
            $synchronizationContract = $this->orObjectService->saveObject(
                object: $synchronizationContract->getObject(),
                register: 'openconnector',
                schema: 'synchronization_contract',
                uuid: $synchronizationContract->getUuid()
            );
        }

        return $synchronizationContract;

    }//end synchronizeInternToExtern()

    /**
     * Synchronizes external source data to the internal system.
     *
     * This method retrieves objects from the external source as configured in the `Synchronization` object.
     * Each object is processed and mapped internally, and optionally, invalid internal objects are deleted.
     * If the synchronization is part of a chain, any defined follow-ups are also executed.
     *
     * If a rate limit error occurs during the external request, a `TooManyRequestsHttpException` is thrown.
     *
     * @param ObjectEntity $synchronization The synchronization configuration and state.
     * @param array        $logData         The log data accumulator to record synchronization details and results.
     * @param FlowToken    $flowToken       The flow token tracking the operation.
     * @param bool|null    $isTest          Optional flag to run the synchronization in test mode (no deletions, no persistence).
     * @param bool|null    $force           Optional flag to bypass change checks and force synchronization of all objects.
     * @param string|null  $source          The source to synchronize, if not provided, the synchronization's source will be used.
     * @param array|null   $data            The data to add to synchronize, if not provided, the synchronization's data will be used.
     * @param string|null  $mutationType    The current type of mutation we are doing this::VALID_MUTATION_TYPES.
     *
     * @return array Returns the updated synchronization log data with processing results.
     *
     * @throws TooManyRequestsHttpException If the external source responds with a rate limiting error.
     * @throws Exception                    If the source ID is empty or synchronization cannot proceed.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    private function synchronizeExternToIntern(
        ObjectEntity $synchronization,
        array &$logData,
        FlowToken &$flowToken,
        ?bool $isTest=false,
        ?bool $force=false,
        ?string $source=null,
        ?array $data=null,
        ?string $mutationType=null
    ): array {
        $syncData = $synchronization->getObject();
        // Start overall timing measurement.
        $overallStartTime   = microtime(true);
        $rateLimitException = null;

        // Initialize timing data in result.
        $result           = $logData['result'] ?? [];
        $result['timing'] = [
            'stages'   => [],
            'total_ms' => 0,
        ];

        // Stage 1: Configuration and validation.
        $stageStartTime = microtime(true);
        $sourceConfig   = $this->callService->applyConfigDot($syncData['sourceConfig'] ?? []);

        // If a source is provided, use it instead of the synchronization's source.
        // Auto-creating Source records from a request-supplied location combined
        // with the SSRF risk on `location` is dangerous (#1009): a caller who can
        // control the `source` parameter could otherwise register an arbitrary
        // URL as a first-class, permanently-enabled Source without going through
        // the normal admin source-creation workflow. We require explicit
        // provisioning instead — if no matching Source row exists we refuse the
        // request with a descriptive error.
        if ($source !== null) {
            $srcFilters = [
                'register' => 'openconnector',
                'schema'   => 'source',
                'location' => $source,
            ];
            $srcMatches = $this->orObjectService->findAll(config: ['filters' => $srcFilters]);
            $srcList    = $srcMatches['results'] ?? $srcMatches;
            if (count($srcList) === 0) {
                $logData['message'] = 'No Source registered for location "'.$source.'". '
                    .'Create the Source explicitly via the Sources API before triggering this sync (#1009).';
                throw new Exception($logData['message']);
            }

            $sourceEntity         = $srcList[0];
            $syncData['sourceId'] = $sourceEntity->getUuid();
            $synchronization      = $this->orObjectService->saveObject(
                object: $syncData,
                register: 'openconnector',
                schema: 'synchronization',
                uuid: $synchronization->getUuid()
            );
            $syncData = $synchronization->getObject();
        }//end if

        if (empty($syncData['sourceId'] ?? null) === true && $source === null) {
            // Set the failure message in the (by-ref) logData so the single
            // finalize write in synchronize() records the failure (#1007 —
            // do NOT issue a mid-flight saveObject UPDATE here).
            $logData['message'] = 'sourceId of synchronization cannot be empty. Canceling synchronization...';
            throw new Exception('sourceId of synchronization cannot be empty. Canceling synchronization...');
        }

        $result['timing']['stages']['configuration_validation'] = [
            'duration_ms' => round((microtime(true) - $stageStartTime) * 1000, 2),
            'description' => 'Configuration loading and source validation',
        ];

        if ($data !== null && $mutationType === 'delete') {
            $processResult = $this->processSynchronizationObject(
                synchronization: $synchronization,
                object: $data,
                result: $result,
                isTest: $isTest,
                force: $force,
                flowToken: $flowToken,
                mutationType: $mutationType
            );
        } else {
            // Stage 2: Fetching objects from source.
            $stageStartTime = microtime(true);
            try {
                $objectList = $this->getAllObjectsFromSource(
                    synchronization: $synchronization,
                    isTest: $isTest,
                    data: $data
                );
            } catch (TooManyRequestsHttpException $e) {
                $rateLimitException = $e;
                // Ensure it's defined.
                $objectList = [];
            }

            $fetchDuration = round((microtime(true) - $stageStartTime) * 1000, 2);
            $result['timing']['stages']['fetch_objects'] = [
                'duration_ms'     => $fetchDuration,
                'description'     => 'Fetching objects from external source (optimized pagination)',
                'objects_fetched' => count($objectList),
                'rate_limited'    => $rateLimitException !== null,
                'fetch_method'    => 'optimized_sequential',
            ];

            // Stage 3: Object list preparation.
            $stageStartTime = microtime(true);
            $result['objects']['found'] = count($objectList);

            if ($sourceConfig['resultsPosition'] === '_object') {
                // Only wrap when the source returned an associative object — a sequential.
                // List slipped through here causes the downstream loop to see a single.
                // [[…items]] element and miss every row.
                if (array_is_list($objectList) === false) {
                    $objectList = [$objectList];
                }

                $result['objects']['found'] = count($objectList);
            }

            $result['timing']['stages']['object_preparation'] = [
                'duration_ms'        => round((microtime(true) - $stageStartTime) * 1000, 2),
                'description'        => 'Object list preparation and counting',
                'final_object_count' => count($objectList),
            ];

            // Stage 4: Processing individual objects.
            $stageStartTime        = microtime(true);
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
                    force: $force
                );

                $objectProcessingTime    = round((microtime(true) - $objectStartTime) * 1000, 2);
                $objectProcessingTimes[] = $objectProcessingTime;

                $result = $processResult['result'];
                $result['_embed']['contracts'] = array_map(
                        function ($contractId) {
                            $contractFilters = [
                                'register' => 'openconnector',
                                'schema'   => 'synchronization_contract',
                                'uuid'     => $contractId,
                            ];
                            $contractMatches = $this->orObjectService->findAll(config: ['filters' => $contractFilters]);
                            $contractList    = $contractMatches['results'] ?? $contractMatches;
                            $contract        = array_shift($contractList);
                            if ($contract !== null) {
                                return $contract->getObject();
                            }

                            return null;
                        },
                        $result['contracts']
                        );

                if ($processResult['targetId'] !== null) {
                    $synchronizedTargetIds[] = $processResult['targetId'];
                }
            }//end foreach

            $totalProcessingDuration = round((microtime(true) - $stageStartTime) * 1000, 2);
            $objectCount = count($objectList);
            $timeCount   = count($objectProcessingTimes);
            if ($objectCount > 0) {
                $averagePerObjectMs = round($totalProcessingDuration / $objectCount, 2);
            } else {
                $averagePerObjectMs = 0;
            }

            if ($timeCount > 0) {
                $minObjectMs    = min($objectProcessingTimes);
                $maxObjectMs    = max($objectProcessingTimes);
                $medianObjectMs = $this->calculateMedian(numbers: $objectProcessingTimes);
            } else {
                $minObjectMs    = 0;
                $maxObjectMs    = 0;
                $medianObjectMs = 0;
            }

            $result['timing']['stages']['process_objects'] = [
                'duration_ms'           => $totalProcessingDuration,
                'description'           => 'Processing and synchronizing individual objects',
                'objects_processed'     => $objectCount,
                'average_per_object_ms' => $averagePerObjectMs,
                'min_object_ms'         => $minObjectMs,
                'max_object_ms'         => $maxObjectMs,
                'median_object_ms'      => $medianObjectMs,
            ];

            // Stage 5: Cleanup - Delete invalid objects.
            // Defensive guard (#1017): only invoke deleteInvalidObjects when
            // this fetch was a definitive success and returned a genuine
            // result set.
            // - Skip when the source rate-limited us (rateLimitException !==
            // null) — re-throw immediately so the caller learns about the
            // 429 instead of silently swallowing it.
            // - Skip on test runs (no persistent state changes allowed under
            // $isTest = true, #1008).
            // - Skip when no objects were returned AND no objects were
            // processed — an empty fetch from a failing source must NOT
            // cause deleteInvalidObjects to wipe every previously-synced
            // target via `array_diff(allContractTargetIds, [])`.
            // - Findings #1000/#1001/#1002 confirmed deleteInvalidObjects is
            // currently INERT at runtime, so this guard is latent
            // protection — when that bug is eventually repaired, the
            // cascade is already disarmed.
            if ($rateLimitException !== null) {
                throw $rateLimitException;
            }

            $stageStartTime    = microtime(true);
            $deleteRestriction = (isset($sourceConfig['restrictDeletion']) === true && (bool) $sourceConfig['restrictDeletion']);
            if (isset($data) === true) {
                $deleteData = $data;
            } else {
                $deleteData = [];
            }

            $shouldRunCleanup = true;
            if ($isTest === true) {
                $shouldRunCleanup = false;
            } else if (count($objectList) === 0 && count($synchronizedTargetIds) === 0) {
                // No fetched objects and nothing processed → no signal to
                // distinguish "source returned a genuinely empty list" from
                // "every page failed and silently yielded []". Skip cleanup.
                $shouldRunCleanup = false;
            }

            if ($shouldRunCleanup === true) {
                $deletedCount = $this->deleteInvalidObjects(
                    synchronization: $synchronization,
                    synchronizedTargetIds: $synchronizedTargetIds,
                    deleteRestriction: $deleteRestriction,
                    data: $deleteData
                );
            } else {
                $deletedCount = 0;
                $this->logger->info(
                    'Skipping deleteInvalidObjects stage — fetch did not provide a '
                        .'definitive success signal (#1017).',
                    [
                        'synchronizationId' => $synchronization->getUuid(),
                        'isTest'            => $isTest,
                        'objectsFetched'    => count($objectList),
                        'objectsProcessed'  => count($synchronizedTargetIds),
                    ]
                );
            }

            $result['objects']['deleted'] = $deletedCount;

            $result['timing']['stages']['cleanup_invalid'] = [
                'duration_ms'     => round((microtime(true) - $stageStartTime) * 1000, 2),
                'description'     => 'Deleting invalid/orphaned objects',
                'objects_deleted' => $deletedCount,
                'skipped'         => ($shouldRunCleanup === false),
            ];
        }//end if

        // Stage 6: Follow-up synchronizations.
        $stageStartTime = microtime(true);
        $followUpCount  = 0;
        $syncData       = $synchronization->getObject();
        foreach ($syncData['followUps'] ?? [] as $followUp) {
            $followUpSynchronization = $this->orObjectService->find(
                id: $followUp,
                register: 'openconnector',
                schema: 'synchronization'
            );
            if ($followUpSynchronization !== null) {
                $this->synchronize(
                    synchronization: $followUpSynchronization,
                    isTest: $isTest,
                    force: $force
                );
                $followUpCount++;
            }
        }

        $result['timing']['stages']['follow_ups'] = [
            'duration_ms'         => round((microtime(true) - $stageStartTime) * 1000, 2),
            'description'         => 'Executing follow-up synchronizations',
            'follow_ups_executed' => $followUpCount,
        ];

        // Calculate total timing.
        $result['timing']['total_ms'] = round((microtime(true) - $overallStartTime) * 1000, 2);

        // Add performance summary.
        $objectsPerSecond = 0;
        if (isset($objectList) === true && count($objectList) > 0) {
            $objectsPerSecond = round(count($objectList) / ($result['timing']['total_ms'] / 1000), 2);
        }

        $result['timing']['summary'] = [
            'slowest_stage'      => $this->getSlowestStage(stages: $result['timing']['stages']),
            'efficiency_ratio'   => $this->calculateEfficiencyRatio(stages: $result['timing']['stages']),
            'objects_per_second' => $objectsPerSecond,
        ];

        // Update the by-ref logData with the accumulated result. No
        // synchronization_log saveObject here — finalizeSynchronizationLog()
        // performs the single append-only-safe write at the end of
        // synchronize() (#1007).
        $logData['result'] = $result;

        // Note: $rateLimitException is re-thrown at line 923 inside the else
        // branch before reaching this point, so no second check is needed here.
        $syncData['targetLastSynced'] = (new DateTime())->format('c');
        $this->orObjectService->saveObject(
            object: $syncData,
            register: 'openconnector',
            schema: 'synchronization',
            uuid: $synchronization->getUuid()
        );

        return $logData;

    }//end synchronizeExternToIntern()

    /**
     * Synchronizes a given synchronization (or a complete source).
     *
     * @param ObjectEntity                                 $synchronization The synchronization to run.
     * @param bool|null                                    $isTest          Test mode flag.
     * @param bool|null                                    $force           Force the update flag.
     * @param array|\OCA\OpenRegister\Db\ObjectEntity|null $object          Object to synchronize, by reference.
     * @param string|null                                  $mutationType    Mutation type for single object syncs.
     * @param string|null                                  $source          Optional source override.
     * @param array|null                                   $data            Optional data payload.
     * @param FlowToken|null                               $flowToken       Optional flow token.
     *
     * @return array|ObjectEntity|null Returns the synchronization log, contract array or null.
     *
     * @throws ContainerExceptionInterface      When the container fails to resolve a service.
     * @throws NotFoundExceptionInterface       When a required service is not found.
     * @throws GuzzleException                  When a remote HTTP call fails.
     * @throws LoaderError                      When the Twig loader fails.
     * @throws SyntaxError                      When a Twig template has a syntax error.
     * @throws MultipleObjectsReturnedException When the query expects one but receives many.
     * @throws \OCP\DB\Exception                When the database layer raises an exception.
     * @throws Exception                        For any other generic error condition.
     * @throws TooManyRequestsHttpException     When the source rate-limits the request.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    public function synchronize(
        ObjectEntity $synchronization,
        ?bool $isTest=false,
        ?bool $force=false,
        array|\OCA\OpenRegister\Db\ObjectEntity|null &$object=null,
        ?string $mutationType=null,
        ?string $source=null,
        ?array $data=null,
        ?FlowToken &$flowToken=null,
    ): array|ObjectEntity|null {
        $syncData = $synchronization->getObject();
        if ($flowToken === null) {
            $flowToken = new FlowToken();
        }

        if ($mutationType !== null && in_array($mutationType, $this::VALID_MUTATION_TYPES) === false) {
            throw new Exception(
                sprintf(
                    'Invalid mutation type: %s given. Allowed mutation types are: %s',
                    $mutationType,
                    implode(', ', $this::VALID_MUTATION_TYPES)
                )
            );
        }

        // Start execution time measurement.
        $startTime = microtime(true);

        // Reset the contract-log accumulator for this pass. Append-only
        // synchronization_log / synchronization_contract_log schemas reject
        // UPDATE (#1007); we accumulate the full sync log payload and any
        // contract-log payloads in memory and persist each row ONCE at the
        // end of this method (or in the failure path).
        $this->pendingContractLogs = [];

        // Prepare initial log array (no persistence yet).
        $logData = [
            'synchronizationId' => $synchronization->getUuid(),
            'result'            => [
                'objects'   => [
                    'found'   => 0,
                    'skipped' => 0,
                    'created' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                    'invalid' => 0,
                ],
                'contracts' => [],
                'logs'      => [],
            ],
            'test'              => $isTest,
            'force'             => $force,
            'expires'           => $this->calculateExpires(...[$this->errorRetention]),
        ];

        // Shortcut for intern-to-extern sync.
        if (($syncData['sourceType'] ?? '') === 'register/schema' && $object !== null) {
            $logData['result']['type'] = 'internToExtern';

            // SynchronizeInternToExtern receives the in-memory log payload
            // (no upfront sync_log row) and mutates it directly. Persistence
            // happens once below in finalizeSynchronizationLog() — even on
            // failure (try/finally), to preserve operator visibility (#1007).
            $internResult = null;
            $internError  = null;
            try {
                $internResult = $this->synchronizeInternToExtern(
                    synchronization: $synchronization,
                    object: $object,
                    logData: $logData,
                    flowToken: $flowToken,
                    force: $force,
                    mutationType: $mutationType,
                );
            } catch (\Throwable $e) {
                $internError        = $e;
                $logData['level']   = 'ERROR';
                $logData['message'] = $e->getMessage();
            } finally {
                $logData['executionTime'] = round((microtime(true) - $startTime) * 1000);
                $logData['message']       = $logData['message'] ?? 'Success';
                $logData['expires']       = $this->calculateExpires(
                        ...[$this->successRetention, $this->successRetention]
                    )?->format('c');

                // Single write of the sync log + flush any accumulated contract logs.
                $this->finalizeSynchronizationLog(logData: $logData);
            }//end try

            if ($internError !== null) {
                throw $internError;
            }

            return $internResult;
        }//end if

        $logData['result']['type'] = 'externToIntern';

        // Run extern-to-intern. The helper mutates $logData (no intermediate
        // sync_log writes) and accumulates contract-log payloads via
        // $this->pendingContractLogs. Both are flushed once at the end here
        // — including the failure path (try/finally) so operators still see
        // the run in synchronization_log (#1007).
        $externError = null;
        try {
            $this->synchronizeExternToIntern(
                synchronization: $synchronization,
                logData: $logData,
                flowToken: $flowToken,
                isTest: $isTest,
                force: $force,
                source: $source,
                data: $data,
                mutationType: $mutationType
            );
        } catch (\Throwable $e) {
            $externError        = $e;
            $logData['level']   = 'ERROR';
            $logData['message'] = $e->getMessage();
        } finally {
            // Finalize log.
            $logData['executionTime'] = round((microtime(true) - $startTime) * 1000);
            $logData['message']       = $logData['message'] ?? 'Success';
            $logData['expires']       = $this->calculateExpires(
                    ...[$this->successRetention, $this->successRetention]
                )?->format('c');

            $persisted = $this->finalizeSynchronizationLog(logData: $logData);
        }//end try

        if ($externError !== null) {
            throw $externError;
        }

        return $persisted;

    }//end synchronize()

    /**
     * Persist the synchronization_log row ONCE and flush any accumulated
     * contract-log payloads with the new sync-log uuid stamped in (#1007).
     *
     * Append-only schemas reject UPDATE, so the engine must never call
     * saveObject(uuid: ...) for these schemas. Every callable that previously
     * issued progressive updates now accumulates state in memory and the
     * write-once finalize happens here.
     *
     * @param array<string, mixed> $logData The fully assembled sync-log payload.
     *
     * @return array<string, mixed> The persisted sync-log object body.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    private function finalizeSynchronizationLog(array $logData): array
    {
        $persisted    = $this->orObjectService->saveObject(
            object: $logData,
            register: 'openconnector',
            schema: 'synchronization_log'
        );
        $syncLogUuid  = $persisted->getUuid();
        $persistedRow = $persisted->getObject();

        // Flush all accumulated contract-log payloads against the new sync-log uuid.
        foreach ($this->pendingContractLogs as $contractLogPayload) {
            $contractLogPayload['synchronizationLogId'] = $syncLogUuid;
            try {
                $this->orObjectService->saveObject(
                    object: $contractLogPayload,
                    register: 'openconnector',
                    schema: 'synchronization_contract_log'
                );
            } catch (\Throwable $contractLogError) {
                // Never let a single contract-log write failure mask the sync
                // result — log it via Nextcloud's logger and move on.
                $this->logger->error(
                    'finalizeSynchronizationLog: failed to persist contract log: '
                        .$contractLogError->getMessage(),
                    ['exception' => $contractLogError]
                );
            }
        }

        // Clear accumulator now that it's been flushed.
        $this->pendingContractLogs = [];

        return $persistedRow;

    }//end finalizeSynchronizationLog()

    /**
     * Buffer a contract-log payload for write-once flush during
     * finalizeSynchronizationLog (#1007).
     *
     * Returns the payload unchanged so existing call sites that consume the
     * "log" key in the synchronizeContract return shape continue to work.
     *
     * @param array<string, mixed> $contractLogData The accumulated contract log payload.
     *
     * @return array<string, mixed> The payload (unchanged).
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    private function bufferContractLog(array $contractLogData): array
    {
        $this->pendingContractLogs[] = $contractLogData;

        return $contractLogData;

    }//end bufferContractLog()

    /**
     * Gets id from object as is in the origin.
     *
     * @param ObjectEntity $synchronization The synchronization providing the id position config.
     * @param array        $object          The source object to extract the id from.
     *
     * @return string|int The extracted origin id.
     *
     * @throws Exception When the id cannot be found at the configured position.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function getOriginId(ObjectEntity $synchronization, array $object): int|string
    {
        // Default ID position is 'id' if not specified in source config.
        $originIdPosition = 'id';
        $sourceConfig     = $synchronization->getObject()['sourceConfig'] ?? [];

        // Check if a custom ID position is defined in the source configuration.
        if (isset($sourceConfig['idPosition']) === true && empty($sourceConfig['idPosition']) === false) {
            // Override default with custom ID position from config.
            $originIdPosition = $sourceConfig['idPosition'];
        }

        // Create Dot object for easy access to nested array values.
        $objectDot = new Dot($object);

        // Try to get the ID value from the specified position in the object.
        $originId = $objectDot->get($originIdPosition);

        // If no ID was found at the specified position, throw an error.
        if ($originId === null) {
            throw new Exception('Could not find origin id in object for key: '.$originIdPosition);
        }

        // Return the found ID value.
        return $originId;

    }//end getOriginId()

    /**
     * Fetch an object from a specific endpoint.
     *
     * @param ObjectEntity    $synchronization The synchronization containing the source.
     * @param string          $endpoint        The endpoint to request to fetch the desired object.
     * @param string|int|null $source          The source to request if object is in other source than synchronization.
     *
     * @return array The resulting object.
     *
     * @throws GuzzleException   When a remote HTTP call fails.
     * @throws LoaderError       When the Twig loader fails.
     * @throws SyntaxError       When a Twig template has a syntax error.
     * @throws \OCP\DB\Exception When the database layer raises an exception.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    public function getObjectFromSource(ObjectEntity $synchronization, string $endpoint, string|int|null $source=null): array
    {
        $syncData = $synchronization->getObject();
        $sourceId = $syncData['sourceId'] ?? null;

        // If source passed down used that instead.
        if ($source !== null) {
            $sourceId = $source;
        }

        $sourceEntity = $this->orObjectService->find(id: $sourceId, register: 'openconnector', schema: 'source');

        // Let's get the source config.
        $sourceConfig = $this->callService->applyConfigDot($syncData['sourceConfig'] ?? []);
        if ($sourceEntity !== null) {
            $sourceData = $sourceEntity->getObject();
        } else {
            $sourceData = [];
        }

        $config = [];
        if (empty($sourceConfig['headers']) === false) {
            $config['headers'] = $sourceConfig['headers'];
        }

        if (empty($sourceConfig['query']) === false) {
            $config['query'] = $sourceConfig['query'];
        }

        $sourceLocation = $sourceData['location'] ?? '';
        if ($sourceLocation !== '' && str_starts_with($endpoint, $sourceLocation) === true) {
            $endpoint = str_replace(search: $sourceLocation, replace: '', subject: $endpoint);
        }

        // Make the initial API call, read denotes that we call an endpoint for a single object (for config variations).
        $callLog  = $this->callService->call(source: $sourceEntity, endpoint: $endpoint, config: $config, read: true);
        $response = $callLog->getObject()['response'] ?? [];

        return json_decode($response['body'] ?? '', true) ?? [];
    }//end getObjectFromSource()

    /**
     * Fetches additional data for a given object based on the synchronization configuration.
     *
     * @param ObjectEntity $synchronization The synchronization instance containing configuration details.
     * @param array        $extraDataConfig The configuration array specifying how to retrieve and handle the extra data.
     * @param array        $object          The original object for which extra data needs to be fetched.
     * @param string|null  $originId        Optional origin id when already resolved by the caller.
     *
     * @return array The original object merged with the extra data, or the extra data itself based on the configuration.
     *
     * @throws Exception        When both dynamic and static endpoint configurations are missing.
     * @throws GuzzleException  When the HTTP call to the source fails.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function fetchExtraDataForObject(
        ObjectEntity $synchronization,
        array $extraDataConfig,
        array $object, ?string $originId=null
    ): array {
        if (isset($extraDataConfig[$this::EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION]) === false
            && isset($extraDataConfig[$this::EXTRA_DATA_STATIC_ENDPOINT_LOCATION]) === false
        ) {
            return $object;
        }

        // Get endpoint from earlier fetched object.
        if (isset($extraDataConfig[$this::EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION]) === true) {
            $dotObject = new Dot($object);
            $endpoint  = $dotObject->get($extraDataConfig[$this::EXTRA_DATA_DYNAMIC_ENDPOINT_LOCATION] ?? null);
        }

        // Get endpoint static defined in config.
        if (isset($extraDataConfig[$this::EXTRA_DATA_STATIC_ENDPOINT_LOCATION]) === true) {
            if ($originId === null) {
                $originId = $this->getOriginId(synchronization: $synchronization, object: $object);
            }

            if (isset($extraDataConfig['endpointIdLocation']) === true) {
                $dotObject = new Dot($object);
                $originId  = $dotObject->get($extraDataConfig['endpointIdLocation']);
            }

            $endpoint = $extraDataConfig[$this::EXTRA_DATA_STATIC_ENDPOINT_LOCATION];

            if ($originId === null) {
                $originId = $this->getOriginId(synchronization: $synchronization, object: $object);
            }

            $endpoint = str_replace(search: '{{ originId }}', replace: $originId, subject: $endpoint);
            $endpoint = str_replace(search: '{{originId}}', replace: $originId, subject: $endpoint);

            if (isset($extraDataConfig['subObjectId']) === true) {
                $objectDot   = new Dot($object);
                $subObjectId = $objectDot->get($extraDataConfig['subObjectId']);
                if ($subObjectId !== null) {
                    $endpoint = str_replace(search: '{{ subObjectId }}', replace: $subObjectId, subject: $endpoint);
                    $endpoint = str_replace(search: '{{subObjectId}}', replace: $subObjectId, subject: $endpoint);
                }
            }
        }//end if

        if (isset($extraDataConfig[$this::EXTRA_DATA_ENDPOINT_TEMPLATE_LOCATION]) === true
            && is_string($extraDataConfig[$this::EXTRA_DATA_ENDPOINT_TEMPLATE_LOCATION]) === true
            && $extraDataConfig[$this::EXTRA_DATA_ENDPOINT_TEMPLATE_LOCATION] !== ''
        ) {
            $endpoint = $this->mappingService->renderTemplateString(
                template: $extraDataConfig[$this::EXTRA_DATA_ENDPOINT_TEMPLATE_LOCATION],
                context: [
                    'endpoint'        => ($endpoint ?? null),
                    'object'          => $object,
                    'originId'        => $originId,
                    'extraDataConfig' => $extraDataConfig,
                ]
            );
        }

        if (empty($endpoint) === true) {
            throw new Exception(
                sprintf(
                    'Could not get static or dynamic endpoint, object: %s',
                    json_encode($object)
                )
            );
        }

        $syncData     = $synchronization->getObject();
        $sourceConfig = $syncData['sourceConfig'] ?? [];
        if (isset($extraDataConfig[$this::UNSET_CONFIG_KEY_LOCATION]) === true
            && isset($sourceConfig[$extraDataConfig[$this::UNSET_CONFIG_KEY_LOCATION]]) === true
        ) {
            unset($sourceConfig[$extraDataConfig[$this::UNSET_CONFIG_KEY_LOCATION]]);
            $syncData['sourceConfig'] = $sourceConfig;
            $synchronization          = $this->orObjectService->saveObject(
                object: $syncData,
                register: 'openconnector',
                schema: 'synchronization',
                uuid: $synchronization->getUuid()
            );
        }

        $source = null;
        if (isset($extraDataConfig['source']) === true && is_scalar($extraDataConfig['source']) === true) {
            $source = $extraDataConfig['source'];
        }

        $extraData = $this->getObjectFromSource(synchronization: $synchronization, endpoint: $endpoint, source: $source);

        // Temporary fix.
        if (isset($extraDataConfig['extraDataConfigPerResult']) === true) {
            $results = $extraData;
            if (isset($extraDataConfig['resultsLocation']) === true) {
                $dotObject = new Dot($extraData);
                $results   = $dotObject->get($extraDataConfig['resultsLocation']);
            }

            foreach ($results as $key => $result) {
                $results[$key] = $this->fetchExtraDataForObject(
                    synchronization: $synchronization,
                    extraDataConfig: $extraDataConfig['extraDataConfigPerResult'],
                    object: $result,
                    originId: $originId
                );
            }

            $extraData = $results;
        }

        // Set new key if configured.
        if (isset($extraDataConfig[$this::KEY_FOR_EXTRA_DATA_LOCATION]) === true) {
            $extraData = [$extraDataConfig[$this::KEY_FOR_EXTRA_DATA_LOCATION] => $extraData];
        }

        // Merge with earlier fetchde object if configured.
        $mergeFlag = ($extraDataConfig[$this::MERGE_EXTRA_DATA_OBJECT_LOCATION] ?? null);
        if (isset($extraDataConfig[$this::MERGE_EXTRA_DATA_OBJECT_LOCATION]) === true
            && ($mergeFlag === true || $mergeFlag === 'true')
        ) {
            return array_merge($object, $extraData);
        }

        return $extraData;

    }//end fetchExtraDataForObject()

    /**
     * Fetches multiple extra data entries for an object based on the source configuration.
     *
     * @param ObjectEntity $synchronization The synchronization instance containing configuration details.
     * @param array        $sourceConfig    The source configuration containing extra data retrieval settings.
     * @param array        $object          The original object for which extra data needs to be fetched.
     *
     * @return array The updated object with all fetched extra data merged into it.
     *
     * @throws GuzzleException When the underlying HTTP call fails.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function fetchMultipleExtraData(ObjectEntity $synchronization, array $sourceConfig, array $object): array
    {
        if (isset($sourceConfig[$this::EXTRA_DATA_CONFIGS_LOCATION]) === true) {
            foreach ($sourceConfig[$this::EXTRA_DATA_CONFIGS_LOCATION] as $extraDataConfig) {
                $object = array_merge(
                    $object,
                    $this->fetchExtraDataForObject(
                        synchronization: $synchronization,
                        extraDataConfig: $extraDataConfig,
                        object: $object
                    )
                );
            }
        }

        return $object;

    }//end fetchMultipleExtraData()

    /**
     * Maps a given object using a source hash mapping configuration.
     *
     * @param ObjectEntity $synchronization The synchronization instance containing the hash mapping configuration.
     * @param array        $object          The input object to be mapped.
     *
     * @return array|Exception The mapped object, or the original object if no mapping is found.
     *
     * @throws LoaderError When the Twig loader fails.
     * @throws SyntaxError When a Twig template has a syntax error.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function mapHashObject(ObjectEntity $synchronization, array $object): array|Exception
    {
        $syncData          = $synchronization->getObject();
        $sourceHashMapping = $syncData['sourceHashMapping'] ?? null;
        if (empty($sourceHashMapping) === false) {
            $mappingEntity = $this->orObjectService->find(id: $sourceHashMapping, register: 'openconnector', schema: 'mapping');
            if ($mappingEntity === null) {
                return new Exception('Source hash mapping not found: '.$sourceHashMapping);
            }

            return $this->mappingService->executeMapping(mapping: $mappingEntity, input: $object);
        }

        return $object;
    }//end mapHashObject()

    /**
     * Deletes invalid objects associated with a synchronization.
     *
     * @param ObjectEntity $synchronization       The synchronization entity to process.
     * @param array|null   $synchronizedTargetIds An array of target IDs that are still valid in the source.
     * @param bool         $deleteRestriction     Sets if the deletion of objects should be restricted to identifiers called in $data.
     * @param array        $data                  The data to be checked when $deleteRestriction is true for origin ids.
     *
     * @return int The count of objects that were deleted.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws NotFoundExceptionInterface  When a required service is not found in the container.
     * @throws \OCP\DB\Exception           When the database layer raises an exception.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    public function deleteInvalidObjects(
        ObjectEntity $synchronization,
        ?array $synchronizedTargetIds=[],
        bool $deleteRestriction=false,
        array $data=[]
    ): int {
        $deletedObjectsCount = 0;
        $syncData            = $synchronization->getObject();
        $type = $syncData['targetType'] ?? '';

        switch ($type) {
            case 'register/schema':

                $targetIdsToDelete = [];
                $rawTargetId       = $syncData['targetId'] ?? null;
                if (is_string($rawTargetId) === false || str_contains($rawTargetId, '/') === false) {
                    $this->logger->warning(
                        'deleteInvalidObjects: targetId not in register/schema format; skipping cleanup',
                        [
                            'synchronizationId' => $synchronization->getUuid(),
                            'targetId'          => $rawTargetId,
                        ]
                    );
                    break;
                }

                [$registerId, $schemaId] = explode(separator: '/', string: $rawTargetId, limit: 2);
                $cleanupFilters          = [
                    'register'          => 'openconnector',
                    'schema'            => 'synchronization_contract',
                    'synchronizationId' => $synchronization->getUuid(),
                ];
                $contractMatches         = $this->orObjectService->findAll(config: ['filters' => $cleanupFilters]);
                $allContracts            = $contractMatches['results'] ?? $contractMatches;
                $allContractTargetIds    = [];
                $allContractSourceIds    = [];
                foreach ($allContracts as $contract) {
                    $contractData = $contract->getObject();
                    if (isset($contractData['targetId']) === true && $contractData['targetId'] !== null) {
                        $allContractTargetIds[] = $contractData['targetId'];
                        $allContractSourceIds[$contractData['targetId']] = $contractData['originId'] ?? null;
                    }
                }

                // Initialize $synchronizedTargetIds as empty array if null.
                if ($synchronizedTargetIds === null) {
                    $synchronizedTargetIds = [];
                }

                // Check if we have contracts that became invalid or do not exist in the source anymore.
                $targetIdsToDelete = array_diff($allContractTargetIds, $synchronizedTargetIds);
                if ($deleteRestriction === true) {
                    $encodedData       = json_encode($data);
                    $targetIdsToDelete = array_filter(
                            $targetIdsToDelete,
                            function (string|int $targetId) use ($encodedData, $allContractSourceIds) {
                                $sourceId = $allContractSourceIds[$targetId];
                                return str_contains($encodedData, $sourceId);
                            }
                                     );
                }

                foreach ($targetIdsToDelete as $targetIdToDelete) {
                    // Scope-check the cleanup candidate before deleting it. Defence in depth:
                    // `updateTargetOpenRegister`'s `delete` branch now invokes the scoped
                    // `deleteObject($uuid, $register, $schema)` API (OR#1638 / hydra#309), but
                    // we still verify here that the object actually lives in this sync's
                    // register/schema. This avoids audit noise and an unnecessary OR call when
                    // a contract's `targetId` UUID accidentally collides with an object in a
                    // foreign register/schema.
                    //
                    // Note: _rbac / _multitenancy are off because the previous SQL-level guard this
                    // replaces (the JOIN against `openregister_objects` in the pre-cutover code)
                    // did not apply RBAC either — the safety property being restored is scope, not
                    // permission. Ported from #733 (author @rjzondervan).
                    try {
                        $existingObject = $this->orObjectService->find(
                            id: $targetIdToDelete,
                            register: $registerId,
                            schema: $schemaId,
                            _rbac: false,
                            _multitenancy: false
                        );
                    } catch (DoesNotExistException $e) {
                        // Expected scope-miss: target lives outside this register/schema or is gone.
                        continue;
                    } catch (Throwable $e) {
                        $this->logger->warning(
                            'Scope check failed for sync cleanup candidate; skipping',
                            [
                                'synchronizationId' => $synchronization->getUuid(),
                                'targetId'          => $targetIdToDelete,
                                'class'             => get_class($e),
                                'error'             => $e->getMessage(),
                            ]
                        );
                        continue;
                    }//end try

                    if ($existingObject === null) {
                        continue;
                    }

                    try {
                        $targetContractFilters = [
                            'register'          => 'openconnector',
                            'schema'            => 'synchronization_contract',
                            'synchronizationId' => $synchronization->getUuid(),
                            'targetId'          => $targetIdToDelete,
                        ];
                        $contractSearch        = $this->orObjectService->findAll(config: ['filters' => $targetContractFilters]);
                        $contractList          = $contractSearch['results'] ?? $contractSearch;
                        if (empty($contractList) === true) {
                            $this->logger->warning(
                                'Contract not found on second lookup during sync cleanup; continuing',
                                [
                                    'synchronizationId' => $synchronization->getUuid(),
                                    'targetId'          => $targetIdToDelete,
                                ]
                            );
                            continue;
                        }

                        $synchronizationContract = $contractList[0];
                        $synchronizationContract = $this->updateTarget(synchronizationContract: $synchronizationContract, action: 'delete');
                        $contractData            = $synchronizationContract->getObject();
                        $this->orObjectService->saveObject(
                            object: $contractData,
                            register: 'openconnector',
                            schema: 'synchronization_contract',
                            uuid: $synchronizationContract->getUuid()
                        );
                        $deletedObjectsCount++;
                    } catch (Throwable $e) {
                        $this->logger->warning(
                            'Sync cleanup failed for candidate; continuing',
                            [
                                'synchronizationId' => $synchronization->getUuid(),
                                'targetId'          => $targetIdToDelete,
                                'class'             => get_class($e),
                                'error'             => $e->getMessage(),
                            ]
                        );
                    }//end try
                }//end foreach
                break;
        }//end switch

        return $deletedObjectsCount;
    }//end deleteInvalidObjects()

    /**
     * Recursively sort an associative array by key.
     *
     * @param mixed $array The array to sort.
     *
     * @return bool Whether or not the sort is successful.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    public function sortNestedArray(mixed &$array): bool
    {
        if (is_array($array) === false) {
            return false;
        }

        ksort($array);
        foreach ($array as $k => $v) {
            $this->sortNestedArray(array: $array[$k]);
        }

        return true;

    }//end sortNestedArray()

    /**
     * Hash an object in a unified order, so the order in which keys are given does not influence the hash.
     *
     * @param array $object The object to hash.
     *
     * @return string The object hash.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function hashObject(array $object): string
    {
        $this->sortNestedArray(array: $object);

        return md5(serialize($object));

    }//end hashObject()

    /**
     * Synchronize a contract.
     *
     * @param ObjectEntity      $synchronizationContract The contract to synchronize.
     * @param ObjectEntity|null $synchronization         The synchronization driving the contract.
     * @param FlowToken         $flowToken               The flow token threading through the call chain.
     * @param array             $object                  The source object being synchronized.
     * @param bool|null         $isTest                  False by default, currently added for sync-test.
     * @param bool|null         $force                   False by default, force update regardless of changes.
     * @param string|null       $mutationType            Single object mutation type: 'create', 'update' or 'delete'.
     *
     * @return ObjectEntity|Exception|array Returns the updated contract entity, an Exception on mapping failures or the test array.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws LoaderError                 When the Twig loader fails.
     * @throws SyntaxError                 When a Twig template has a syntax error.
     * @throws GuzzleException             When a remote HTTP call fails.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    public function synchronizeContract(
        ObjectEntity $synchronizationContract,
        ObjectEntity $synchronization=null,
        FlowToken &$flowToken,
        array &$object=[],
        ?bool $isTest=false,
        ?bool $force=false,
        ?string $mutationType=null
    ): ObjectEntity|Exception|array {
        // Append-only synchronization_contract_log schemas reject any UPDATE
        // (#1007). We assemble the full contract-log payload in memory across
        // this method and buffer it for write-once flush in finalizeSynchronizationLog().
        // `$contractLogData` is the running accumulator; null means "no log
        // expected for this contract" (e.g. unknown uuid).
        $contractLogData = null;
        $contractData    = $synchronizationContract->getObject();
        if ($synchronization !== null) {
            $syncData = $synchronization->getObject();
        } else {
            $syncData = [];
        }

        // We are doing something so lets log it.
        if (isset($contractData['uuid']) === true && $contractData['uuid'] !== null) {
            if ($synchronization !== null) {
                $synchronizationId = $synchronization->getUuid();
            } else {
                $synchronizationId = null;
            }

            $contractLogData = [
                // SynchronizationLogId is filled in by finalizeSynchronizationLog()
                // once the parent sync_log row is created — see #1007.
                'synchronizationId'         => $synchronizationId,
                'synchronizationContractId' => $synchronizationContract->getUuid(),
                'source'                    => $object,
                'test'                      => $isTest,
                'force'                     => $force,
                'expiry'                    => $this->calculateExpires(...[$this->errorContractRetention])?->format('c'),
            ];
        }//end if

        $flowToken->setSyncInputOriginal($object);

        $sourceConfig = $this->callService->applyConfigDot($syncData['sourceConfig'] ?? []);

        // Check if extra data needs to be fetched.
        // If not fetched before conditions, fetch now.
        $extraBefore = ($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION] ?? null);
        if (isset($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION]) === false
            || ($extraBefore !== true && $extraBefore !== 'true')
        ) {
            $object = $this->fetchMultipleExtraData(synchronization: $synchronization, sourceConfig: $sourceConfig, object: $object);
        }

        $flowToken->setSyncOutputAmended($object);

        // Get mapped hash object (some fields can make it look the object has changed even if it hasn't).
        $hashObject = $this->mapHashObject(synchronization: $synchronization, object: $object);
        // Let create a source hash for the object.
        $originHash = $this->hashObject(object: $hashObject);

        // If no source target mapping is defined, use original object.
        $sourceTargetMappingId = $syncData['sourceTargetMapping'] ?? null;
        if (empty($sourceTargetMappingId) === true) {
            $sourceTargetMapping = null;
        } else {
            $sourceTargetMapping = $this->orObjectService->find(
                id: $sourceTargetMappingId,
                register: 'openconnector',
                schema: 'mapping'
            );
            if ($sourceTargetMapping === null) {
                return new Exception('Source target mapping not found: '.$sourceTargetMappingId);
            }
        }

        // Let's prevent pointless updates by checking:
        // 1. If the origin hash matches (object hasn't changed)
        // 2. If the synchronization config hasn't been updated since last check
        // 3. If source target mapping exists, check it hasn't been updated since last check
        // 4. If target ID and hash exist (object hasn't been removed from target)
        // 5. Force parameter is false (otherwise always continue with update).
        $contractUpdated           = $contractData['updated'] ?? null;
        $contractSourceLastChecked = $contractData['sourceLastChecked'] ?? null;
        $syncUpdated = $syncData['updated'] ?? null;
        if ($sourceTargetMapping !== null) {
            $mappingUpdated = ($sourceTargetMapping->getObject()['updated'] ?? null);
        } else {
            $mappingUpdated = null;
        }

        if ($force === false
            && $originHash === ($contractData['originHash'] ?? null)
            && $syncUpdated < $contractSourceLastChecked
            && ($sourceTargetMapping === null || $mappingUpdated < $contractSourceLastChecked)
            && isset($contractData['targetId']) === true && $contractData['targetId'] !== null
            && isset($contractData['targetHash']) === true && $contractData['targetHash'] !== null
        ) {
            // We checked the source so let log that.
            $contractData['sourceLastChecked'] = (new DateTime())->format('c');
            // Test-mode no-write contract (#1008): skip the saveObject and the
            // contract-log buffer entry on the unchanged-skip path.
            if ($isTest === false) {
                $synchronizationContract = $this->orObjectService->saveObject(
                    object: $contractData,
                    register: 'openconnector',
                    schema: 'synchronization_contract',
                    uuid: $synchronizationContract->getUuid()
                );
                $contractData            = $synchronizationContract->getObject();

                if ($contractLogData !== null) {
                    $contractLogData['expiry'] = $this->calculateExpires(
                            ...[$this->successRetention]
                        )?->format('c');
                    // Buffer the final contract-log payload for write-once flush
                    // — append-only schemas reject UPDATE (#1007).
                    $this->bufferContractLog(contractLogData: $contractLogData);
                }
            } else if ($contractLogData !== null) {
                $contractLogData['expiry'] = $this->calculateExpires(
                        ...[$this->successRetention]
                    )?->format('c');
            }//end if

            $skipLog = $contractLogData;

            return [
                'log'          => $skipLog,
                'contract'     => $contractData,
                'resultAction' => 'skip',
            ];
        }//end if

        // The object has changed, oke let do mappig and set metadata.
        $contractData['originHash']        = $originHash;
        $contractData['sourceLastChanged'] = (new DateTime())->format('c');
        $contractData['sourceLastChecked'] = (new DateTime())->format('c');
        // Test-mode no-write contract (#1008): keep the mid-flight metadata
        // update in-memory only.
        if ($isTest === false) {
            $synchronizationContract = $this->orObjectService->saveObject(
                object: $contractData,
                register: 'openconnector',
                schema: 'synchronization_contract',
                uuid: $synchronizationContract->getUuid()
            );
            $contractData            = $synchronizationContract->getObject();
        }

        // Execute mapping if found.
        $objectBeforeMapping = $object;
        if ($sourceTargetMapping !== null) {
            $flowToken->setSyncOutputOriginal($object);

            $object = $this->mappingService->executeMapping(mapping: $sourceTargetMapping, input: $object);
            $flowToken->setSyncOutputAmended($object);
        }

        if ($contractLogData !== null) {
            // Update the in-memory contract-log accumulator only. Persistence
            // is deferred to the write-once finalize (#1007).
            $contractLogData['target'] = $object;
        }

        $object = $this->replaceRelatedOriginIds(
            object: $object,
            config: ($sourceConfig['idsToReplaceWithTargetIdsBeforeRules'] ?? []),
            replaceIdWithTargetId: true
        );
        $flowToken->setSyncOutputAmended($object);

        if (empty($syncData['actions'] ?? []) === false) {
            $object = $this->processRules(
                synchronization: $synchronization,
                data: $object,
                timing: 'before',
                flowToken: $flowToken
            );
            $flowToken->setSyncOutputAmended($object);
        }

        // Set the target hash.
        $targetHash = md5(serialize($object));

        $contractData['targetHash']        = $targetHash;
        $contractData['targetLastChanged'] = (new DateTime())->format('c');
        $contractData['targetLastSynced']  = (new DateTime())->format('c');
        $contractData['sourceLastSynced']  = (new DateTime())->format('c');

        // The controller docblock for sync-test guarantees zero persistent writes
        // (#1008). Under $isTest=true we keep the freshly computed contract data
        // in-memory only — no saveObject, no contract-log buffering — so neither
        // a synchronization_contract row nor a synchronization_contract_log row
        // is created.
        if ($isTest === false) {
            $synchronizationContract = $this->orObjectService->saveObject(
                object: $contractData,
                register: 'openconnector',
                schema: 'synchronization_contract',
                uuid: $synchronizationContract->getUuid()
            );
            $contractData            = $synchronizationContract->getObject();
        }

        // Handle synchronization based on test mode.
        if ($isTest === true) {
            // Return test data without updating target. Deliberately DO NOT
            // bufferContractLog here — that would persist a contract-log row at
            // finalize() in violation of the documented test-run contract (#1008).
            if ($contractLogData !== null) {
                $contractLogData['targetResult'] = 'test';
                $contractLogData['expiry']       = $this->calculateExpires(
                        ...[$this->successRetention]
                    )?->format('c');
            }

            $testLog = $contractLogData;

            return [
                'log'          => $testLog,
                'contract'     => $contractData,
                'resultAction' => 'skip',
            ];
        }//end if

        // Update target and create log when not in test mode.
        $synchronizationContract = $this->updateTarget(
            synchronizationContract: $synchronizationContract,
            targetObject: $object,
            mutationType: $mutationType
        );
        $contractData            = $synchronizationContract->getObject();

        $targetType = $syncData['targetType'] ?? '';
        $sourceType = $syncData['sourceType'] ?? '';
        if ($targetType === 'register/schema') {
            [$registerId, $schemaId] = explode(separator: '/', string: ($syncData['targetId'] ?? '/'));
            $this->processRules(
                synchronization: $synchronization,
                data: array_merge($object, ['_objectBeforeMapping' => $objectBeforeMapping]),
                timing: 'after',
                objectId: ($contractData['targetId'] ?? null),
                registerId: $registerId,
                schemaId: $schemaId,
                flowToken: $flowToken
            );
        } else if ($targetType === 'api' && $sourceType === 'register/schema') {
            [$registerId, $schemaId] = explode(separator: '/', string: ($syncData['sourceId'] ?? '/'));
            $this->processRules(
                synchronization: $synchronization,
                data: array_merge($object, ['_objectBeforeMapping' => $objectBeforeMapping]),
                timing: 'after',
                objectId: ($contractData['originId'] ?? null),
                registerId: $registerId,
                schemaId: $schemaId,
                flowToken: $flowToken
            );
        }//end if

        // Finalize the accumulated contract-log payload (write-once flush
        // happens in finalizeSynchronizationLog after the parent sync-log row
        // is created — #1007).
        if ($contractLogData !== null) {
            $contractLogData['targetResult'] = ($contractData['targetLastAction'] ?? null);
            $contractLogData['expiry']       = $this->calculateExpires(
                    ...[$this->successRetention]
                )?->format('c');
            $this->bufferContractLog(contractLogData: $contractLogData);
        }

        $synchronizationContract = $this->orObjectService->saveObject(
            object: $contractData,
            register: 'openconnector',
            schema: 'synchronization_contract',
            uuid: $synchronizationContract->getUuid()
        );

        $finalLog = $contractLogData ?? [];

        // Update or create.
        return [
            'log'          => $finalLog,
            'contract'     => $synchronizationContract->getObject(),
            'resultAction' => 'update',
        ];

    }//end synchronizeContract()

    /**
     * Updates or deletes a target object in the Open Register system.
     *
     * @param ObjectEntity $synchronizationContract The synchronization contract being updated.
     * @param ObjectEntity $synchronization         The synchronization entity containing the target ID.
     * @param array|null   $targetObject            An optional array containing the data for the target object.
     * @param string|null  $action                  The action to perform: 'save' (default) or 'delete'.
     *
     * @return ObjectEntity The updated synchronization contract with the modified target ID.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function updateTargetOpenRegister(
        ObjectEntity $synchronizationContract,
        ObjectEntity $synchronization,
        ?array &$targetObject=[],
        ?string $action='save'
    ): ObjectEntity {
        // Setup the object service.
        $objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
        $syncData      = $synchronization->getObject();
        $contractData  = $synchronizationContract->getObject();
        $sourceConfig  = $this->callService->applyConfigDot($syncData['sourceConfig'] ?? []);

        // If we already have an id, we need to get the object and update it.
        if (isset($contractData['targetId']) === true && $contractData['targetId'] !== null) {
            $targetObject['id'] = $contractData['targetId'];
        }

        if (isset($sourceConfig['subObjects']) === true) {
            $targetObject = $this->updateIdsOnSubObjects(
                subObjectsConfig: $sourceConfig['subObjects'],
                synchronizationId: $synchronization->getUuid(),
                targetObject: $targetObject
            );
        }

        // Extract register and schema from the targetId.
        // The targetId needs to be filled in as: {registerId} + / + {schemaId} for example: 1/1.
        $targetId = $syncData['targetId'] ?? '/';
        list($register, $schema) = explode('/', $targetId);

        // Save the object to the target.
        switch ($action) {
            case 'save':
                if (isset($targetObject['id']) === true && ($contractData['targetId'] ?? null) === null) {
                    $contractData['targetId'] = $targetObject['id'];
                }

                $targetObject = $this->replaceRelatedOriginIds(
                    object: $targetObject,
                    config: ($sourceConfig['originIdsToReplace'] ?? [])
                );

                $target = $objectService->saveObject(
                    register: $register,
                    schema: $schema,
                    object: $targetObject,
                    uuid: ($contractData['targetId'] ?? null)
                );
                // Get the id from the target object.
                $contractData['targetId'] = $target->getUuid();

                // Handle sub-objects synchronization if sourceConfig is defined.
                if (isset($sourceConfig['subObjects']) === true) {
                    $targetObjectRendered = $objectService->renderEntity($target, ['all']);
                    $this->updateContractsForSubObjects(
                        subObjectsConfig: $sourceConfig['subObjects'],
                        synchronizationId: $synchronization->getUuid(),
                        targetObject: $targetObjectRendered
                    );
                }

                // Set target last action based on whether we're creating or updating.
                if ($contractData['targetId'] !== null) {
                    $contractData['targetLastAction'] = 'update';
                } else {
                    $contractData['targetLastAction'] = 'create';
                }
                break;
            case 'delete':
                // Use the scoped delete API (OR#1638) so a UUID collision across magic.
                // Tables cannot silently delete a foreign-scope object. Register and.
                // Schema are derived from $syncData['targetId'] above.
                $objectService->deleteObject(uuid: $contractData['targetId'], register: $register, schema: $schema);
                $contractData['targetId']         = null;
                $contractData['targetLastAction'] = 'delete';
                break;
        }//end switch

        $synchronizationContract = $this->orObjectService->saveObject(
            object: $contractData,
            register: 'openconnector',
            schema: 'synchronization_contract',
            uuid: $synchronizationContract->getUuid()
        );

        return $synchronizationContract;

    }//end updateTargetOpenRegister()

    /**
     * Recursively replaces 'originId' values with corresponding target IDs in the given object.
     *
     * @param array $object                The object array to process (can be nested).
     * @param array $config                A nested config tree indicating which keys to process and replace.
     * @param bool  $replaceIdWithTargetId If we need to replace id with target id found by origin id if configured.
     *
     * @return array The processed data with 'originId' replaced where applicable.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    public function replaceRelatedOriginIds(array $object, array $config, bool $replaceIdWithTargetId=false): array
    {
        foreach ($config as $key => $subConfig) {
            if (isset($object[$key]) === false) {
                continue;
            }

            if (is_array($object[$key]) === true
                && $this->isAssociativeArray(array: reset($object[$key])) === true
                && is_array($subConfig) === true
            ) {
                // It's a list of associative objects.
                foreach ($object[$key] as $i => $item) {
                    if (is_array($item) === true) {
                        $object[$key][$i] = $this->replaceRelatedOriginIds(
                            object: $item,
                            config: $subConfig,
                            replaceIdWithTargetId: $replaceIdWithTargetId
                        );
                    }
                }
            } else if ($this->isAssociativeArray(array: $object[$key]) === true && is_array($subConfig) === true) {
                // Single nested associative object.
                $object[$key] = $this->replaceRelatedOriginIds(
                    object: $object[$key],
                    config: $subConfig,
                    replaceIdWithTargetId: $replaceIdWithTargetId
                );
            } else if ($subConfig === 'true' && is_string($object[$key]) === true && $replaceIdWithTargetId === false) {
                // Leaf: value is a string, marked for replacement.
                $object[$key] = $this->replaceIdInString(value: $object[$key]);
            }//end if

            // Replace 'id' at this level if requested, demands originId to be set aswel.
            if ($replaceIdWithTargetId === true && $key === 'id'
                && isset($object['originId']) === true && is_string($object['originId']) === true
            ) {
                $targetId = $this->replaceIdInString(value: $object['originId']);
                if ($targetId !== null && $targetId !== $object['originId']) {
                    $object['id'] = $targetId;
                }
            }
        }//end foreach

        return $object;

    }//end replaceRelatedOriginIds()

    /**
     * Replaces a UUID within a string with a mapped target ID using the synchronization mapper.
     *
     * @param string $value The string potentially containing a UUID to replace.
     *
     * @return string The string with the UUID replaced if found and valid, otherwise the original string.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function replaceIdInString(string $value): string
    {
        // First check if we already can find object with origin id as is.
        $targetId = $this->findTargetIdByOriginId(originId: $value);
        if ($targetId !== null && $targetId !== $value) {
            return $targetId;
        }

        // If not a direct match, check for embedded UUID (used for uri relations).
        if (preg_match('/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/', $value, $matches) === 1
            && filter_var($value, FILTER_VALIDATE_URL) !== false
        ) {
            $originId = $matches[0];

            if (Uuid::isValid($originId) === true) {
                $targetId = $this->findTargetIdByOriginId(originId: $originId);

                if ($targetId !== null && $targetId !== $originId) {
                    return str_replace($originId, $targetId, $value);
                }
            }
        }

        return $value;

    }//end replaceIdInString()

    /**
     * Finds target ID by origin ID in synchronization contracts.
     *
     * @param string $originId The origin ID to look up.
     *
     * @return string|null The target ID if found, null otherwise.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function findTargetIdByOriginId(string $originId): ?string
    {
        $targetFilters = [
            'register' => 'openconnector',
            'schema'   => 'synchronization_contract',
            'originId' => $originId,
        ];
        $matches       = $this->orObjectService->findAll(config: ['filters' => $targetFilters]);
        $contracts     = $matches['results'] ?? $matches;
        if (empty($contracts) === false) {
            $contractData = $contracts[0]->getObject();
            return $contractData['targetId'] ?? null;
        }

        return null;

    }//end findTargetIdByOriginId()

    /**
     * Handles the synchronization of subObjects based on source configuration.
     *
     * @param array  $subObjectsConfig  The configuration for subObjects.
     * @param string $synchronizationId The ID of the synchronization.
     * @param array  $targetObject      The target object containing subObjects to be processed.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function updateContractsForSubObjects(array $subObjectsConfig, string $synchronizationId, array $targetObject): void
    {
        foreach ($subObjectsConfig as $propertyName => $subObjectConfig) {
            if (isset($targetObject[$propertyName]) === false) {
                continue;
            }

            $propertyData = $targetObject[$propertyName];

            // If property data is an array of subObjects, iterate and process.
            if (is_array($propertyData) === true && $this->isAssociativeArray(array: $propertyData) === true) {
                if (isset($propertyData['originId']) === true) {
                    $this->processSyncContract(synchronizationId: $synchronizationId, subObjectData: $propertyData);
                }

                // Recursively process any nested subObjects within the associative array.
                foreach ($propertyData as $key => $value) {
                    if (is_array($value) === true && isset($subObjectConfig['subObjects']) === true) {
                        $this->updateContractsForSubObjects(
                            subObjectsConfig: $subObjectConfig['subObjects'],
                            synchronizationId: $synchronizationId,
                            targetObject: [$key => $value]
                        );
                    }
                }
            }

            // Process if it's an indexed array (list) of associative arrays.
            if (is_array($propertyData) === true && $this->isAssociativeArray(array: $propertyData) === false) {
                foreach ($propertyData as $subObjectData) {
                    if (is_array($subObjectData) === true && isset($subObjectData['originId']) === true) {
                        $this->processSyncContract(synchronizationId: $synchronizationId, subObjectData: $subObjectData);
                    }

                    // Recursively process nested sub-objects.
                    if (is_array($subObjectData) === true && isset($subObjectConfig['subObjects']) === true) {
                        $this->updateContractsForSubObjects(
                            subObjectsConfig: $subObjectConfig['subObjects'],
                            synchronizationId: $synchronizationId,
                            targetObject: $subObjectData
                        );
                    }
                }
            }
        }//end foreach

    }//end updateContractsForSubObjects()

    /**
     * Processes a single synchronization contract for a subObject.
     *
     * @param string $synchronizationId The ID of the synchronization.
     * @param array  $subObjectData     The data of the subObject to process.
     *
     * @return void
     *
     * @throws \OCP\DB\Exception When the database layer raises an exception.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function processSyncContract(string $synchronizationId, array $subObjectData): void
    {
        $rawId    = ($subObjectData['id'] ?? null);
        $idLevel2 = null;
        if (is_array($rawId) === true) {
            $idLevel2 = ($rawId['id'] ?? null);
        }

        $idLevel3 = null;
        if (is_array($idLevel2) === true) {
            $idLevel3 = ($idLevel2['id'] ?? null);
        }

        $idLevel4 = null;
        if (is_array($idLevel3) === true) {
            $idLevel4 = ($idLevel3['id'] ?? null);
        }

        $id          = ($idLevel4 ?? $idLevel3 ?? $idLevel2 ?? $rawId);
        $originId    = $subObjectData['originId'] ?? null;
        $subContract = null;
        if ($originId !== null) {
            $subContractFilters = [
                'register' => 'openconnector',
                'schema'   => 'synchronization_contract',
                'originId' => $originId,
            ];
            $contractMatches    = $this->orObjectService->findAll(config: ['filters' => $subContractFilters]);
            $contractList       = $contractMatches['results'] ?? $contractMatches;
            if (empty($contractList) === false) {
                $subContract = $contractList[0];
            } else {
                $subContract = null;
            }
        }

        $contractData = [
            'synchronizationId' => $synchronizationId,
            'originId'          => $originId,
            'targetId'          => $id,
            'targetHash'        => md5(serialize($subObjectData)),
            'targetLastChanged' => (new DateTime())->format('c'),
            'targetLastSynced'  => (new DateTime())->format('c'),
            'sourceLastSynced'  => (new DateTime())->format('c'),
        ];

        if ($subContract === null) {
            $subContract = $this->orObjectService->saveObject(
                object: $contractData,
                register: 'openconnector',
                schema: 'synchronization_contract'
            );
        } else {
            $existing     = $subContract->getObject();
            $contractData = array_merge($existing, $contractData);
            $subContract  = $this->orObjectService->saveObject(
                object: $contractData,
                register: 'openconnector',
                schema: 'synchronization_contract',
                uuid: $subContract->getUuid()
            );
        }//end if

        $this->orObjectService->saveObject(
            object: [
                'synchronizationId'         => ($subContract->getObject()['synchronizationId'] ?? null),
                'synchronizationContractId' => $subContract->getUuid(),
                'target'                    => $subObjectData,
                'expires'                   => $this->calculateExpires(...[$this->successRetention, $this->successRetention])?->format('c'),
            ],
            register: 'openconnector',
            schema: 'synchronization_contract_log'
        );

    }//end processSyncContract()

    /**
     * Checks if an array is associative.
     *
     * @param mixed $array The array to check.
     *
     * @return bool True if the array is associative, false otherwise.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function isAssociativeArray(mixed $array): bool
    {
        // Check if the array is associative.
        return (is_array($array) === true && count(array_filter(array_keys($array), 'is_string')) > 0);

    }//end isAssociativeArray()

    /**
     * Processes subObjects update their arrays with existing targetId's so OpenRegister can update the objects instead of duplicate them.
     *
     * @param array  $subObjectsConfig  The configuration for subObjects.
     * @param string $synchronizationId The ID of the synchronization.
     * @param array  $targetObject      The target object containing subObjects to be processed.
     *
     * @return array The updated target object with IDs updated on subObjects.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function updateIdsOnSubObjects(array $subObjectsConfig, string $synchronizationId, array $targetObject): array
    {
        $targetObject = $this->updateIdOnSubObject(synchronizationId: $synchronizationId, subObject: $targetObject);

        foreach ($targetObject as $propertyName => $value) {
            if (is_array($value) === false) {
                continue;
            }

            if ($this->isAssociativeArray(array: $value) === true) {
                $targetObject[$propertyName] = $this->updateIdsOnSubObjects(
                    subObjectsConfig: $subObjectsConfig,
                    synchronizationId: $synchronizationId,
                    targetObject: $value
                );
                continue;
            }

            if (is_array(reset($value)) === true && $this->isAssociativeArray(array: reset($value)) === true) {
                foreach ($value as $key => $subValue) {
                    if (is_array($subValue) === false) {
                        continue;
                    }

                    $targetObject[$propertyName][$key] = $this->updateIdsOnSubObjects(
                        subObjectsConfig: $subObjectsConfig,
                        synchronizationId: $synchronizationId,
                        targetObject: $subValue
                    );
                }
            }
        }//end foreach

        return $targetObject;

    }//end updateIdsOnSubObjects()

    /**
     * Updates the ID of a single subObject based on its synchronization contract so OpenRegister can update the object .
     *
     * @param string $synchronizationId The ID of the synchronization.
     * @param array  $subObject         The subObject to update.
     *
     * @return array The updated subObject with the ID set based on the synchronization contract.
     * @throws MultipleObjectsReturnedException
     * @throws \OCP\DB\Exception
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function updateIdOnSubObject(string $synchronizationId, array $subObject): array
    {
        if (isset($subObject['originId']) === true) {
            $idFilters       = [
                'register'          => 'openconnector',
                'schema'            => 'synchronization_contract',
                'synchronizationId' => $synchronizationId,
                'originId'          => $subObject['originId'],
            ];
            $contractMatches = $this->orObjectService->findAll(config: ['filters' => $idFilters]);
            $contractList    = $contractMatches['results'] ?? $contractMatches;
            if (empty($contractList) === false) {
                $contractData    = $contractList[0]->getObject();
                $subObject['id'] = ($contractData['targetId'] ?? null);
            }
        }

        return $subObject;

    }//end updateIdOnSubObject()

    /**
     * Write the data to the target.
     *
     * @param ObjectEntity $synchronizationContract The contract to update.
     * @param array|null   $targetObject            The data payload for the target.
     * @param string|null  $action                  Action to perform; defaults to 'save'.
     * @param string|null  $mutationType            Mutation type for single object syncs.
     *
     * @return ObjectEntity The updated contract entity.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws GuzzleException             When a remote HTTP call fails.
     * @throws LoaderError                 When the Twig loader fails.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws SyntaxError                 When a Twig template has a syntax error.
     * @throws \OCP\DB\Exception           When the database layer raises an exception.
     * @throws Exception                   For unsupported target types.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    public function updateTarget(
        ObjectEntity $synchronizationContract,
        ?array &$targetObject=[],
        ?string $action='save',
        ?string $mutationType=null
    ): ObjectEntity {
        // The function can be called solo so let's make sure we have the full synchronization object.
        $contractData    = $synchronizationContract->getObject();
        $synchronization = $this->orObjectService->find(
            id: ($contractData['synchronizationId'] ?? null),
            register: 'openconnector',
            schema: 'synchronization'
        );

        if ($synchronization === null) {
            throw new Exception('Synchronization not found for contract: '.($contractData['synchronizationId'] ?? 'null'));
        }

        $syncData = $synchronization->getObject();
        $type     = $syncData['targetType'] ?? '';

        if ($mutationType === 'delete') {
            $action = 'delete';
        }

        switch ($type) {
            case 'register/schema':
                $synchronizationContract = $this->updateTargetOpenRegister(
                    synchronizationContract: $synchronizationContract,
                    synchronization: $synchronization,
                    targetObject: $targetObject,
                    action: $action
                );
                break;
            case 'api':
                $targetConfig            = $syncData['targetConfig'] ?? [];
                $synchronizationContract = $this->writeObjectToTarget(
                    synchronization: $synchronization,
                    contract: $synchronizationContract,
                    endpoint: ($targetConfig['endpoint'] ?? ''),
                    targetObject: $targetObject,
                    mutationType: $mutationType
                );
                break;
            case 'database':
                // @todo: implement.
                break;
            default:
                throw new Exception("Unsupported target type: $type");
        }//end switch

        return $synchronizationContract;

    }//end updateTarget()

    /**
     * Get all the object from a source.
     *
     * @param ObjectEntity $synchronization The synchronization providing source configuration.
     * @param bool|null    $isTest          Test mode flag.
     * @param array|null   $data            Optional payload data.
     *
     * @return array The fetched source objects.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws GuzzleException             When a remote HTTP call fails.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws \OCP\DB\Exception           When the database layer raises an exception.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    public function getAllObjectsFromSource(ObjectEntity $synchronization, ?bool $isTest=false, ?array $data=null): array
    {
        $objects  = [];
        $syncData = $synchronization->getObject();
        $type     = $syncData['sourceType'] ?? '';

        switch ($type) {
            case 'register/schema':
                // @todo: implement.
                break;
            case 'api':
                $objects = $this->getAllObjectsFromApi(synchronization: $synchronization, isTest: $isTest, data: $data);
                break;
            case 'database':
                // @todo: implement.
                break;
        }

        return $objects;

    }//end getAllObjectsFromSource()

    /**
     * Fetches all objects from an API source for a given synchronization.
     *
     * @param ObjectEntity $synchronization The synchronization object containing source information.
     * @param bool|null    $isTest          If true, only a single object is returned for testing purposes.
     * @param array|null   $data            The data to add to synchronize.
     *
     * @return array An array of all objects retrieved from the API.
     *
     * @throws GuzzleException   When a remote HTTP call fails.
     * @throws LoaderError       When the Twig loader fails.
     * @throws SyntaxError       When a Twig template has a syntax error.
     * @throws \OCP\DB\Exception When the database layer raises an exception.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    public function getAllObjectsFromApi(ObjectEntity $synchronization, ?bool $isTest=false, ?array $data=null): array
    {
        $syncData = $synchronization->getObject();
        $source   = $this->orObjectService->find(
            id: ($syncData['sourceId'] ?? null),
            register: 'openconnector',
            schema: 'source'
        );

        // Check rate limit before proceeding.
        $this->checkRateLimit(source: $source);

        // Extract source configuration.
        $sourceConfig = $this->callService->applyConfigDot($syncData['sourceConfig'] ?? []);
        // TODO; This is the second time this function is called in the synchonysation flow, needs further refactoring investigation.
        $endpoint = $sourceConfig['endpoint'] ?? '';
        if (is_string($endpoint) === true
            && str_contains($endpoint, '{{') === true
            && str_contains($endpoint, '}}') === true
        ) {
            $contextData = ($data ?? []);
            // After-rule responses pass the OR object (`{id, @self}`) here; lift @self.relations.
            // To the top level so simple `{{ data.zaaknummer }}` lookups still resolve.
            if (isset($contextData['@self']['relations']) === true && is_array($contextData['@self']['relations']) === true) {
                $contextData = array_merge($contextData['@self']['relations'], $contextData);
            }

            $endpoint = $this->mappingService->renderTemplateString(
                template: $endpoint,
                context: ['data' => $contextData]
            );
        }

        $headers        = $sourceConfig['headers'] ?? [];
        $query          = $sourceConfig['query'] ?? [];
        $usesPagination = true;
        if (isset($sourceConfig['usesPagination']) === true) {
            $usesPagination = filter_var($sourceConfig['usesPagination'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if (isset($sourceConfig['resultsPosition']) === true && $sourceConfig['resultsPosition'] === '_object') {
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
        if ($source !== null) {
            $sourceData = $source->getObject();
        } else {
            $sourceData = [];
        }

        // Start with the current page.
        if (isset($sourceData['rateLimitLimit']) === true && $sourceData['rateLimitLimit'] !== null) {
            $currentPage = $syncData['currentPage'] ?? 1;
        }

        // Fetch all pages recursively.
        $objects = $this->fetchAllPages(
            source: $source,
            endpoint: $endpoint,
            config: $config,
            synchronization: $synchronization,
            currentPage: $currentPage,
            isTest: $isTest,
            usesPagination: $usesPagination
        );

        // For non-`_object` sources, an associative array means the source returned a single.
        // Record at the root and we need to wrap it for the downstream foreach. For.
        // `_object` sources the wrap is the caller's responsibility and doing it twice.
        // Produces a [[…items]] payload that loses every row.
        if (($sourceConfig['resultsPosition'] ?? null) !== '_object' && array_is_list($objects) === false) {
            $objects = [$objects];
        }

        // Merge additional data into each object if $data is provided.
        if ($data !== null
            && empty($data) === false
            && $useDataAsRequestBody === false
        ) {
            foreach ($objects as &$object) {
                $object = array_merge($object, $data);
            }
        }

        // Reset the current page after synchronization if not a test.
        if ($isTest === false) {
            $syncData['currentPage'] = 1;
            $this->orObjectService->saveObject(
                object: $syncData,
                register: 'openconnector',
                schema: 'synchronization',
                uuid: $synchronization->getUuid()
            );
        }

        return $objects;

    }//end getAllObjectsFromApi()

    /**
     * Fetches all pages from a paginated API endpoint with optimized sequential processing.
     *
     * @param ObjectEntity|null $source           The data source configuration.
     * @param string            $endpoint         The API endpoint to fetch from.
     * @param array             $config           The request configuration.
     * @param ObjectEntity      $synchronization  The synchronization context.
     * @param int               $currentPage      The starting page number.
     * @param bool              $isTest           Whether this is a test run (returns only first object).
     * @param bool|null         $usesNextEndpoint Whether the API uses next endpoint URLs.
     * @param bool|null         $usesPagination   Whether pagination is enabled.
     *
     * @return array Combined objects from all pages.
     *
     * @throws TooManyRequestsHttpException When rate limit is exceeded.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function fetchAllPages(
        ?ObjectEntity $source,
        string $endpoint,
        array $config,
        ObjectEntity $synchronization,
        int $currentPage,
        bool $isTest=false,
        ?bool $usesNextEndpoint=null,
        ?bool $usesPagination=true
    ): array {
        // Return objects if we don't paginate.
        if ($usesPagination === false) {
            return $this->fetchSinglePage(
                source: $source,
                endpoint: $endpoint,
                config: $config,
                synchronization: $synchronization
            );
        }

        // Use optimized sequential fetching (much faster than the original recursive approach).
        return $this->fetchAllPagesOptimized(
            source: $source,
            endpoint: $endpoint,
            config: $config,
            synchronization: $synchronization,
            currentPage: $currentPage,
            isTest: $isTest,
            usesNextEndpoint: $usesNextEndpoint
        );

    }//end fetchAllPages()

    /**
     * Fetches all pages using an optimized sequential approach.
     *
     * @param ObjectEntity|null $source           The data source configuration.
     * @param string            $endpoint         The API endpoint to fetch from.
     * @param array             $config           The request configuration.
     * @param ObjectEntity      $synchronization  The synchronization context.
     * @param int               $currentPage      The starting page number.
     * @param bool              $isTest           Whether this is a test run.
     * @param bool|null         $usesNextEndpoint Whether the API uses next endpoint URLs.
     *
     * @return array Combined objects from all pages.
     *
     * @throws TooManyRequestsHttpException When rate limit is exceeded.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function fetchAllPagesOptimized(
        ?ObjectEntity $source,
        string $endpoint,
        array $config,
        ObjectEntity $synchronization,
        int $currentPage,
        bool $isTest=false,
        ?bool $usesNextEndpoint=null
    ): array {
        $allObjects      = [];
        $currentEndpoint = $endpoint;
        $syncData        = $synchronization->getObject();
        $sourceConfig    = $syncData['sourceConfig'] ?? [];
        $maxPages        = $sourceConfig['maxPages'] ?? $this::DEFAULT_MAX_PAGES;
        $pageCount       = 0;
        $startPage       = $currentPage;
        // Resume cursor — track the highest currentPage we have stepped to so
        // we can write it back ONCE (on rate-limit / exception or at the end of
        // the loop) instead of issuing one saveObject per page (#1010).
        $persistedCurrentPage = $currentPage;

        try {
            for ($i = 0; $i < $maxPages; $i++) {
                // Fetch the current page.
                $pageData    = $this->fetchSinglePageData(
                    source: $source,
                    endpoint: $currentEndpoint,
                    config: $config,
                    synchronization: $synchronization
                );
                $pageObjects = $pageData['objects'];
                $pageCount++;

                // If test mode is enabled, return only the first object from the first page.
                if ($isTest === true && empty($pageObjects) === false) {
                    return [$pageObjects[0]];
                }

                // If no objects found, we've reached the end.
                if (empty($pageObjects) === true) {
                    break;
                }

                // Add objects to our collection.
                // Note: we still accumulate the full set across pages so the
                // existing extern-to-intern pipeline (which iterates the
                // returned array in synchronizeExternToIntern) keeps working
                // unchanged. The legacy "page-by-page upsert" architectural
                // change is out of scope for this fix — #1010 punts that to a
                // follow-up; here we eliminate the per-page write amplification
                // which is the actively-painful half of the bug.
                $allObjects = array_merge($allObjects, $pageObjects);

                // Determine the next page URL/config.
                $nextInfo = $this->getNextPageInfo(
                    source: $source,
                    currentEndpoint: $currentEndpoint,
                    config: $config,
                    synchronization: $synchronization,
                    currentPage: $currentPage,
                    result: $pageData['result'],
                    usesNextEndpoint: $usesNextEndpoint
                );

                if ($nextInfo === null) {
                    // No more pages.
                    break;
                }

                // Update for next iteration.
                $currentEndpoint  = $nextInfo['endpoint'];
                $config           = $nextInfo['config'];
                $currentPage      = $nextInfo['page'];
                $usesNextEndpoint = $nextInfo['usesNextEndpoint'];
                $persistedCurrentPage = $currentPage;
            }//end for
        } finally {
            // Persist the final currentPage ONCE for resume semantics — only
            // when it actually advanced. This collapses what was previously N
            // OR write round-trips into a single write per sync pass (#1010).
            // The finally block also covers the rate-limit / exception paths so
            // an interrupted run still records its resume point.
            if ($persistedCurrentPage !== $startPage) {
                $syncData['currentPage'] = $persistedCurrentPage;
                $this->orObjectService->saveObject(
                    object: $syncData,
                    register: 'openconnector',
                    schema: 'synchronization',
                    uuid: $synchronization->getUuid()
                );
            }
        }//end try

        return $allObjects;

    }//end fetchAllPagesOptimized()

    /**
     * Gets information for the next page in pagination.
     *
     * @param ObjectEntity|null $source           The data source configuration.
     * @param string            $currentEndpoint  The current page endpoint.
     * @param array             $config           The current request configuration.
     * @param ObjectEntity      $synchronization  The synchronization context.
     * @param int               $currentPage      The current page number.
     * @param array             $result           The current page response payload.
     * @param bool|null         $usesNextEndpoint Whether the API uses next endpoint URLs.
     *
     * @return array|null Next page information or null if no more pages.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function getNextPageInfo(
        ?ObjectEntity $source,
        string $currentEndpoint,
        array $config,
        ObjectEntity $synchronization,
        int $currentPage,
        array $result,
        ?bool $usesNextEndpoint=null
    ): ?array {
        if (empty($result) === true) {
            return null;
        }

        // Determine pagination method if not already known.
        if ($usesNextEndpoint === null && array_key_exists('next', $result) === true) {
            $usesNextEndpoint = true;
        }

        if ($source !== null) {
            $sourceData = $source->getObject();
        } else {
            $sourceData = [];
        }

        if ($usesNextEndpoint === true) {
            // Use next endpoint URL pagination.
            $nextEndpoint = $this->getNextEndpoint(
                body: $result,
                url: ($sourceData['location'] ?? ''),
                currentEndpoint: $currentEndpoint
            );
            if ($nextEndpoint === null || $nextEndpoint === $currentEndpoint) {
                // No more pages.
                return null;
            }

            return [
                'endpoint'         => $nextEndpoint,
                'config'           => $config,
                'page'             => ($currentPage + 1),
                'usesNextEndpoint' => true,
            ];
        }

        // Use page number pagination.
        $nextPage   = ($currentPage + 1);
        $syncData   = $synchronization->getObject();
        $nextConfig = $this->getNextPage(
            config: $config,
            sourceConfig: ($syncData['sourceConfig'] ?? []),
            currentPage: $nextPage
        );

        // Base endpoint stays the same.
        return [
            'endpoint'         => $currentEndpoint,
            'config'           => $nextConfig,
            'page'             => $nextPage,
            'usesNextEndpoint' => false,
        ];

    }//end getNextPageInfo()

    /**
     * Fetches a single page synchronously.
     *
     * @param ObjectEntity|null $source          The data source configuration.
     * @param string            $endpoint        The page endpoint to fetch.
     * @param array             $config          The request configuration.
     * @param ObjectEntity      $synchronization The synchronization context.
     *
     * @return array Objects from the page.
     *
     * @throws TooManyRequestsHttpException When rate limit is exceeded.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function fetchSinglePage(?ObjectEntity $source, string $endpoint, array $config, ObjectEntity $synchronization): array
    {
        $pageData = $this->fetchSinglePageData(
            source: $source,
            endpoint: $endpoint,
            config: $config,
            synchronization: $synchronization
        );

        return $pageData['objects'];

    }//end fetchSinglePage()

    /**
     * Fetches and parses a single page.
     *
     * @param ObjectEntity|null $source          The data source configuration.
     * @param string            $endpoint        The page endpoint to fetch.
     * @param array             $config          The request configuration.
     * @param ObjectEntity      $synchronization The synchronization context.
     *
     * @return array{objects: array, result: array} The decoded objects + raw response.
     *
     * @throws TooManyRequestsHttpException When rate limit is exceeded.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function fetchSinglePageData(?ObjectEntity $source, string $endpoint, array $config, ObjectEntity $synchronization): array
    {
        // Make the API call.
        $callLog     = $this->callService->call(source: $source, endpoint: $endpoint, config: $config);
        $callLogData = $callLog->getObject();
        $response    = $callLogData['response'] ?? null;

        // Check for rate limiting.
        if ($response === null && ($callLogData['statusCode'] ?? 0) === 429) {
            throw new TooManyRequestsHttpException(
                message: "Rate Limit on Source exceeded.",
                code: 429,
                headers: $this->getRateLimitHeaders(source: $source)
            );
        }

        if ($response === null) {
            return [
                'objects' => [],
                'result'  => [],
            ];
        }

        $body = $response['body'] ?? '';

        // Try parsing the response body in different formats, starting with JSON.
        $result = json_decode($body, true);

        // If JSON parsing failed, try XML.
        if (empty($result) === true) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);

            if ($xml !== false) {
                $result = $this->xmlToArray(xml: $xml);
            }
        }

        if (empty($result) === true) {
            return [
                'objects' => [],
                'result'  => [],
            ];
        }

        // Process and return the objects from this page.
        return [
            'objects' => $this->getAllObjectsFromArray(array: $result, synchronization: $synchronization),
            'result'  => $result,
        ];

    }//end fetchSinglePageData()

    /**
     * Fallback method for sequential page fetching.
     *
     * @param ObjectEntity|null $source           The data source configuration.
     * @param string            $endpoint         The API endpoint to fetch from.
     * @param array             $config           The request configuration.
     * @param ObjectEntity      $synchronization  The synchronization context.
     * @param int               $currentPage      The starting page number.
     * @param bool              $isTest           Whether this is a test run.
     * @param bool|null         $usesNextEndpoint Whether the API uses next endpoint URLs.
     *
     * @return array Combined objects from all pages.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function fetchAllPagesSequential(
        ?ObjectEntity $source,
        string $endpoint,
        array $config,
        ObjectEntity $synchronization,
        int $currentPage,
        bool $isTest=false,
        ?bool $usesNextEndpoint=null
    ): array {
        $allObjects      = [];
        $currentEndpoint = $endpoint;
        // Safety limit.
        $maxPages = 50;
        if ($source !== null) {
            $sourceData = $source->getObject();
        } else {
            $sourceData = [];
        }

        $syncData = $synchronization->getObject();

        for ($i = 0; $i < $maxPages; $i++) {
            $pageData    = $this->fetchSinglePageData(
                source: $source,
                endpoint: $currentEndpoint,
                config: $config,
                synchronization: $synchronization
            );
            $pageObjects = $pageData['objects'];

            // If test mode is enabled, return only the first object.
            if ($isTest === true && empty($pageObjects) === false) {
                return [$pageObjects[0]];
            }

            if (empty($pageObjects) === true) {
                break;
            }

            $allObjects = array_merge($allObjects, $pageObjects);

            $result = $pageData['result'];
            if (empty($result) === true) {
                break;
            }

            // Determine pagination method.
            if ($usesNextEndpoint === null && array_key_exists('next', $result) === true) {
                $usesNextEndpoint = true;
            }

            if ($usesNextEndpoint === true) {
                $nextEndpoint = $this->getNextEndpoint(
                    body: $result,
                    url: ($sourceData['location'] ?? ''),
                    currentEndpoint: $currentEndpoint
                );
                if ($nextEndpoint === null || $nextEndpoint === $currentEndpoint) {
                    break;
                }

                $currentEndpoint = $nextEndpoint;
            } else {
                $currentPage++;
                $config = $this->getNextPage(
                    config: $config,
                    sourceConfig: ($syncData['sourceConfig'] ?? []),
                    currentPage: $currentPage
                );
            }
        }//end for

        return $allObjects;
    }//end fetchAllPagesSequential()

    /**
     * Checks if the source has exceeded its rate limit and throws an exception if true.
     *
     * @param ObjectEntity|null $source The source object containing rate limit details.
     *
     * @return void
     *
     * @throws TooManyRequestsHttpException When the source rate-limit is exceeded.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function checkRateLimit(?ObjectEntity $source): void
    {
        if ($source === null) {
            return;
        }

        $sourceData = $source->getObject();
        if (isset($sourceData['rateLimitRemaining']) === true && $sourceData['rateLimitRemaining'] !== null
            && isset($sourceData['rateLimitReset']) === true && $sourceData['rateLimitReset'] !== null
            && $sourceData['rateLimitRemaining'] <= 0
            && $sourceData['rateLimitReset'] > time()
        ) {
            throw new TooManyRequestsHttpException(
                message: "Rate Limit on Source has been exceeded. Canceling synchronization...",
                code: 429,
                headers: $this->getRateLimitHeaders(source: $source)
            );
        }

    }//end checkRateLimit()

    /**
     * Retrieves rate limit information from a given source and formats it as HTTP headers.
     *
     * @param ObjectEntity|null $source The source object containing rate limit details.
     *
     * @return array An associative array of rate limit headers.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function getRateLimitHeaders(?ObjectEntity $source): array
    {
        if ($source !== null) {
            $sourceData = $source->getObject();
        } else {
            $sourceData = [];
        }

        return [
            'X-RateLimit-Limit'     => ($sourceData['rateLimitLimit'] ?? null),
            'X-RateLimit-Remaining' => ($sourceData['rateLimitRemaining'] ?? null),
            'X-RateLimit-Reset'     => ($sourceData['rateLimitReset'] ?? null),
            'X-RateLimit-Used'      => 0,
            'X-RateLimit-Window'    => ($sourceData['rateLimitWindow'] ?? null),
        ];

    }//end getRateLimitHeaders()

    /**
     * Updates the API request configuration with pagination details for the next page.
     *
     * @param array $config       The current request configuration.
     * @param array $sourceConfig The source configuration containing pagination settings.
     * @param int   $currentPage  The current page number for pagination.
     *
     * @return array Updated configuration with pagination settings.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function getNextPage(array $config, array $sourceConfig, int $currentPage): array
    {
        $config['pagination'] = [
            'paginationQuery' => $sourceConfig['paginationQuery'] ?? 'page',
            'page'            => $currentPage,
        ];

        return $config;
    }//end getNextPage()

    /**
     * Extracts the next API endpoint for pagination from the response body.
     *
     * @param array       $body            The decoded JSON response body from the API.
     * @param string      $url             The base URL of the API source.
     * @param string|null $currentEndpoint Optional current endpoint to preserve missing query params.
     *
     * @return string|null The next endpoint URL if available, or null if there is no next page.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    private function getNextEndpoint(array $body, string $url, ?string $currentEndpoint=null): ?string
    {
        $nextLink = $this->getNextlinkFromCall(body: $body);
        if ($nextLink === null || $nextLink === '') {
            return null;
        }

        if (str_starts_with($nextLink, $url) === true) {
            $nextLink = substr($nextLink, strlen($url));
        }

        // Preserve missing query params from current endpoint (e.g. expand=...).
        // When the API next link only contains paging information.
        if ($currentEndpoint !== null) {
            $nextParts    = parse_url($nextLink);
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

            $path  = $nextParts['path'] ?? $nextLink;
            $query = http_build_query($nextQuery);

            if ($query !== '') {
                return $path.'?'.$query;
            }

            return $path;
        }//end if

        return $nextLink;

    }//end getNextEndpoint()

    /**
     * Retrieves the next link for pagination from the API response body.
     *
     * @param array $body The decoded JSON body of the API response.
     *
     * @return string|null The URL for the next page of results, or null if there is no next page.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    public function getNextlinkFromCall(array $body): ?string
    {
        return $body['next'] ?? null;
    }//end getNextlinkFromCall()

    /**
     * Extracts all objects from the API response body.
     *
     * @param array        $array           The decoded JSON body of the API response.
     * @param ObjectEntity $synchronization The synchronization object containing source configuration.
     *
     * @return array An array of items extracted from the response body.
     *
     * @throws Exception When the position of objects in the return body cannot be determined.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-2
     */
    public function getAllObjectsFromArray(array $array, ObjectEntity $synchronization): array
    {
        // Get the source configuration from the synchronization object.
        $syncData     = $synchronization->getObject();
        $sourceConfig = $syncData['sourceConfig'] ?? [];

        // Check if a specific objects position is defined in the source configuration.
        if (empty($sourceConfig['resultsPosition']) === false) {
            $position = $sourceConfig['resultsPosition'];
            // If position is root, return the array.
            if ($position === '_root' || $position === '_object') {
                return $array;
            }

            // Use Dot notation to access nested array elements.
            $dot = new Dot($array);
            if ($dot->has($position) === true) {
                // Return the objects at the specified position.
                return $dot->get($position);
            }

            // @todo log error.
            // Throw an exception if the specified position doesn't exist.
            return [];
        }

        // Define common keys to check for objects.
        $commonKeys = ['items', 'result', 'results'];

        // Loop through common keys and return first match found.
        foreach ($commonKeys as $key) {
            if (isset($array[$key]) === true) {
                return $array[$key];
            }
        }

        // If no objects can be found, throw an exception.
        throw new Exception("Cannot determine the position of objects in the return body.");

    }//end getAllObjectsFromArray()

    /**
     * Write an created, updated or deleted object to an external target.
     *
     * @param ObjectEntity $synchronization The synchronization to run.
     * @param ObjectEntity $contract        The contract to enforce.
     * @param string       $endpoint        The endpoint to write the object to.
     * @param array|null   $targetObject    Update referenced targetObject so we can return response here.
     * @param string|null  $mutationType    Single object mutation type: 'create', 'update' or 'delete'.
     *
     * @return ObjectEntity The updated contract.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws GuzzleException             When a remote HTTP call fails.
     * @throws LoaderError                 When the Twig loader fails.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws SyntaxError                 When a Twig template has a syntax error.
     * @throws \OCP\DB\Exception           When the database layer raises an exception.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function writeObjectToTarget(
        ObjectEntity $synchronization,
        ObjectEntity $contract,
        string $endpoint,
        ?array &$targetObject=null,
        ?string $mutationType=null
    ): ObjectEntity {
        $syncData     = $synchronization->getObject();
        $contractData = $contract->getObject();
        $targetId     = $contractData['targetId'] ?? null;
        $target       = $this->orObjectService->find(
            id: ($syncData['targetId'] ?? null),
            register: 'openconnector',
            schema: 'source'
        );
        if ($target !== null) {
            $targetData = $target->getObject();
        } else {
            $targetData = [];
        }

        if ($targetObject !== null) {
            $object = $targetObject;
        } else {
            $object = [];
        }

        $sourceId = $syncData['sourceId'] ?? null;
        if (($syncData['sourceType'] ?? '') === 'register/schema' && ($contractData['originId'] ?? null) !== null) {
            $sourceIds = explode(separator: '/', string: $sourceId);

            if ($targetObject === null) {
                $openRegisters = $this->objectService->getOpenRegisters();
                if ($openRegisters !== null) {
                    $openRegisters->setRegister($sourceIds[0]);
                    $openRegisters->setSchema($sourceIds[1]);
                    $object = $openRegisters->find(
                        id: $contractData['originId'],
                    )->jsonSerialize();
                }
            }
        }

        $targetConfig   = $this->callService->applyConfigDot($syncData['targetConfig'] ?? []);
        $targetLocation = $targetData['location'] ?? '';

        if ($targetLocation !== '' && str_starts_with($endpoint, $targetLocation) === true) {
            $endpoint = str_replace(search: $targetLocation, replace: '', subject: $endpoint);
        }

        if ($mutationType === 'delete') {
            $method = 'DELETE';

            // @todo check for {{targetId}} in endpoint and replace.
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
                $deleteMapping        = $this->mappingService->getMapping($targetConfig['deleteMapping']);
                $targetConfig['json'] = $this->mappingService->executeMapping(mapping: $deleteMapping, input: $object);
            }

            $callResponse = $this->callService->call(
                source: $target,
                endpoint: $endpoint,
                method: $method,
                config: $targetConfig
            );
            $response     = $callResponse->getObject()['response'] ?? [];

            $contractData['targetHash'] = md5(serialize($response['body'] ?? ''));
            $contractData['targetId']   = null;
            $contract = $this->orObjectService->saveObject(
                object: $contractData,
                register: 'openconnector',
                schema: 'synchronization_contract',
                uuid: $contract->getUuid()
            );

            return $contract;
        }//end if

        // @TODO For now only JSON APIs are supported.
        $targetConfig['json'] = $object;

        if ($targetId === null) {
            if (isset($targetConfig['idInRequestBody']) === true) {
                $targetId = $targetConfig['json'][$targetConfig['idInRequestBody']];
            }

            $callResponse = $this->callService->call(
                source: $target,
                endpoint: $endpoint,
                method: 'POST',
                config: $targetConfig
            );
            $response     = $callResponse->getObject()['response'] ?? [];

            $body = json_decode($response['body'] ?? '', true);

            $bodyDot = new Dot($body ?? []);

            if (isset($targetConfig['idPosition']) === true) {
                $targetId = $bodyDot->get($targetConfig['idPosition']);
            } else if (isset($targetConfig['idposition']) === true) {
                // Backwards compatible if older sync still use idposition (lowercase).
                $targetId = $bodyDot->get($targetConfig['idposition']);
            } else if (isset($body['id']) === true) {
                $targetId = $body['id'];
            } else {
                throw new Exception('Could not determine an id from target synchronization');
            }

            $contractData['targetId'] = $targetId;
            $contract = $this->orObjectService->saveObject(
                object: $contractData,
                register: 'openconnector',
                schema: 'synchronization_contract',
                uuid: $contract->getUuid()
            );
            return $contract;
        }//end if

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

        $callResponse        = $this->callService->call(
            source: $target,
            endpoint: $endpoint,
            method: $method,
            config: $targetConfig
        );
        $response            = $callResponse->getObject()['response'] ?? [];
        $decodedResponseBody = json_decode($response['body'] ?? '', true);
        if (is_array($decodedResponseBody) === false) {
            $decodedResponseBody = [];
        }

        $body         = array_merge($decodedResponseBody, ['targetId' => $targetId]);
        $targetObject = $body;

        return $contract;

    }//end writeObjectToTarget()

    /**
     * Synchronize data to a target.
     *
     * @param ObjectEntity      $object                  The object to synchronize.
     * @param ObjectEntity|null $synchronizationContract If given: the synchronization contract that should be updated.
     * @param bool|null         $force                   If true, the object will be updated regardless of changes.
     * @param bool|null         $test                    If true, run as a test without persisting target writes.
     * @param ObjectEntity|null $log                     Optional log entity to update during the run.
     *
     * @return array The updated synchronizationContracts.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws LoaderError                 When the Twig loader fails.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws SyntaxError                 When a Twig template has a syntax error.
     * @throws \OCP\DB\Exception           When the database layer raises an exception.
     * @throws GuzzleException             When a remote HTTP call fails.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    public function synchronizeToTarget(
        ObjectEntity $object,
        ?ObjectEntity $synchronizationContract=null,
        ?bool $force=false,
        ?bool $test=false,
        ?ObjectEntity $log=null
    ): array {
        $objectId = $object->getUuid();

        if ($synchronizationContract === null) {
            $stcFilters      = [
                'register' => 'openconnector',
                'schema'   => 'synchronization_contract',
                'originId' => $objectId,
            ];
            $contractMatches = $this->orObjectService->findAll(config: ['filters' => $stcFilters]);
            $contractList    = $contractMatches['results'] ?? $contractMatches;
            if (empty($contractList) === false) {
                $synchronizationContract = $contractList[0];
            } else {
                $synchronizationContract = null;
            }
        }

        $stsFilters       = [
            'register'   => 'openconnector',
            'schema'     => 'synchronization',
            'sourceType' => 'register/schema',
            'sourceId'   => "{$object->getRegister()}/{$object->getSchema()}",
        ];
        $syncMatches      = $this->orObjectService->findAll(config: ['filters' => $stsFilters]);
        $synchronizations = $syncMatches['results'] ?? $syncMatches;
        if (count($synchronizations) === 0) {
            return [];
        }

        $synchronization = $synchronizations[0];

        if ($synchronizationContract === null) {
            $synchronizationContract = $this->orObjectService->saveObject(
                object: [
                    'synchronizationId' => $synchronization->getUuid(),
                    'originId'          => $objectId,
                ],
                register: 'openconnector',
                schema: 'synchronization_contract'
            );
        }

        $serializedObject = $object->jsonSerialize();
        $flowToken        = new FlowToken();

        // SynchronizeContract no longer accepts a $log arg — it buffers the
        // contract-log payload (#1007). When called outside a synchronize()
        // pass we flush the buffer to a synchronizationLogId set from the
        // passed-in $log (if any), then clear the buffer.
        $existingBuffer            = $this->pendingContractLogs;
        $this->pendingContractLogs = [];

        $result = $this->synchronizeContract(
            synchronizationContract: $synchronizationContract,
            synchronization: $synchronization,
            flowToken: $flowToken,
            object: $serializedObject,
            isTest: $test,
            force: $force
        );

        // Flush any contract-log payloads accumulated during this call.
        $syncLogUuid = $log?->getUuid();
        foreach ($this->pendingContractLogs as $contractLogPayload) {
            if ($syncLogUuid !== null) {
                $contractLogPayload['synchronizationLogId'] = $syncLogUuid;
            }

            try {
                $this->orObjectService->saveObject(
                    object: $contractLogPayload,
                    register: 'openconnector',
                    schema: 'synchronization_contract_log'
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'synchronizeToTarget: failed to persist contract log: '.$e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        // Restore any outer-pass buffer so a nested call doesn't drop it.
        $this->pendingContractLogs = $existingBuffer;

        if (isset($result['contract']) === true) {
            $synchronizationContract = $this->orObjectService->saveObject(
                object: $result['contract'],
                register: 'openconnector',
                schema: 'synchronization_contract',
                uuid: $synchronizationContract->getUuid()
            );
        }

        return [$synchronizationContract];

    }//end synchronizeToTarget()

    /**
     * Saves object to OpenRegister.
     *
     * @param ObjectEntity $rule The rule entity describing the save_object configuration.
     * @param array        $data The data array containing the input parameters.
     *
     * @return array The serialized saved object payload.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    private function processSaveObjectRule(ObjectEntity $rule, array $data): array
    {
        $configuration = $rule->getObject()['configuration'] ?? [];
        $register      = $configuration['save_object']['register'];
        $schema        = $configuration['save_object']['schema'];
        $mapping       = $configuration['save_object']['mapping'] ?? null;
        $patch         = $configuration['save_object']['patch'] ?? false;
        $id            = null;

        if (empty($mapping) === false) {
            if (isset($data['_objectBeforeMapping']['id']) === true) {
                $id = $data['_objectBeforeMapping']['id'];
                unset($data['_objectBeforeMapping']);
            }

            $mapping = $this->mappingService->getMapping($mapping);
            $data    = $this->processMapping(mapping: $mapping, data: $data);
        }

        if ($patch === true || $patch === 'true') {
            $patchObject = $this->orObjectService->find(id: $id, register: $register, schema: $schema);
            if ($patchObject !== null) {
                $data = array_merge($patchObject->getObject(), $data);
            }
        }

        $object = $this->orObjectService->saveObject(
            register: $register,
            schema: $schema,
            object: $data
        )->jsonSerialize();

        return $object;

    }//end processSaveObjectRule()

    /**
     * Extends input for performing business logic.
     *
     * @param array $config The rule configuration which parameters could be extended.
     * @param array $data   The data array containing the input parameters.
     *
     * @return array The data array with the extended parameters merged in.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    private function processExtendInputRule(array $config, array $data): array
    {
        $parameters         = new Dot($data);
        $extendedParameters = new Dot();

        // If the extends are given as a comma separated string, separate them into an array.
        if (is_string($config['extend_input']['properties']) === true) {
            $config['extend_input']['properties'] = explode(
                separator: ',',
                string: $config['extend_input']['properties']
            );
        }

        if (isset($data['id']) === true || isset($data['@self']['id']) === true || ($data['uuid'] ?? null) === true) {
            $id = ($data['@self']['id'] ?? $data['id'] ?? $data['uuid']);
        }

        // If we can fetch the object to extend again, use OpenRegister to fetch the extended object.
        $fetchObject = ($config['extend_input']['fetchObject'] ?? null);
        if (isset($id) === true && isset($config['extend_input']['fetchObject']) === true
            && ($fetchObject === true || $fetchObject === 'true')
        ) {
            $object = $this->objectService->getOpenRegisters()->find(
                id: $id,
                extend: $config['extend_input']['properties']
            );
            return $object->jsonSerialize();
        }

        foreach ($config['extend_input']['properties'] as $property) {
            $value = $parameters->get($property);

            if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
                $exploded = explode(separator: '/', string: $value);
                $value    = end($exploded);
            }

            try {
                $object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find(identifier: $value);
            } catch (DoesNotExistException $exception) {
                continue;
            }

            $extendedParameters->add($property, $object->jsonSerialize());
        }

        return array_merge($data, $extendedParameters->all());

    }//end processExtendInputRule()

    /**
     * Processes rules for an endpoint request.
     *
     * @param ObjectEntity|null $synchronization The endpoint being processed.
     * @param array             $data            Current request data.
     * @param string            $timing          When to apply the rule (before/after).
     * @param string|null       $objectId        Optional object id under rule context.
     * @param string|null       $registerId      Optional register id under rule context.
     * @param string|null       $schemaId        Optional schema id under rule context.
     * @param FlowToken|null    $flowToken       Optional flow token to thread through the call chain.
     *
     * @return array|JSONResponse Returns modified data or error response if rule fails.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws GuzzleException             When a remote HTTP call fails.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws Exception                   For unsupported rule types.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    private function processRules(
        ?ObjectEntity $synchronization,
        array $data,
        string $timing,
        ?string $objectId=null,
        ?string $registerId=null,
        ?string $schemaId=null,
        ?FlowToken $flowToken=null
    ): array|JSONResponse {
        if ($synchronization === null) {
            return $data;
        }

        $syncData = $synchronization->getObject();
        $rules    = $syncData['actions'] ?? [];
        if (empty($rules) === true) {
            return $data;
        }

        try {
            // Get all rules at once and sort by order.
            $ruleEntities = array_filter(
                array_map(
                    fn($ruleId) => $this->getRuleById(id: $ruleId),
                    $rules
                )
            );

            // Sort rules by order.
            usort(
                $ruleEntities,
                function ($a, $b) {
                    $aOrder = ($a->getObject()['order'] ?? 0);
                    $bOrder = ($b->getObject()['order'] ?? 0);
                    return ($aOrder - $bOrder);
                }
            );

            $syncName = ($syncData['name'] ?? $synchronization->getUuid());

            // Process each rule in order.
            foreach ($ruleEntities as $rule) {
                $ruleData = $rule->getObject();
                if ($flowToken !== null) {
                    $data['flowToken'] = $flowToken->__serialize();
                }

                // Check rule conditions.
                $ruleName   = ($ruleData['name'] ?? $rule->getUuid());
                $ruleType   = ($ruleData['type'] ?? '');
                $ruleTiming = ($ruleData['timing'] ?? '');
                if ($this->checkRuleConditions(rule: $rule, data: $data) === false || $ruleTiming !== $timing) {
                    $this->logger->info(
                        'Rule condition check failed for synchronization '.$syncName
                        .' and rule '.$ruleName.' of type: '.$ruleType
                    );
                    unset($data['flowToken']);
                    continue;
                }

                unset($data['flowToken']);

                $this->logger->info(
                    'Applying rule for synchronization '.$syncName
                    .' with rule '.$ruleName.' of type '.$ruleType
                );

                // Process rule based on type.
                $result = match ($ruleType) {
                    'error' => $this->processErrorRule(rule: $rule),
                    'mapping' => $this->processMappingRule(rule: $rule, data: $data),
                    'synchronization' => $this->processSyncRule(rule: $rule, data: $data),
                    'save_object' => $this->processSaveObjectRule(rule: $rule, data: $data),
                    'fetch_file' => $this->processFetchFileRule(rule: $rule, data: $data, objectId: $objectId),
                    'write_file' => $this->processWriteFileRule(
                        rule: $rule,
                        data: $data,
                        objectId: $objectId,
                        registerId: $registerId,
                        schemaId: $schemaId
                    ),
                    'extend_input' => $this->processExtendInputRule(
                        config: ($ruleData['config'] ?? []),
                        data: $data
                    ),
                    default => throw new Exception('Unsupported rule type: '.$ruleType),
                };

                // If result is JSONResponse, return error immediately.
                if ($result instanceof JSONResponse) {
                    return $result;
                }

                // Update data with rule result.
                $data = $result;

                $this->logger->info(
                    'Successfully applied rule for synchronization '.$syncName
                    .' with rule '.$ruleName.' of type '.$ruleType
                );
            }//end foreach

            return $data;
        } catch (Exception $e) {
            $this->logger->error(
                'Error processing rules for synchronization '.($syncData['name'] ?? '').' : '.$e->getMessage()
            );
            return new JSONResponse(['error' => 'Rule processing failed: '.$e->getMessage()], 500);
        }//end try

    }//end processRules()

    /**
     * Get a rule by its ID using RuleMapper.
     *
     * @param string $id The unique identifier of the rule.
     *
     * @return ObjectEntity|null The rule object if found, or null if not found.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    private function getRuleById(string $id): ?ObjectEntity
    {
        return $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'rule');

    }//end getRuleById()

    /**
     * Write a file to the filesystem.
     *
     * @param string $fileName The filename.
     * @param string $content  The content of the file.
     * @param string $objectId The id of the object the file belongs to.
     *
     * @return mixed File or false.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws GenericFileException        When file operations fail.
     * @throws LockedException             When the file is locked.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
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
        } catch (NotFoundException | NotPermittedException | NoUserException $e) {
            return false;
        }

        return $file;

    }//end writeFile()

    /**
     * Fetch a file from a source.
     *
     * @param ObjectEntity|null $source     The source to fetch the file from.
     * @param string            $endpoint   The endpoint for the file.
     * @param array             $config     The configuration of the action.
     * @param string            $objectId   The id of the object the file belongs to.
     * @param array|null        $tags       Tags to assign to the file.
     * @param string|null       $filename   Filename to assign to the file.
     * @param string|null       $published  Optional published timestamp.
     * @param int|string|null   $registerId The id of the register the object belongs to.
     *
     * @return string If write is enabled: the url of the file, if write is disabled: the base64 encoded file.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws GenericFileException        When file operations fail.
     * @throws GuzzleException             When a remote HTTP call fails.
     * @throws LoaderError                 When the Twig loader fails.
     * @throws LockedException             When the file is locked.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws SyntaxError                 When a Twig template has a syntax error.
     * @throws \OCP\DB\Exception           When the database layer raises an exception.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function fetchFile(
        ?ObjectEntity $source,
        string $endpoint,
        array $config,
        string $objectId,
        ?array $tags=[],
        ?string &$filename=null,
        ?string $published=null,
        int|string|null $registerId=null
    ): string {
        if ($source !== null) {
            $sourceData = $source->getObject();
        } else {
            $sourceData = [];
        }

        $sourceLocation   = $sourceData['location'] ?? '';
        $originalEndpoint = $endpoint;
        if ($sourceLocation !== '' && str_contains(haystack: $endpoint, needle: $sourceLocation) === true) {
            $endpoint = substr(string: $endpoint, offset: strlen(string: $sourceLocation));
        }

        $sourceConfig = json_encode($config['sourceConfiguration']);
        if (isset($config['originId']) === true) {
            $sourceConfig = str_replace(search: "{{ originId }}", replace: $config['originId'], subject: $sourceConfig);
        }

        $sourceConfig = json_decode($sourceConfig, true);

        if (isset($sourceConfig['body']) === true
            || (isset($config['method']) === true && $config['method'] !== 'GET')
        ) {
            $sourceConfig['body'] = json_encode($sourceConfig['body'] ?? []);
        }

        $config['sourceConfiguration'] = $sourceConfig;

        $result     = $this->callService->call(
            source: $source,
            endpoint: $endpoint,
            method: $config['method'] ?? 'GET',
            config: $config['sourceConfiguration'] ?? []
        );
        $resultData = $result->getObject();
        $response   = $resultData['response'] ?? null;

        $body = $response['body'] ?? '';

        if (($decodedBody = json_decode(json: $body, associative: true)) !== null
            && isset($response['headers']['Content-Disposition']) === false
        ) {
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

        // Check if response is valid.
        if ($response === null) {
            throw new Exception("Failed to fetch file from endpoint: {$originalEndpoint}. No response received.");
        }

        if (isset($config['write']) === true && $config['write'] === false) {
            return base64_encode($body);
        }

        if ($filename === null) {
            // Get a filename from the response. First try the Content-Disposition header.
            $filename = $this->getFilenameFromHeaders(response: $response, result: $result);
        }

        if ($filename === null) {
            throw new Exception("Could not write file from endpoint {$originalEndpoint}: no filename could be determined");
        }

        // Validate objectId format (should be a UUID).
        if (empty($objectId) === true
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $objectId) !== 1
        ) {
            throw new Exception("Invalid object ID format: {$objectId}. Expected a valid UUID.");
        }

        $fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');

        if (isset($content) === false) {
            $content = $body;
        }

        $shouldShare = false;
        if (empty($tags) === false && isset($config['autoShare']) === true) {
            $shouldShare = $config['autoShare'];
        }

        // Determine if file should be published based on the published parameter.
        $shouldPublish = $this->shouldPublishFile(published: $published);

        try {
            $objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
            $objectEntity  = $objectService->find(id: $objectId);
            $file          = $fileService->saveFile(
                objectEntity: $objectEntity,
                fileName: $filename,
                content: $content,
                share: $shouldShare,
                tags: $tags
            );

            // Publish the file if needed.
            if ($shouldPublish === true && $file !== null) {
                try {
                    $fileService->publishFile(object: $objectEntity, file: $filename);
                } catch (Exception $e) {
                    // Log but don't fail the entire operation.
                    $this->logger->error(
                        "Failed to publish file {$filename} for object {$objectId}: ".$e->getMessage()
                    );
                }
            }
        } catch (DoesNotExistException $exception) {
            // If the object cannot be found, continue with register/schema/objectId combination.
            $register = ($config['register'] ?? null);
            $schema   = ($config['schema'] ?? null);
            if (isset($config['autoShare']) === true) {
                $shareFlag = $config['autoShare'];
            } else {
                $shareFlag = false;
            }

            $file = $fileService->addFile(
                objectEntity: $objectId,
                fileName: $filename,
                content: $response['body'],
                share: $shareFlag,
                tags: $tags,
                register: $register,
                schema: $schema,
                registerId: $registerId
            );

            // For the addFile case, we'll need to get the object entity to publish.
            if ($shouldPublish === true && $file !== null) {
                try {
                    $objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
                    $objectEntity  = $objectService->find(id: $objectId);
                    $fileService->publishFile(object: $objectEntity, file: $filename);
                } catch (Exception $e) {
                    // Log but don't fail the entire operation.
                    $this->logger->error(
                        "Failed to publish file {$filename} for object {$objectId}: ".$e->getMessage()
                    );
                }
            }
        } catch (Exception $e) {
            throw new Exception("Failed to save file {$filename} for object {$objectId}: ".$e->getMessage());
        }//end try

        return $originalEndpoint;

    }//end fetchFile()

    /**
     * Determine the file name from a fetched response.
     *
     * @param array        $response The HTTP response array (headers + body).
     * @param ObjectEntity $result   The CallLog entity for the original call.
     *
     * @return string|null Resolved filename, or null when nothing matched.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function getFilenameFromHeaders(array $response, ObjectEntity $result): ?string
    {
        $filename = null;
        // Get a filename from the response. First try the Content-Disposition header.
        if (isset($response['headers']['Content-Disposition']) === true
            && str_contains($response['headers']['Content-Disposition'][0], 'filename') === true
        ) {
            $explodedContentDisposition = explode('=', $response['headers']['Content-Disposition'][0]);

            $filename = trim(string: $explodedContentDisposition[1], characters: '"');
        } else {
            // Otherwise, parse the url and content type header.
            $resultData = $result->getObject();
            $requestUrl = ($resultData['request']['url'] ?? '');
            $parsedUrl  = parse_url($requestUrl);
            $path       = explode(separator:'/', string: ($parsedUrl['path'] ?? ''));
            $filename   = end($path);

            if (count(explode(separator: '.', string: $filename)) === 1
                && (isset($response['headers']['Content-Type']) === true
                || isset($response['headers']['content-type']) === true)
            ) {
                if (isset($response['headers']['Content-Type']) === true) {
                    $contentTypeHeader = $response['headers']['Content-Type'][0];
                } else {
                    $contentTypeHeader = $response['headers']['content-type'][0];
                }

                $baseType         = explode(separator: ';', string: $contentTypeHeader)[0];
                $explodedMimeType = explode(separator: '/', string: $baseType);

                $filename = $filename.'.'.end($explodedMimeType);
            }
        }//end if

        return $filename;

    }//end getFilenameFromHeaders()

    /**
     * Extracts an endpoint from the given data and optionally retrieves a filename and tags.
     *
     * @param array           $config     The configuration array.
     * @param mixed           $endpoint   The data containing the endpoint, string or array.
     * @param string|null     $filename   Reference to the filename to be updated.
     * @param array|null      $tags       Reference to the tag list to be updated.
     * @param string|null     $objectId   Reference to the object id to attach files to.
     * @param string|null     $published  Reference to the published status to be updated.
     * @param int|string|null $registerId Reference to the registerId to be updated.
     *
     * @return string|null The extracted endpoint or null when nothing matched.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function getFileContext(
        array $config,
        mixed $endpoint,
        ?string &$filename=null,
        ?array &$tags=[],
        ?string &$objectId=null,
        ?string &$published=null,
        int|string|null &$registerId=null
    ) {
        $dataDot = new Dot($endpoint);
        if (isset($config['objectIdPath']) === true && empty($config['objectIdPath']) === false) {
            $objectId = $dataDot->get($config['objectIdPath']);
        }

        if (isset($config['subObjectFilepath']) === true && empty($config['subObjectFilepath']) === false) {
            $endpoint = $dataDot->get($config['subObjectFilepath']);
        }

        if (is_array($endpoint) === true) {
            // Handle labels/tags with support for multiple property names.
            $extractedTags = [];

            // Check for various tag/label property names and extract values.
            $tagProperties = ['label', 'labels', 'tag', 'tags'];
            foreach ($tagProperties as $property) {
                if (isset($endpoint[$property]) === true && empty($endpoint[$property]) === false) {
                    $value = $endpoint[$property];

                    // Handle both single values and arrays.
                    if (is_array($value) === true) {
                        $filteredValues = array_filter(
                            $value,
                            function ($item) {
                                return (empty($item) === false && is_string($item) === true);
                            }
                        );
                        $extractedTags  = array_merge($extractedTags, $filteredValues);
                    } else if (is_string($value) === true && empty($value) === false) {
                        $extractedTags[] = $value;
                    }
                }
            }

            // Remove duplicates and apply tag filtering logic.
            $extractedTags = array_unique($extractedTags);

            // Check if we have meaningful tag configuration.
            $hasUseLabelsAsTags     = (isset($config['useLabelsAsTags']) === true && $config['useLabelsAsTags'] === true);
            $hasAllowedLabels       = (isset($config['allowedLabels']) === true
                && is_array($config['allowedLabels']) === true
                && empty($config['allowedLabels']) === false);
            $hasLegacyTags          = (isset($config['tags']) === true
                && is_array($config['tags']) === true
                && empty($config['tags']) === false);
            $hasMeaningfulTagConfig = ($hasUseLabelsAsTags === true
                || $hasAllowedLabels === true
                || $hasLegacyTags === true);

            foreach ($extractedTags as $tagValue) {
                if ($hasUseLabelsAsTags === true) {
                    // If useLabelsAsTags is explicitly enabled, always use the tag.
                    $tags[] = $tagValue;
                } else if ($hasAllowedLabels === true && in_array($tagValue, $config['allowedLabels'], true) === true) {
                    // If config has specific allowed labels, check if this tag is allowed.
                    $tags[] = $tagValue;
                } else if ($hasLegacyTags === true && in_array($tagValue, $config['tags'], true) === true) {
                    // Legacy behavior - if config has non-empty tags array and tag is in it.
                    $tags[] = $tagValue;
                } else if ($hasMeaningfulTagConfig === false) {
                    // If no meaningful tag configuration is provided, use all tags (default behavior).
                    $tags[] = $tagValue;
                }
            }

            // Extract filename if available.
            if (isset($endpoint['filename']) === true && empty($endpoint['filename']) === false) {
                $filename = $endpoint['filename'];
            }

            // Extract published status if available.
            if (isset($endpoint['published']) === true) {
                $published = $endpoint['published'];
            }

            // Extract registerId if available.
            if (isset($endpoint['registerId']) === true) {
                $registerId = $endpoint['registerId'];
            }

            // Check if endpoint exists before returning it.
            if (isset($endpoint['endpoint']) === true) {
                return $endpoint['endpoint'];
            }

            // If no endpoint is found, return null.
            return null;
        }//end if

        return $endpoint;

    }//end getFileContext()

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
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function getArrayType(mixed $array): string
    {
        // Check if not array.
        if (is_array($array) === false) {
            return "Not array";
        }

        if ($array === []) {
            return 'Empty array';
        }

        // Check for an associative array.
        if (array_keys($array) !== range(0, count($array) - 1)) {
            return "Associative array";
        }

        // Check for a multidimensional array.
        if (count($array) !== count($array, COUNT_RECURSIVE)) {
            return "Multidimensional array";
        }

        // Otherwise, it's an indexed array.
        return "Indexed array";

    }//end getArrayType()

    /**
     * Process a rule to fetch a file from an external source using fire-and-forget execution.
     *
     * @param ObjectEntity $rule     The rule to process containing fetch_file configuration.
     * @param array        $data     The data written to the object.
     * @param string|null  $objectId The UUID of the object to attach files to.
     *
     * @return array The resulting object data with placeholder values for file paths.
     *
     * @throws Exception When OpenRegister app is not available or configuration is missing.
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function processFetchFileRule(ObjectEntity $rule, array $data, ?string $objectId=null): array
    {
        // Check if OpenRegister app is available.
        $appManager = \OC::$server->get(\OCP\App\IAppManager::class);
        if ($appManager->isEnabledForUser('openregister') === false) {
            throw new Exception('OpenRegister app is required for the fetch file rule and not installed');
        }

        $ruleData = $rule->getObject();

        // Validate rule configuration.
        if (isset($ruleData['configuration']['fetch_file']) === false) {
            throw new Exception('No configuration found for fetch_file');
        }

        $config = $ruleData['configuration']['fetch_file'];

        $dataDot = new Dot($data);
        if (isset($config['filePath']) === true && $config['filePath'] !== '') {
            $endpoint = $dataDot->get($config['filePath']);
        } else {
            $endpoint = $config['endpoint'];
        }

        if ($objectId === null && isset($config['objectIdPath']) === true) {
            $objectId = $dataDot->get($config['objectIdPath']);
        }

        if (isset($config['originIdPath']) === true) {
            $config['originId'] = $dataDot->get($config['originIdPath']);
        }

        // If no endpoint is found, return data unchanged.
        if ($endpoint === null) {
            return $dataDot->jsonSerialize();
        }

        // Get source for file fetching.
        $source = $this->orObjectService->find(
            id: ($config['source'] ?? null),
            register: 'openconnector',
            schema: 'source'
        );
        if ($source === null) {
            $this->logger->error("Failed to find source for fetch file rule: source not found");
            return $dataDot->jsonSerialize();
        }

        // Start fire-and-forget file fetching based on endpoint type.
        $this->startAsyncFileFetching(
            source: $source,
            config: $config,
            endpoint: $endpoint,
            ruleId: $rule->getUuid(),
            objectId: $objectId
        );

        // Return data immediately with placeholder values.
        if (isset($config['setPlaceholder']) === false
            || (isset($config['setPlaceholder']) === true && $config['setPlaceholder'] !== false)
        ) {
            $dataDot[$config['filePath']] = $this->generatePlaceholderValues(endpoint: $endpoint);
        }

        return $dataDot->jsonSerialize();

    }//end processFetchFileRule()

    /**
     * Starts asynchronous file fetching operations using ReactPHP promises.
     *
     * @param ObjectEntity|null $source   The source to fetch files from.
     * @param array             $config   The fetch_file rule configuration.
     * @param mixed             $endpoint The endpoint(s) to fetch files from.
     * @param string            $ruleId   The ID of the rule for error logging.
     * @param string|null       $objectId The UUID of the object to attach files to.
     *
     * @return void
     *
     * @psalm-param array<string, mixed> $config
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function startAsyncFileFetching(?ObjectEntity $source, array $config, mixed $endpoint, string $ruleId, ?string $objectId=null): void
    {
        // Execute file fetching immediately but with error isolation.
        // This provides "fire-and-forget" behavior without complex ReactPHP setup.
        $this->executeAsyncFileFetching(
            source: $source,
            config: $config,
            endpoint: $endpoint,
            ruleId: $ruleId,
            objectId: $objectId
        );

    }//end startAsyncFileFetching()

    /**
     * Executes the actual file fetching operations asynchronously.
     *
     * @param ObjectEntity|null $source   The source to fetch files from.
     * @param array             $config   The fetch_file rule configuration.
     * @param mixed             $endpoint The endpoint(s) to fetch files from.
     * @param string            $ruleId   The ID of the rule for error logging.
     * @param string|null       $objectId The UUID of the object to attach files to.
     *
     * @return void
     *
     * @psalm-param array<string, mixed> $config
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function executeAsyncFileFetching(?ObjectEntity $source, array $config, mixed $endpoint, string $ruleId, ?string $objectId=null): void
    {
        try {
            $filename   = null;
            $tags       = [];
            $published  = null;
            $registerId = null;

            switch ($this->getArrayType(array: $endpoint)) {
                // Single file endpoint.
                case 'Not array':
                    $this->fetchFileSafely(
                        source: $source,
                        endpoint: $endpoint,
                        config: $config,
                        objectId: $objectId
                    );
                    break;

                // Array of object that has file(s).
                case 'Associative array':
                    $contextObjectId = null;
                    // Separate variable to avoid overwriting the original.
                    $actualEndpoint = $this->getFileContext(
                        config: $config,
                        endpoint: $endpoint,
                        filename: $filename,
                        tags: $tags,
                        objectId: $contextObjectId,
                        published: $published,
                        registerId: $registerId
                    );
                    // Use context object ID if specified, otherwise fall back to the original object ID.
                    $targetObjectId = ($contextObjectId ?? $objectId);
                    if ($actualEndpoint !== null) {
                        $this->fetchFileSafely(
                            source: $source,
                            endpoint: $actualEndpoint,
                            config: $config,
                            objectId: $targetObjectId,
                            filename: $filename,
                            tags: $tags,
                            published: $published,
                            registerId: $registerId
                        );
                    }
                    break;

                // Array is empty.
                case 'Empty array':
                    // Array of object(s) that has file(s) - use cleanup logic.
                case "Multidimensional array":
                    // Array of just endpoints - use cleanup logic.
                case "Indexed array":
                    $this->processMultipleFilesWithCleanup(
                        source: $source,
                        config: $config,
                        endpoints: $endpoint,
                        objectId: $objectId
                    );
                    break;
            }//end switch
        } catch (Exception $e) {
            // Log error but don't throw - this is fire-and-forget.
            $this->logger->error("Async file fetching failed for rule {$ruleId}: ".$e->getMessage());
        }//end try

    }//end executeAsyncFileFetching()

    /**
     * Fetches a single file with comprehensive error handling.
     *
     * This method wraps the existing fetchFile method with error isolation to enable
     * fire-and-forget execution. Errors are caught and logged without affecting the main process.
     *
     * @param ObjectEntity|null $source     The source to fetch the file from.
     * @param string            $endpoint   The endpoint for the file.
     * @param array             $config     The configuration of the action.
     * @param string            $objectId   The UUID of the object the file belongs to.
     * @param string|null       $filename   Optional filename to assign to the file.
     * @param array             $tags       Optional tags to assign to the file.
     * @param int|string|null   $published  Optional published status.
     * @param int|string|null   $registerId Optional register identifier.
     *
     * @return void
     *
     * @psalm-param array<string, mixed> $config
     * @psalm-param array<string> $tags
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function fetchFileSafely(
        ?ObjectEntity $source,
        string $endpoint,
        array $config,
        string $objectId,
        ?string $filename=null,
        array $tags=[],
        int|string|null $published=null,
        int|string|null $registerId=null
    ): void {
        try {
            // Execute the file fetching operation.
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
            // Log error with detailed information but don't throw.
            $this->logger->error("File fetch failed for endpoint {$endpoint}, objectId {$objectId}: ".$e->getMessage());
        }

    }//end fetchFileSafely()

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
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function generatePlaceholderValues(mixed $endpoint): mixed
    {
        switch ($this->getArrayType(array: $endpoint)) {
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
    }//end generatePlaceholderValues()

    /**
     * Process a rule to write files.
     *
     * @param ObjectEntity $rule       The rule to process.
     * @param array        $data       The data to write.
     * @param string       $objectId   The object to write the data to.
     * @param string       $registerId The register the object is in.
     * @param string       $schemaId   The schema the object is in.
     *
     * @return array The updated data after processing.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws Exception                   When the rule configuration is missing.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function processWriteFileRule(
        ObjectEntity $rule,
        array $data,
        string $objectId,
        string $registerId,
        string $schemaId
    ): array {
        $ruleData = $rule->getObject();
        if (isset($ruleData['configuration']['write_file']) === false) {
            throw new Exception('No configuration found for write_file');
        }

        $config  = $ruleData['configuration']['write_file'];
        $dataDot = new Dot($data);
        $files   = $dataDot[$config['filePath']];
        if (isset($files) === false || empty($files) === true) {
            return $dataDot->jsonSerialize();
        }

        // Get the object entity and file service.
        $objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
        $objectEntity  = $objectService->find(id: $objectId);
        $fileService   = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');

        // Check if associative array (multiple files with metadata).
        if (is_array($files) === true && isset($files[0]) === true && array_keys($files[0]) !== range(0, count($files[0]) - 1)) {
            $result = [];
            foreach ($files as $key => $value) {
                $content  = '';
                $fileName = '';
                $tags     = [];

                // Extract file data.
                if (is_array($value) === true) {
                    $content  = $value['content'];
                    $fileName = ($value['filename'] ?? "file_$key");

                    // Handle tags from config and value labels.
                    if (isset($value['label']) === true && isset($config['tags']) === true
                        && in_array(needle: $value['label'], haystack: $config['tags']) === true
                    ) {
                        $tags = [$value['label']];
                    }
                } else {
                    $content  = $value;
                    $fileName = "file_$key";
                }

                // Merge with configured tags.
                $allTags = array_unique(array_merge(($config['tags'] ?? []), $tags));

                // Determine if we should share the file - only if there are user-defined tags.
                $shouldShare = (empty($allTags) === false);

                try {
                    // Use the new saveFile method.
                    $file = $fileService->saveFile(
                        objectEntity: $objectEntity,
                        fileName: $fileName,
                        content: $content,
                        share: $shouldShare,
                        tags: $allTags
                    );

                    $result[$key] = $file->getPath();
                } catch (Exception $exception) {
                    $this->logger->error("Failed to save file $fileName: ".$exception->getMessage());
                    $result[$key] = null;
                }
            }//end foreach

            $dataDot[$config['filePath']] = $result;
        } else {
            // Single file case.
            $content  = $files;
            $fileName = ($dataDot[$config['fileNamePath']] ?? 'default_file');

            // Get configured tags.
            $tags = ($config['tags'] ?? []);

            // Determine if we should share the file - only if there are user-defined tags.
            $shouldShare = (empty($tags) === false);

            try {
                // Use the new saveFile method.
                $file = $fileService->saveFile(
                    objectEntity: $objectEntity,
                    fileName: $fileName,
                    content: $content,
                    share: $shouldShare,
                    tags: $tags
                );

                $dataDot[$config['filePath']] = $file->getPath();
            } catch (Exception $exception) {
                $this->logger->error("Failed to save file $fileName: ".$exception->getMessage());
                $dataDot[$config['filePath']] = null;
            }
        }//end if

        return $dataDot->jsonSerialize();

    }//end processWriteFileRule()

    /**
     * Processes an error rule.
     *
     * @param ObjectEntity $rule The rule object containing error details.
     *
     * @return JSONResponse Response containing error details and HTTP status code.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    private function processErrorRule(ObjectEntity $rule): JSONResponse
    {
        $config = $rule->getObject()['configuration'] ?? [];
        return new JSONResponse(
            [
                'error'   => $config['error']['name'],
                'message' => $config['error']['message'],
            ],
            $config['error']['code']
        );

    }//end processErrorRule()

    /**
     * Processes a mapping rule.
     *
     * @param ObjectEntity $rule The rule object containing mapping details.
     * @param array        $data The data to be processed through the mapping rule.
     *
     * @return array The processed data after applying the mapping rule.
     *
     * @throws DoesNotExistException            When the mapping configuration does not exist.
     * @throws MultipleObjectsReturnedException When multiple mapping objects are returned unexpectedly.
     * @throws LoaderError                      When there is an error loading the mapping.
     * @throws SyntaxError                      When there is a syntax error in the mapping configuration.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function processMappingRule(ObjectEntity $rule, array $data): array
    {
        $config  = $rule->getObject()['configuration'] ?? [];
        $mapping = $this->mappingService->getMapping($config['mapping']);

        return $this->processMapping(mapping: $mapping, data: $data);

    }//end processMappingRule()

    /**
     * Executes mapping on data from endpoint flow.
     *
     * @param ObjectEntity $mapping The mapping entity.
     * @param array        $data    The data to be mapped.
     *
     * @return array The mapped data.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function processMapping(ObjectEntity $mapping, array $data): array
    {
        return $this->mappingService->executeMapping($mapping, $data);

    }//end processMapping()

    /**
     * Processes a synchronization rule.
     *
     * @param ObjectEntity $rule The rule object containing synchronization details.
     * @param array        $data The data to be synchronized.
     *
     * @return array The data after synchronization processing.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    private function processSyncRule(ObjectEntity $rule, array $data): array
    {
        $config = $rule->getObject()['configuration'] ?? [];
        // Here you would implement the synchronization logic.
        // For now, just return the data unchanged.
        return $data;

    }//end processSyncRule()

    /**
     * Checks if rule conditions are met.
     *
     * @param ObjectEntity $rule The rule object containing conditions to be checked.
     * @param array        $data The input data against which the conditions are evaluated.
     *
     * @return bool True if conditions are met, false otherwise.
     *
     * @throws Exception When the JsonLogic evaluator throws.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    private function checkRuleConditions(ObjectEntity $rule, array $data): bool
    {
        $conditions = $rule->getObject()['conditions'] ?? [];
        if (empty($conditions) === true) {
            return true;
        }

        return JsonLogic::apply($conditions, $data) === true;

    }//end checkRuleConditions()

    /**
     * Replaces strings in array keys, helpful for characters like . in array keys.
     *
     * @param array  $array       The array to encode the array keys for.
     * @param string $toReplace   The character to encode.
     * @param string $replacement The encoded character.
     *
     * @return array The array with encoded array keys
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    public function encodeArrayKeys(array $array, string $toReplace, string $replacement): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = str_replace($toReplace, $replacement, $key);

            if (is_array($value) === true && $value !== []) {
                $result[$newKey] = $this->encodeArrayKeys(
                    array: $value,
                    toReplace: $toReplace,
                    replacement: $replacement
                );
                continue;
            }

            $result[$newKey] = $value;
        }

        return $result;

    }//end encodeArrayKeys()

    /**
     * Convert SimpleXMLElement to array while preserving namespaced attributes.
     *
     * @param \SimpleXMLElement $xml The XML element to convert.
     *
     * @return array The array representation with preserved namespaced attributes.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-3
     */
    private function xmlToArray(\SimpleXMLElement $xml): array
    {
        $result = [];

        // Handle attributes - this preserves namespaced attributes with colons.
        $attributes = $xml->attributes();
        if (count($attributes) > 0) {
            $result['@attributes'] = [];
            foreach ($attributes as $attrName => $attrValue) {
                $result['@attributes'][(string) $attrName] = (string) $attrValue;
            }
        }

        // Handle namespaced attributes.
        $namespaces = $xml->getNamespaces(true);
        foreach ($namespaces as $prefix => $namespace) {
            $nsAttributes = $xml->attributes($namespace);
            if (count($nsAttributes) > 0) {
                if (isset($result['@attributes']) === false) {
                    $result['@attributes'] = [];
                }

                foreach ($nsAttributes as $attrName => $attrValue) {
                    // Preserve the namespace prefix in the attribute name (with colon).
                    if (empty($prefix) === false) {
                        $nsAttrName = "$prefix:$attrName";
                    } else {
                        $nsAttrName = $attrName;
                    }

                    $result['@attributes'][$nsAttrName] = (string) $attrValue;
                }
            }
        }

        // Handle child elements.
        foreach ($xml->children() as $childName => $child) {
            $childArray = $this->xmlToArray(xml: $child);

            if (isset($result[$childName]) === true) {
                // If this child name already exists, convert to or add to array.
                if (isset($result[$childName][0]) === false) {
                    $result[$childName] = [$result[$childName]];
                }

                $result[$childName][] = $childArray;
            } else {
                $result[$childName] = $childArray;
            }
        }

        // Handle text content.
        $text = trim((string) $xml);
        if (count($result) === 0 && $text !== '') {
            return ['#text' => $text];
        } else if ($text !== '') {
            $result['#text'] = $text;
        }

        return $result;

    }//end xmlToArray()

    /**
     * Process a single object during synchronization.
     *
     * @param ObjectEntity $synchronization The synchronization being processed.
     * @param array        $object          The object to synchronize.
     * @param array        $result          The current result tracking data.
     * @param bool         $isTest          Whether this is a test run.
     * @param bool         $force           Whether to force synchronization regardless of changes.
     * @param FlowToken    $flowToken       The flow token tracking the operation.
     * @param string|null  $mutationType    The type of object mutation.
     *
     * @return array Contains updated result data and the targetId: ['result' => array, 'targetId' => string|null].
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function processSynchronizationObject(
        ObjectEntity $synchronization,
        array $object,
        array $result,
        bool $isTest,
        bool $force,
        FlowToken &$flowToken,
        ?string $mutationType=null
    ): array {
        // We can only deal with arrays (based on the source empty values or string might be returned).
        if (is_array($object) === false) {
            $result['objects']['invalid']++;
            return [
                'result'   => $result,
                'targetId' => null,
            ];
        }

        $syncData     = $synchronization->getObject();
        $sourceConfig = $this->callService->applyConfigDot($syncData['sourceConfig'] ?? []);
        // Optional to fetch extra data now instead of later in ->synchronizeContract.
        $extraBefore = ($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION] ?? null);
        if (isset($sourceConfig[$this::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION]) === true
            && ($extraBefore === true || $extraBefore === 'true')
        ) {
            $object = $this->fetchMultipleExtraData(
                synchronization: $synchronization,
                sourceConfig: $sourceConfig,
                object: $object
            );
        }

        $conditionsObject = $this->encodeArrayKeys(array: $object, toReplace: '.', replacement: '&#46;');

        // Add flow token to conditions object if it exists.
        if ($flowToken !== null) {
            $conditionsObject['flowToken'] = $flowToken->__serialize();
        }

        // Check if object adheres to conditions.
        // JsonLogic::apply() returns a mixed type, so we explicitly compare against true.
        $syncConditions = $syncData['conditions'] ?? [];
        if ($syncConditions !== [] && JsonLogic::apply($syncConditions, $conditionsObject) !== true) {
            // Increment skipped count in log since object doesn't meet conditions.
            $result['objects']['skipped']++;
            return [
                'result'   => $result,
                'targetId' => null,
            ];
        }

        // If the source configuration contains a dot notation for the id position, extract the id.
        $originId = $this->getOriginId(synchronization: $synchronization, object: $object);

        // Get the synchronization contract for this object.
        $findContractByOriginId = false;
        if (isset($sourceConfig['findContractByOriginIdOnly']) === true
            && filter_var($sourceConfig['findContractByOriginIdOnly'], FILTER_VALIDATE_BOOLEAN) === true
        ) {
            $findContractByOriginId = true;
        }

        // Find sync contract by originId. Re-syncs MUST route the write through
        // the existing target object (UPDATE) instead of creating a fresh
        // ObjectEntity — otherwise mirrored registers fill with N copies after
        // N runs (#1016). The OR `findAll` body-field filter is not guaranteed
        // to strictly match `originId` (it depends on schema indexing); we
        // therefore re-verify the match in PHP before adopting the contract.
        $contractFilters = [
            'register' => 'openconnector',
            'schema'   => 'synchronization_contract',
            'originId' => $originId,
        ];
        if ($findContractByOriginId === false) {
            $contractFilters['synchronizationId'] = $synchronization->getUuid();
        }

        $contractMatches = $this->orObjectService->findAll(config: ['filters' => $contractFilters]);
        $contractList    = $contractMatches['results'] ?? $contractMatches;

        // Defensive re-filter: ensure originId (and synchronizationId when not
        // findContractByOriginIdOnly) match exactly. Casting to string covers
        // numeric origin ids retrieved as int from OR.
        $originIdString = (string) $originId;
        $contractList   = array_values(
            array_filter(
                $contractList,
                function ($candidate) use ($originIdString, $findContractByOriginId, $synchronization) {
                    if ($candidate instanceof ObjectEntity === false) {
                        return false;
                    }

                    $candidateData = $candidate->getObject();
                    if ((string) ($candidateData['originId'] ?? '') !== $originIdString) {
                        return false;
                    }

                    if ($findContractByOriginId === false
                        && (string) ($candidateData['synchronizationId'] ?? '') !== $synchronization->getUuid()
                    ) {
                        return false;
                    }

                    return true;
                }
            )
        );

        if (empty($contractList) === false) {
            $synchronizationContract = $contractList[0];
        } else {
            $synchronizationContract = null;
        }

        if ($synchronizationContract === null) {
            // The controller docblock for sync-test guarantees zero persistent
            // writes (#1008). Under $isTest=true we build an in-memory
            // ObjectEntity instead of issuing a saveObject so neither a
            // synchronization_contract nor (downstream) a synchronization_contract_log
            // row is created.
            // Cast originId to string — the synchronization_contract schema
            // declares originId as string|null but getOriginId returns
            // int|string (numeric source ids like jsonplaceholder posts ids
            // are common).
            $contractPayload = [
                'synchronizationId' => $synchronization->getUuid(),
                'originId'          => (string) $originId,
            ];
            if ($isTest === true) {
                $synchronizationContract = new ObjectEntity();
                // Positional arg only — Entity::__call's setter() uses $args[0].
                // Named args on Entity magic setters silently miscompose (memory rule).
                $synchronizationContract->setObject($contractPayload);
            } else {
                $synchronizationContract = $this->orObjectService->saveObject(
                    object: $contractPayload,
                    register: 'openconnector',
                    schema: 'synchronization_contract'
                );
            }

            $synchronizationContractResult = $this->synchronizeContract(
                synchronizationContract: $synchronizationContract,
                synchronization: $synchronization,
                flowToken: $flowToken,
                object: $object,
                isTest: $isTest,
                force: $force,
                mutationType: $mutationType
            );

            if ($synchronizationContractResult instanceof \Exception) {
                $this->logger->error(
                    'synchronizeContract failed (new contract): '.$synchronizationContractResult->getMessage(),
                    ['exception' => $synchronizationContractResult]
                );
                $result['objects']['invalid']++;
                return ['result' => $result, 'targetId' => null];
            }

            $synchronizationContract = ($synchronizationContractResult['contract'] ?? []);
            $result['contracts'][]   = ($synchronizationContract['uuid'] ?? null);
            if (isset($synchronizationContractResult['log']) === true) {
                $result['logs'][] = ($synchronizationContractResult['log']['uuid'] ?? null);
            } else {
                $result['logs'][] = null;
            }

            $resultAction = ($synchronizationContractResult['resultAction'] ?? null);
            if ($resultAction === 'update') {
                $resultAction = 'create';
            }
        } else {
            // @todo this is weird.
            $synchronizationContractResult = $this->synchronizeContract(
                synchronizationContract: $synchronizationContract,
                synchronization: $synchronization,
                flowToken: $flowToken,
                object: $object,
                isTest: $isTest,
                force: $force,
                mutationType: $mutationType
            );

            if ($synchronizationContractResult instanceof \Exception) {
                $this->logger->error(
                    'synchronizeContract failed (existing contract): '.$synchronizationContractResult->getMessage(),
                    ['exception' => $synchronizationContractResult]
                );
                $result['objects']['invalid']++;
                return ['result' => $result, 'targetId' => null];
            }

            $synchronizationContract = $synchronizationContractResult['contract'];
            if (isset($synchronizationContractResult['contract']['uuid']) === true) {
                $result['contracts'][] = $synchronizationContractResult['contract']['uuid'];
            } else {
                $result['contracts'][] = null;
            }

            if (isset($synchronizationContractResult['log']['uuid']) === true) {
                $result['logs'][] = $synchronizationContractResult['log']['uuid'];
            } else {
                $result['logs'][] = null;
            }

            $resultAction = $synchronizationContractResult['resultAction'] ?? null;
        }//end if

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

        $targetId = ($synchronizationContract['targetId'] ?? null);

        return [
            'result'   => $result,
            'targetId' => $targetId,
        ];

    }//end processSynchronizationObject()

    /**
     * Fetch an synchronization by id or other characteristics.
     *
     * @param string|int|null $id      The id of the synchronization.
     * @param array           $filters Other filters to find the synchronization by.
     *
     * @return ObjectEntity The resulting synchronization.
     *
     * @throws DoesNotExistException When the synchronization does not exist.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-1
     */
    public function getSynchronization(null|string|int $id=null, array $filters=[]) :ObjectEntity
    {
        if ($id !== null) {
            $entity = $this->orObjectService->find(
                id: (string) $id,
                register: 'openconnector',
                schema: 'synchronization'
            );
            if ($entity === null) {
                throw new DoesNotExistException('The synchronization you are looking for does not exist');
            }

            return $entity;
        }

        $orFilters        = array_merge(
            [
                'register' => 'openconnector',
                'schema'   => 'synchronization',
            ],
            $filters
        );
        $matches          = $this->orObjectService->findAll(config: ['filters' => $orFilters]);
        $synchronizations = $matches['results'] ?? $matches;

        if (count($synchronizations) === 0) {
            throw new DoesNotExistException('The synchronization you are looking for does not exist');
        }

        return $synchronizations[0];

    }//end getSynchronization()

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
     * @psalm-param   array<float|int> $numbers
     * @phpstan-param array<float|int> $numbers
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    private function calculateMedian(array $numbers): float
    {
        if (empty($numbers) === true) {
            return 0.0;
        }

        // Sort the array to find the median.
        sort($numbers);
        $count = count($numbers);

        // If odd number of elements, return the middle one.
        if (($count % 2) === 1) {
            return (float) $numbers[intval($count / 2)];
        }

        // If even number of elements, return average of two middle values.
        $middle1 = $numbers[(intval($count / 2) - 1)];
        $middle2 = $numbers[intval($count / 2)];
        return (($middle1 + $middle2) / 2.0);

    }//end calculateMedian()

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
     * @psalm-param    array<string, array{duration_ms: float, description: string}> $stages
     * @phpstan-param  array<string, array{duration_ms: float, description: string}> $stages
     * @psalm-return   array{name: string, duration_ms: float, description: string}
     * @phpstan-return array{name: string, duration_ms: float, description: string}
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    private function getSlowestStage(array $stages): array
    {
        if (empty($stages) === true) {
            return [
                'name'        => 'none',
                'duration_ms' => 0.0,
                'description' => 'No stages recorded',
            ];
        }

        $slowestStage       = '';
        $slowestDuration    = 0.0;
        $slowestDescription = '';

        foreach ($stages as $stageName => $stageData) {
            if ($stageData['duration_ms'] > $slowestDuration) {
                $slowestDuration    = $stageData['duration_ms'];
                $slowestStage       = $stageName;
                $slowestDescription = $stageData['description'];
            }
        }

        return [
            'name'        => $slowestStage,
            'duration_ms' => $slowestDuration,
            'description' => $slowestDescription,
        ];
    }//end getSlowestStage()

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
     * @psalm-param   array<string, array{duration_ms: float}> $stages
     * @phpstan-param array<string, array{duration_ms: float}> $stages
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    private function calculateEfficiencyRatio(array $stages): float
    {
        if (empty($stages) === true) {
            return 0.0;
        }

        $totalDuration      = 0.0;
        $processingDuration = 0.0;

        foreach ($stages as $stageName => $stageData) {
            $totalDuration += $stageData['duration_ms'];

            // Consider 'process_objects' as the core processing stage.
            if ($stageName === 'process_objects') {
                $processingDuration = $stageData['duration_ms'];
            }
        }

        if ($totalDuration === 0.0) {
            return 0.0;
        }

        return round(($processingDuration / $totalDuration), 4);

    }//end calculateEfficiencyRatio()

    /**
     * Cleans up files that are currently attached to an object but not present in the new file set.
     *
     * @param string $objectId     The UUID of the object to clean up files for.
     * @param array  $newFileNames Array of filenames that should remain attached to the object.
     *
     * @return int The number of files that were deleted.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws Exception                   When the cleanup encounters an unexpected error.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function cleanupOrphanedFiles(string $objectId, array $newFileNames): int
    {
        $deletedCount = 0;

        try {
            // Get the object entity.
            $objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
            try {
                $objectEntity = $objectService->find(id: $objectId);
            } catch (DoesNotExistException $e) {
                // It is possible we are trying to delete files for an object id where the object has not been persisted yet.
                return 0;
            }

            // Get the file service.
            $fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');

            // Get all currently attached files for this object.
            $currentFiles = $fileService->getFiles($objectEntity);

            // Check each current file to see if it should be kept.
            foreach ($currentFiles as $file) {
                $fileName = $file->getName();

                // If this file is not in the new set, delete it.
                if (in_array($fileName, $newFileNames, true) === false) {
                    try {
                        // Use FileService's deleteFile method instead of direct deletion.
                        $result = $fileService->deleteFile($file, $objectEntity);

                        if ($result === true) {
                            $deletedCount++;
                        }
                    } catch (Exception $e) {
                        $this->logger->error("FAILED to delete orphaned file {$fileName}: ".$e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            $this->logger->error("FATAL ERROR during file cleanup for object {$objectId}: ".$e->getMessage());
        }//end try

        return $deletedCount;
    }//end cleanupOrphanedFiles()

    /**
     * Processes file fetching for multiple files and handles cleanup of orphaned files.
     *
     * This method fetches multiple files for an object and ensures that any files
     * currently attached to the object but not in the new set are removed.
     *
     * @param ObjectEntity|null $source    The source to fetch files from.
     * @param array             $config    The fetch_file rule configuration.
     * @param array             $endpoints Array of endpoints/file data to process.
     * @param string|null       $objectId  The UUID of the object to attach files to.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function processMultipleFilesWithCleanup(?ObjectEntity $source, array $config, array $endpoints, ?string $objectId=null): void
    {
        $newFileNames = [];

        if ($endpoints === [] && $objectId !== null) {
            $targetObjectId = $objectId;
        } else if ($endpoints === []) {
            return;
        }

        // Process all files first and collect their filenames.
        foreach ($endpoints as $endpoint) {
            $filename        = null;
            $tags            = [];
            $contextObjectId = null;
            $actualEndpoint  = null;
            $registerId      = null;
            $published       = null;

            // Handle different endpoint types.
            if (is_array($endpoint) === true) {
                // This is an object with file metadata (multidimensional array case).
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
                // This is a simple endpoint string (indexed array case).
                $actualEndpoint = $endpoint;
            }

            // Use context object ID if specified, otherwise fall back to the original object ID.
            $targetObjectId = ($contextObjectId ?? $objectId);

            if ($actualEndpoint !== null) {
                // Determine filename for tracking BEFORE attempting fetch.
                try {
                    // Fetch the file.
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
                    $this->logger->error("Failed to fetch file from endpoint {$actualEndpoint}: ".$e->getMessage());
                    // Note: We still keep the filename in tracking array even if fetch fails.
                    // This prevents cleanup from deleting files that should exist.
                }

                $trackingFilename = $filename;

                if ($trackingFilename === null) {
                    // Try to extract filename from endpoint URL.
                    $pathParts        = explode('/', $actualEndpoint);
                    $trackingFilename = end($pathParts);

                    // If still no clear filename, generate a fallback.
                    if (empty($trackingFilename) === true || strpos($trackingFilename, '?') !== false) {
                        $trackingFilename = 'file_'.md5($actualEndpoint);
                    }
                }

                // Add to tracking array BEFORE attempting fetch (so failures don't affect cleanup).
                if (empty($trackingFilename) === false) {
                    $newFileNames[] = $trackingFilename;
                }
            }//end if
        }//end foreach

        // Always run cleanup, even if newFileNames is empty.
        // This handles the case where all files should be removed from an object.
        $this->cleanupOrphanedFiles(objectId: $targetObjectId, newFileNames: $newFileNames);

    }//end processMultipleFilesWithCleanup()

    /**
     * Cleans up files for an object based on the current attachments array.
     *
     * @param string $objectId    The UUID of the object to clean up files for.
     * @param array  $attachments Array of attachment objects with filename properties.
     *
     * @return int The number of files that were deleted.
     *
     * @throws ContainerExceptionInterface When the container fails to resolve a service.
     * @throws NotFoundExceptionInterface  When a required service is not found.
     * @throws Exception                   When the cleanup encounters an unexpected error.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    public function cleanupFilesFromAttachments(string $objectId, array $attachments): int
    {
        // Extract filenames from attachments array.
        $expectedFileNames = [];
        foreach ($attachments as $attachment) {
            if (isset($attachment['filename']) === true && empty($attachment['filename']) === false) {
                $expectedFileNames[] = $attachment['filename'];
            }
        }

        // Remove duplicates in case the same file appears multiple times with different labels.
        $expectedFileNames = array_unique($expectedFileNames);

        // Use the existing cleanup method.
        return $this->cleanupOrphanedFiles(objectId: $objectId, newFileNames: $expectedFileNames);

    }//end cleanupFilesFromAttachments()

    /**
     * Determines if a file should be published based on the published parameter.
     *
     * @param string|null $published The published parameter from the attachment data.
     *
     * @return bool True if the file should be published, false otherwise.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-4
     */
    private function shouldPublishFile(?string $published): bool
    {
        if ($published === null) {
            return false;
        }

        // Handle boolean true values.
        if ($published === true || $published === 'true' || $published === '1') {
            return true;
        }

        // Handle boolean false values.
        if ($published === false || $published === 'false' || $published === '0') {
            return false;
        }

        // Handle date strings - if it's a valid date string, consider it as published.
        if (is_string($published) === true && empty($published) === false) {
            // Try to parse as a date.
            $date = \DateTime::createFromFormat(\DateTime::ATOM, $published);
            if ($date !== false) {
                return true;
            }

            // Try other common date formats.
            $formats = [
                'Y-m-d',
                'Y-m-d H:i:s',
                'Y-m-d\TH:i:s\Z',
                'Y-m-d\TH:i:sP',
                'Y-m-d\TH:i:s',
            ];
            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $published);
                if ($date !== false) {
                    return true;
                }
            }
        }//end if

        return false;

    }//end shouldPublishFile()
}//end class
