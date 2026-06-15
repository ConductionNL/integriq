<?php
/**
 * OpenConnector endpoint service.
 *
 * Service class for handling endpoint requests. Routes to a schema within a
 * register, proxies to an external source, or dispatches to a generic source
 * connector, applying configured before/after rules and request mappings.
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
use OC\AppFramework\Http;
use OC\Files\Node\File;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;
use OCA\OpenConnector\Exception\AuthenticationException;
use OCA\OpenConnector\Service\Helper\FlowToken;
use OCA\OpenConnector\Util\SafeXmlParser;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use React\Promise\Promise;
use Symfony\Component\Uid\Uuid;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use ValueError;
use function React\Async\await;
use function React\Promise\all;

/**
 * Service class for handling endpoint requests
 *
 * This class provides functionality to handle requests to endpoints, either by
 * connecting to a schema within a register or by proxying to a source.
 *
 * @SuppressWarnings(PHPMD)
 *
 * @spec openspec/changes/openconnector-legacy-quality-cleanup/tasks.md#task-2
 */
class EndpointService
{

    /**
     * Body parameter keys stripped before forwarding a request.
     *
     * @var array<int, string>
     */
    private const UNSET_PARAMETERS = [
        '_parameters',
        '_utility',
        '_method',
        '_headers',
        '_route',
        '_path',
    ];

    /**
     * Constructor for EndpointService.
     *
     * @param ObjectService           $objectService           Service for handling object operations.
     * @param CallService             $callService             Service for making external API calls.
     * @param LoggerInterface         $logger                  Logger interface for error logging.
     * @param IURLGenerator           $urlGenerator            Nextcloud URL generator used for absolute links.
     * @param MappingService          $mappingService          Service used to apply request/response mappings.
     * @param ORObjectService         $orObjectService         OpenRegister object service for register/schema CRUD.
     * @param IConfig                 $config                  Nextcloud system configuration.
     * @param StorageService          $storageService          Service used for file part and attachment storage.
     * @param AuthorizationService    $authorizationService    Service used to authorize incoming endpoint requests.
     * @param ContainerInterface      $containerInterface      PSR container used to resolve optional services.
     * @param SynchronizationService  $synchronizationService  Service used to dispatch endpoint synchronizations.
     * @param RuleService             $ruleService             Service used to load and resolve endpoint rules.
     * @param WebhookSignatureService $webhookSignatureService Service used to verify inbound webhook signatures.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly CallService $callService,
        private readonly LoggerInterface $logger,
        private readonly IURLGenerator $urlGenerator,
        private readonly MappingService $mappingService,
        private readonly ORObjectService $orObjectService,
        private readonly IConfig $config,
        private readonly StorageService $storageService,
        private readonly AuthorizationService $authorizationService,
        private readonly ContainerInterface $containerInterface,
        private readonly SynchronizationService $synchronizationService,
        private readonly RuleService $ruleService,
        private readonly WebhookSignatureService $webhookSignatureService,
    ) {
    }//end __construct()

    /**
     * Parse the error message from the validation service for ZGW format.
     *
     * @param array $response     The response that is build.
     * @param array $responseData The data from the responses found in the rules and openregister.
     *
     * @return array
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-5
     */
    private function parseMessage(array $response, array $responseData): array
    {
        if (isset($responseData['message']) === true
            && $responseData['message'] === 'Validation failed'
            && isset($responseData['errors']) === true
            && str_contains(haystack: $responseData['errors'][0]['message'], needle: 'missing') === true
        ) {
            $startChar = strpos($responseData['errors'][0]['message'], '(') + 1;
            $endChar   = strpos($responseData['errors'][0]['message'], ')');

            $keys = explode(
                separator: ',',
                string: substr(
                    string: $responseData['errors'][0]['message'],
                    offset: $startChar,
                    length: ($endChar - $startChar)
                )
            );

            $response['detail']        = $responseData['errors'][0]['message'];
            $response['invalidParams'] = array_map(
           function (string $key) {
                return ['property' => trim($key), 'code' => 'required', 'reason' => 'The required property is missing'];
           },
            $keys
            );
        } else if (isset($responseData['message']) === true
            && $responseData['message'] === 'Validation failed'
            && isset($responseData['errors']) === true
            && isset($responseData['errors'][0]['errors']) === true
        ) {
            $response['detail']        = $responseData['errors'][0]['message'];
            $response['invalidParams'] = array_map(
           function (string $key, array $message) {
            if (str_contains(haystack: $message[0], needle: 'type') === true) {
                $code = 'invalid type';
            } else {
                $code = 'invalid value';
            }

                return ['property' => $key, 'code' => $code, 'reason' => $message[0]];
           },
            array_keys($responseData['errors'][0]['errors']),
            array_values($responseData['errors'][0]['errors'])
            );
        } else if (isset($responseData['errors']) === true) {
            $response['invalidParams'] = $responseData['errors'];
        }//end if

        return $response;
    }//end parseMessage()

    /**
     * Transform outgoing errors according to a specified format
     *
     * @param Response $result  The result from either the rules or the target of the endpoint.
     * @param IRequest $request The current request, used to determine the request identifier.
     *
     * @return Response
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-5
     */
    private function transformError(Response $result, IRequest $request): Response
    {
        if ($result->getStatus() < 200 || $result->getStatus() >= 300) {
            $resultData = $result->getData();
            $message    = $resultData['message'] ?? null;
            $error      = $resultData['error'] ?? null;

            $responseData = [
                'type'     => $message,
                'code'     => $result->getStatus(),
                'title'    => $message,
                'status'   => $result->getStatus(),
                'instance' => $request->getId(),
                'detail'   => $error,
            ];

            $responseData = $this->parseMessage(response: $responseData, responseData: $resultData);

            return new JSONResponse(data: $responseData, statusCode: $result->getStatus());
        }

        return $result;
    }//end transformError()

    /**
     * Handles incoming requests to endpoints
     *
     * This method determines how to handle the request based on the endpoint configuration.
     * It either routes to a schema within a register or proxies to an external source.
     *
     * @param ObjectEntity $endpoint The endpoint configuration to handle
     * @param IRequest     $request  The incoming request object
     * @param string       $path     The specific path or sub-route being requested
     *
     * @return JSONResponse Response containing the result
     * @throws Exception When endpoint configuration is invalid
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-3
     */
    public function handleRequest(ObjectEntity $endpoint, IRequest $request, string $path): Response
    {
        $endpointData = $endpoint->getObject();
        $errors       = $this->checkConditions(endpoint: $endpoint, request: $request);

        if ($errors !== []) {
            return new JSONResponse(['error' => 'The following parameters are not correctly set', 'fields' => $errors], 400);
        }

        try {
            $flowToken = new FlowToken(requestOriginal: $request, path: $path);

            // Process initial data.
            // $responseBody = $this->parseContent(
            // request: $request,
            //
            // );
            //
            // if ($responseBody == '') {
            // $responseBody = [];
            // }.
            $currentDate = (new DateTime())->format('c');
            // This is double becuase mapping needs it in body but other rules seek directly in data.
            // $incomingMethod = $request->getMethod();
            // $incomingHeaders = $this->getHeaders($request->server, true);
            // $incomingParams = array_merge($request->getParams(), $responseBody);
            //
            // $incomingData = [
            // 'method' => $incomingMethod,
            // 'headers' => $incomingHeaders,
            // 'params' => $incomingParams
            // ];
            // @todo: This should eventually be merged into the flow tokens.
            $data = [
                'utility'    => [
                    'currentDate' => $currentDate,
                ],
                'parameters' => array_merge(
                    $flowToken->getRequestOriginal()['parameters'],
                    $this->getPathParameters(
                        endpointArray: ($endpointData['endpointArray'] ?? []),
                        path: $path
                    )
                ),
                'headers'    => $flowToken->getRequestOriginal()['headers'],
                'path'       => $flowToken->getRequestOriginal()['path'],
                'method'     => $flowToken->getRequestOriginal()['method'],
                'body'       => array_merge(
                        [
                            '_utility'    => [
                                'currentDate' => $currentDate,
                            ],
                            '_parameters' => $flowToken->getRequestOriginal()['parameters'],
                            '_headers'    => $flowToken->getRequestOriginal()['headers'],
                            '_path'       => $flowToken->getRequestOriginal()['path'],
                            '_method'     => $flowToken->getRequestOriginal()['method'],
                        ],
                        $flowToken->getRequestOriginal()['parameters']
                        ),
            ];
            // Process rules before handling the request.
            $ruleResult = $this->processRules(
                endpoint: $endpoint,
                request: $request,
                data: $data,
                timing: 'before',
                flowToken: $flowToken,
            );

            if ($ruleResult instanceof JSONResponse === true) {
                return $this->transformError(result: $ruleResult, request: $request);
            }

            // Update request data with rule processing results.
            $flowToken = $this->updateRequestWithRuleData(flowToken: $flowToken, ruleData: $ruleResult);

            // Check if endpoint connects to a schema.
            if (($endpointData['targetType'] ?? '') === 'register/schema') {
                // Handle CRUD operations via ObjectService.
                $result = $this->handleSchemaRequest(endpoint: $endpoint, flowToken: $flowToken, path: $path);

                // Process initial data.
                $data = [
                    'utility'        => [
                        'currentDate' => (new DateTime())->format('c'),
                    ],
                    'parameters'     => $flowToken->getRequestAmended()['parameters'],
                    'requestHeaders' => $flowToken->getRequestAmended()['headers'],
                    'headers'        => $flowToken->getResponseAmended()['headers'],
                    'path'           => $flowToken->getRequestAmended()['path'],
                    'method'         => $flowToken->getRequestAmended()['method'],
                    'body'           => $flowToken->getResponseOriginal()['data'],
                ];

                $ruleResult = $this->processRules(
                    endpoint: $endpoint,
                    request: $request,
                    data: $data,
                    timing: 'after',
                    objectId: $result->getData()['id'] ?? null,
                    flowToken: $flowToken
                );

                if ($ruleResult instanceof Response === true && $ruleResult->getStatus() >= 200 && $ruleResult->getStatus() < 300) {
                    return $ruleResult;
                }

                if ($ruleResult instanceof JSONResponse === true) {
                    return $this->transformError(result: $ruleResult, request: $request);
                }

                if ($result->getStatus() !== 200 && $result->getStatus() !== 201) {
                    return $this->transformError(result: $result, request: $request);
                }

                // Set the proper status code for the method.
                // @TODO: we might want an override from rule processing.
                switch ($flowToken->getRequestAmended()['method']) {
                    case 'POST':
                        $statusCode = Http::STATUS_CREATED;
                        break;
                    case 'DELETE':
                        $statusCode = Http::STATUS_NO_CONTENT;
                        break;
                    case 'GET':
                    case 'PUT':
                    case 'PATCH':
                    default:
                        $statusCode = Http::STATUS_OK;
                        break;
                }

                $configurations = $endpointData['configurations'] ?? [];
                if (isset($configurations['defaultStatusCode']) === true) {
                    $statusCode = $configurations['defaultStatusCode'];
                }

                return new JSONResponse(data: $ruleResult['body'], statusCode: $statusCode, headers: $ruleResult['headers'] ?? []);
            }//end if

            // Check if endpoint connects to a source.
            if (($endpointData['targetType'] ?? '') === 'api') {
                // Proxy request to source via CallService.
                return $this->handleSourceRequest(endpoint: $endpoint, request: $request);
            }

            // Invalid endpoint configuration.
            throw new Exception('Endpoint must specify either a schema or source connection');
        } catch (Exception $e) {
            // C3 fix: never disclose the stack trace in the response body.
            // This endpoint is @PublicPage — unauthenticated callers must not see internal file
            // paths or class names.  Log the full trace server-side for support lookup.
            $this->logger->error(
                'Error handling endpoint request: '.$e->getMessage(),
                ['exception' => $e]
            );
            return new JSONResponse(
                ['error' => 'Internal server error'],
                400
            );
        }//end try
    }//end handleRequest()

    /**
     * Parses a path to get the parameters in a path.
     *
     * @param array  $endpointArray The endpoint array from an endpoint object.
     * @param string $path          The path called by the client.
     *
     * @return array The parsed path with the fields having the correct name.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-3
     */
    private function getPathParameters(array $endpointArray, string $path): array
    {
        $pathParts = explode(separator: '/', string: $path);

        $endpointArrayNormalized = array_map(
            function ($item) {
                return str_replace(
                    search: ['{{', '{{ ', '}}', '}}'],
                    replace: '',
                    subject: $item
                );
            },
            $endpointArray
                );

        // Normalise both sides to the SHORTER length so array_combine never
        // throws even when the request path doesn't match the endpoint
        // pattern (#1015 follow-up — the existing single array_pop fallback
        // failed when the size delta was > 1, e.g. EndpointService::419 in
        // EndpointServiceTest::testHandleRequestReturns404WhenEndpointObjectMissing).
        $keyCount   = count($endpointArrayNormalized);
        $valueCount = count($pathParts);
        if ($keyCount > $valueCount) {
            $endpointArrayNormalized = array_slice($endpointArrayNormalized, 0, $valueCount);
        } else if ($valueCount > $keyCount) {
            $pathParts = array_slice($pathParts, 0, $keyCount);
        }

        if (count($endpointArrayNormalized) === 0) {
            return [];
        }

        return array_combine(
            keys: $endpointArrayNormalized,
            values: $pathParts
        );
    }//end getPathParameters()

    /**
     * Replaces internal pointers with urls and ids by endpoint urls.
     *
     * @param QBMapper|ORObjectService|ObjectServiceMapperAdapter $mapper           The mapper used to find objects.
     * @param ObjectEntity|null                                   $object           The object to substitute pointers in.
     * @param array                                               $serializedObject The serialized object (if the object is not available).
     * @param array                                               $extend           Optional extend spec controlling which refs are inlined.
     *
     * @return array|null The serialized object including substituted pointers.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-5
     */
    private function replaceInternalReferences(
        QBMapper|ORObjectService|ObjectServiceMapperAdapter $mapper,
        ?ObjectEntity $object=null,
        array $serializedObject=[],
        array $extend=[]
    ): array {
        if ($serializedObject === [] && $object !== null) {
            $serializedObject = $object->jsonSerialize();
        } else {
            $this->objectService->getOpenRegisters()->clearCurrents();
            $object = $mapper->find($serializedObject['id']);
        }

        $uses = (new Dot($object->jsonSerialize()))->flatten();
        // If(isset($serializedObject) === true && !empty($serializedObject['@self']['relations'])) {
        // $uses = $serializedObject['@self']['relations'];
        // }.
        $useUrls = [];

        $uuidToUrlMap = [];
        // Initiate schemaMapper here once for performance.
        $schemaMapper = $this->containerInterface->get('OCA\OpenRegister\Db\SchemaMapper');
        $schema       = $schemaMapper->find($object->getSchema());

        // Find property names that are uris.
        $validUriProperties = [];
        foreach ($schema->getProperties() as $propertyName => $property) {
            if (isset($property['objectConfiguration']['handling']) === true && $property['objectConfiguration']['handling'] === 'uri') {
                $validUriProperties[] = $propertyName;
            }

            if (isset($property['format']) === true && $property['format'] === 'uri') {
                $validUriProperties[] = $propertyName;
            }
        }

        foreach ($uses as $key => $use) {
            $baseKey = explode('.', $key, 2)[0];
            // Skip if the key (or its base form) is not in the valid URI properties.
            if (in_array(needle: $baseKey, haystack: $validUriProperties) === false) {
                continue;
            }

            if (empty($use) === true) {
                continue;
            }

            if (Uuid::isValid(uuid: $use) === true) {
                $useId = $use;
            } else if (str_contains(haystack: $use, needle: 'localhost') === true
                || str_contains(haystack: $use, needle: 'nextcloud.local') === true
                || str_contains(haystack: $use, needle: $this->urlGenerator->getBaseUrl()) === true
            ) {
                $explodedUrl = explode(separator: '/', string: $use);
                $useId       = end($explodedUrl);
            } else {
                unset($uses[$key]);
                continue;
            }

            try {
                $generatedUrl         = $this->generateEndpointUrl(id: $useId, parentIds: [$object->getUuid()], schemaMapper: $schemaMapper);
                $uuidToUrlMap[$useId] = $generatedUrl;
                $useUrls[]            = $generatedUrl;
            } catch (Exception $exception) {
                continue;
            }
        }//end foreach

        // @TODO: correct rewriting self url. This has to be fixed with issue CONNECTOR-314.
        // Add self object URI mapping.
        // $uuidToUrlMap[$object->getUuid()] = $this->generateEndpointUrl(id: $object->getUuid(), schemaMapper: $schemaMapper);.
        $uuidToUrlMap[$object->getUri()] = $this->generateEndpointUrl(id: $object->getUuid(), schemaMapper: $schemaMapper);

        // @TODO: temporary fix for download endpoints. This has to be fixed with issue CONNECTOR-314.
        $uuidToUrlMap[$object->getUri().'/download'] = $this->generateEndpointUrl(id: $object->getUuid(), schemaMapper: $schemaMapper).'/download';

        // Replace UUIDs in serializedObject recursively.
        $serializedObject = $this->replaceUuidsInArray(
            data: $serializedObject,
            uuidToUrlMap: $uuidToUrlMap,
            extend: $this->reduceExtendKeys(extend: $extend)
        );

        return $serializedObject;
    }//end replaceInternalReferences()

    /**
     * Create a reduced list of extend keys and extends for checking purposes
     *
     * @param array $extend The original extend array
     *
     * @return array The reduced extend array
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-5
     */
    private function reduceExtendKeys(array $extend): array
    {
        if (empty($extend) === true) {
            return $extend;
        }

        $reducedKeys = [];

        foreach ($extend as $key => $value) {
            // Normalize input in case it's a simple array: ['something.subthing'].
            if (is_int($key) === true) {
                $actualValue = $value;
            } else {
                $actualValue = $key;
            }

            if (str_contains($actualValue, '.') === false) {
                $reducedKeys[] = $actualValue;
                continue;
            }

            [$prefix, $newKey] = explode('.', $actualValue, 2);

            if (in_array($prefix, $extend, true) === true) {
                $reducedKeys[] = $prefix.'.'.$newKey;
                continue;
            }

            $reducedKeys[] = $actualValue;
        }//end foreach

        $dot = new Dot([], parse: true);
        foreach ($reducedKeys as $path) {
            $dot->set($path, true);
            // True is a safe placeholder for Dot to serialize.
        }

        return $dot->jsonSerialize();
    }//end reduceExtendKeys()

    /**
     * Recursively replaces UUIDs in an array with their corresponding URLs.
     *
     * This function traverses the given array and replaces any UUID values found in the
     * mapping array ($uuidToUrlMap) with their associated URLs. It ensures that 'id' and 'uuid'
     * fields remain unchanged.
     *
     * @param array     $data            The input array that may contain UUIDs.
     * @param array     $uuidToUrlMap    An associative array mapping UUIDs to URLs.
     * @param bool|null $isRelatedObject Are we currently iterating through a related object.
     * @param array     $extend          Optional extend specification controlling which keys are inlined.
     *
     * @return array The modified array with UUIDs replaced by URLs.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-5
     */
    private function replaceUuidsInArray(array $data, array $uuidToUrlMap, ?bool $isRelatedObject=false, array $extend=[]): array
    {
        foreach ($data as $key => $value) {
            // Don't check @self.
            if ($key === '@self') {
                continue;
            }

            // If in array of multiple objects and has id.
            if (is_array($value) === true
                && isset($value['id']) === true
                && isset($uuidToUrlMap[$value['id']]) === true
                && key_exists(key: $key, array: $extend) === false
            ) {
                $data[$key] = $uuidToUrlMap[$value['id']];
                continue;
            }

            // If related object and has id.
            if ($isRelatedObject === true
                && $key === 'id'
                && isset($uuidToUrlMap[$value]) === true
                && key_exists(key: $key, array: $extend) === false
            ) {
                $data[$key] = $uuidToUrlMap[$value];
                continue;
            }

            // Never replace 'id' or 'uuid' fields but only in previous checks.
            if ($key === 'id' || $key === 'uuid') {
                continue;
            }

            if (is_array($value) === true && array_is_list($value) === true && isset($extend[$key]) === true) {
                $extend[$key] = array_fill(0, count($value), $extend[$key]);
            }

            if (is_array($value) === true && empty($value) === false) {
                if (isset($extend[$key]) === true && is_array($extend[$key]) === true) {
                    $nestedExtend = $extend[$key];
                } else {
                    $nestedExtend = $extend;
                }

                $data[$key] = $this->replaceUuidsInArray(
                    data: $value,
                    uuidToUrlMap: $uuidToUrlMap,
                    isRelatedObject: true,
                    extend: $nestedExtend
                );
            } else if (is_string($value) === true && isset($uuidToUrlMap[$value]) === true) {
                $data[$key] = $uuidToUrlMap[$value];
            }
        }//end foreach

        return $data;
    }//end replaceUuidsInArray()

    /**
     * Inverse of replaceInternalReferences, rewriting external references to internal references for query parameters.
     *
     * @param array                                               $parameters The incoming request parameters.
     * @param ORObjectService|ObjectServiceMapperAdapter|QBMapper $mapper     The ObjectService containing the request schema.
     *
     * @return array The updated request parameters.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-5
     */
    private function rewriteExternalReferences(array $parameters, ORObjectService|ObjectServiceMapperAdapter|QBMapper $mapper): array
    {
        $schemaMapper = $this->containerInterface->get('OCA\OpenRegister\Db\SchemaMapper');
        $schema       = $schemaMapper->find($mapper->getSchema());

        $rewriteParameters = array_intersect(array_keys($parameters), array_keys($schema->getProperties()));

        foreach ($rewriteParameters as $rewriteParameter) {
            if (is_array($parameters[$rewriteParameter]) === true) {
                // @TODO: this is a dirty hotfix, here we should run through values
                continue;
            }

            if (filter_var($parameters[$rewriteParameter], FILTER_VALIDATE_URL) === false
                || in_array(parse_url($parameters[$rewriteParameter], PHP_URL_HOST), $this->config->getSystemValue('trusted_domains')) === false
            ) {
                continue;
            }

            $parsedPath = parse_url($parameters[$rewriteParameter], PHP_URL_PATH);
            $parsedPath = substr($parsedPath, 33);
            $epMatches  = $this->orObjectService->findAll(
                config: [
                    'filters' => [
                        'register'      => 'openconnector',
                        'schema'        => 'endpoint',
                        'endpointRegex' => $parsedPath,
                        'method'        => 'GET',
                    ],
                ]
            );
            $epEntities = $epMatches['results'] ?? $epMatches;

            if (count($epEntities) < 1) {
                continue;
            }

            $epEntity  = array_shift($epEntities);
            $epData    = $epEntity->getObject();
            $pathArray = $this->getPathParameters(endpointArray: ($epData['endpointArray'] ?? []), path: $parsedPath);
            $parameters[$rewriteParameter] = [$parameters[$rewriteParameter], end($pathArray)];
        }//end foreach

        return $parameters;
    }//end rewriteExternalReferences()

    /**
     * Fetch objects for the endpoint.
     *
     * @param ORObjectService|ObjectServiceMapperAdapter|QBMapper $mapper     The mapper for the object type.
     * @param array                                               $parameters The parameters from the request.
     * @param array                                               $pathParams The parameters in the path.
     * @param int                                                 $status     The HTTP status to return.
     *
     * @return Entity|array The object(s) confirming to the request.
     *
     * @throws Exception
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-3
     */
    private function getObjects(
        ORObjectService|ObjectServiceMapperAdapter|QBMapper $mapper,
        array $parameters,
        array $pathParams,
        int &$status=200
    ): Entity|array {
        if (isset($pathParams['id']) === true && $pathParams['id'] === end($pathParams)) {
            try {
                $serializedObject = $mapper->find(
                    $pathParams['id'],
                    extend: ($parameters['extend'] ?? $parameters['_extend'] ?? null)
                )->jsonSerialize();
            } catch (DoesNotExistException $e) {
                $status = 404;
                return ['error' => 'not found', 'message' => "the object with id {$pathParams['id']} does not exist"];
            }

            $result = $this->replaceInternalReferences(
                mapper: $mapper,
                serializedObject: $serializedObject,
                extend: $parameters['extend'] ?? $parameters['_extend'] ?? []
            );

            return $result;
        } else if (isset($pathParams['id']) === true) {
            // Set the array pointer to the location of the id, so we can fetch the parameters further down the line in order.
            while (prev($pathParams) !== $pathParams['id']) {
            }

            $property = next($pathParams);

            if (next($pathParams) !== false) {
                $id = pos($pathParams);
            }

            $main = $mapper->find($pathParams['id'])->getObject();

            if (isset($main[$property]) === false) {
                return $this->replaceInternalReferences(mapper: $mapper, object: $mapper->find($pathParams['id']));
            }

            $ids = $main[$property];

            if (empty($ids) === true) {
                $returnArray = [
                    'count'   => 0,
                    'results' => [],
                ];

                return $returnArray;
            }

            if (isset($id) === true && in_array(needle: $id, haystack: $ids) === true) {
                $object = $mapper->find($id);

                return $this->replaceInternalReferences(mapper: $mapper, object: $object);
            } else if (isset($id) === true) {
                $status = 404;
                return ['error' => 'not found', 'message' => "the subobject with id $id does not exist"];
            }

            $results = $mapper->findAll(['ids' => $ids]);
            foreach ($results as $key => $result) {
                $results[$key] = $this->replaceInternalReferences(mapper: $mapper, object: $result);
            }

            $returnArray = [
                'count'   => count($results),
                'results' => $results,
            ];

            return $returnArray;
        }//end if

        $parameters = $this->rewriteExternalReferences(parameters: $parameters, mapper: $mapper);

        if (isset($parameters['_limit']) === false && isset($parameters['limit']) === false) {
            $parameters['_limit'] = 30;
        }

        $result   = $mapper->findAllPaginated(requestParams: $parameters);
        $promises = [];

        foreach ($result['results'] as $index => $object) {
            $promises[$index] = new Promise(
                function ($resolve, $reject) use ($object, $mapper) {
                    try {
                        $updatedObject = $this->replaceInternalReferences(mapper: $mapper, serializedObject: $object->jsonSerialize());
                        $resolve($updatedObject);
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                }
            );
        }

        $result['results'] = await(all($promises));

        $returnArray = [
            'count' => $result['total'],
        ];

        if ($result['page'] < $result['pages']) {
            $parameters['page']  = $result['page'] + 1;
            $parameters['_path'] = implode('/', $pathParams);

            $returnArray['next'] = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute(
                    routeName: 'openconnector.endpoints.handlePathRead',
                    arguments: $parameters
                )
            );
        }

        if ($result['page'] > 1) {
            $parameters['page']  = $result['page'] - 1;
            $parameters['_path'] = implode('/', $pathParams);

            $returnArray['previous'] = $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute(
                    routeName: 'openconnector.endpoints.handlePathRead',
                    arguments: $parameters
                )
            );
        }

        $returnArray['results'] = $result['results'];

        return $returnArray;
    }//end getObjects()

    /**
     * Handles requests for schema-based endpoints.
     *
     * @param ObjectEntity $endpoint  The endpoint configuration.
     * @param FlowToken    $flowToken The current flow token (passed by reference for amendment).
     * @param string       $path      The path called by the client.
     *
     * @return JSONResponse
     *
     * @throws DoesNotExistException|LoaderError|MultipleObjectsReturnedException|SyntaxError
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-3
     */
    private function handleSchemaRequest(ObjectEntity $endpoint, FlowToken &$flowToken, string $path): JSONResponse
    {
        $endpointData = $endpoint->getObject();
        // @TODO: CONVERT TO FLOWTOKENS
        // Get request method
        $method = $flowToken->getRequestAmended()['method'];
        $target = explode('/', $endpointData['targetId'] ?? '');

        $register = $target[0];
        $schema   = $target[1];

        $mapper = $this->objectService->getMapper(schema: (int) $schema, register: (int) $register);

        $parameters = $flowToken->getRequestAmended()['parameters'];

        if (($endpointData['inputMapping'] ?? null) !== null) {
            $inputMapping = $this->mappingService->getMapping($endpointData['inputMapping']);
            $parameters   = $this->mappingService->executeMapping(mapping: $inputMapping, input: $parameters);
        }

        $pathParams = $this->getPathParameters(endpointArray: ($endpointData['endpointArray'] ?? []), path: $path);

        if (isset($pathParams['id']) === true) {
            $parameters['id'] = $pathParams['id'];
        }

        foreach ($this::UNSET_PARAMETERS as $parameter) {
            unset($parameters[$parameter]);
        }

        $status = 200;

        $headers = [];
        if (isset($flowToken->getRequestAmended()['headers']['Accept-Crs']) === true
            && $flowToken->getRequestAmended()['headers']['Accept-Crs'] !== ''
        ) {
            $headers['Content-Crs'] = $flowToken->getRequestAmended()['headers']['Accept-Crs'];
        }

        // Route to appropriate ObjectService method based on HTTP method.
        try {
            switch ($method) {
                case 'GET':
                    $response = new JSONResponse(
                        $this->getObjects(mapper: $mapper, parameters: $parameters, pathParams: $pathParams, status: $status),
                        statusCode: $status,
                        headers: $headers
                    );
                    $flowToken->setResponseOriginal($response);
                    $flowToken->setResponseAmended($flowToken->getResponseOriginal());
                    return $response;
                case 'POST':
                    $response = new JSONResponse(
                        $this->replaceInternalReferences(
                            mapper: $mapper,
                            serializedObject: $mapper->createFromArray(object: $parameters)->jsonSerialize()
                        )
                    );
                    $flowToken->setResponseOriginal($response);
                    $flowToken->setResponseAmended($flowToken->getResponseOriginal());
                    return $response;
                case 'PUT':
                    $putUpdated = $mapper->updateFromArray(
                        $parameters['id'],
                        $flowToken->getRequestAmended()['parameters'],
                        true,
                        false
                    );
                    $response   = new JSONResponse(
                        $this->replaceInternalReferences(
                            mapper: $mapper,
                            serializedObject: $putUpdated->jsonSerialize()
                        )
                    );
                    $flowToken->setResponseOriginal($response);
                    $flowToken->setResponseAmended($flowToken->getResponseOriginal());
                    return $response;
                case 'PATCH':
                    $patchUpdated = $mapper->updateFromArray(
                        $parameters['id'],
                        $flowToken->getRequestAmended()['parameters'],
                        true,
                        true
                    );
                    $response     = new JSONResponse(
                        $this->replaceInternalReferences(
                            mapper: $mapper,
                            serializedObject: $patchUpdated->jsonSerialize()
                        )
                    );
                    $flowToken->setResponseOriginal($response);
                    $flowToken->setResponseAmended($flowToken->getResponseOriginal());
                    return $response;
                case 'DELETE':
                    if (isset($parameters['id']) === false) {
                        $response = new JSONResponse(data: ['error' => 'No id given to delete'], statusCode: 400);
                        $flowToken->setResponseOriginal($response);
                        return $response;
                    }

                    if ($mapper->delete(['id' => $parameters['id']]) !== true) {
                        $response = new JSONResponse(
                            data: ['error' => sprintf('Something went wrong deleting object: %s', $parameters['id'])],
                            statusCode: 500
                        );
                        $flowToken->setResponseOriginal($response);
                        $flowToken->setResponseAmended($flowToken->getResponseOriginal());
                        return $response;
                    }

                    $response = new JSONResponse(statusCode: 204);
                    $flowToken->setResponseOriginal($response);
                    $flowToken->setResponseAmended($flowToken->getResponseOriginal());
                    return $response;

                default:
                    throw new Exception('Unsupported HTTP method');
            }//end switch
        } catch (Exception $exception) {
            $validationExceptions = [
                'OCA\OpenRegister\Exception\ValidationException',
                'OCA\OpenRegister\Exception\CustomValidationException',
            ];
            if (in_array(get_class($exception), $validationExceptions) === true) {
                return $mapper->getValidateHandler()->handleValidationException(exception: $exception);
            }

            throw $exception;
        }//end try

    }//end handleSchemaRequest()

    /**
     * Gets the raw content for a http request from the input stream.
     *
     * @return string The raw content body for a http request
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-5
     */
    private function getRawContent(): string
    {
        return file_get_contents(filename: 'php://input');
    }//end getRawContent()

    /**
     * Get all headers for a HTTP request.
     *
     * @param array $server       The server data from the request.
     * @param bool  $proxyHeaders Whether the proxy headers should be returned.
     *
     * @return array The resulting headers.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-5
     */
    private function getHeaders(array $server, bool $proxyHeaders=false): array
    {
        $headers = array_filter(
            array: $server,
            callback: function (string $key) use ($proxyHeaders) {
                if (str_starts_with($key, 'HTTP_') === false) {
                    return false;
                } else if ($proxyHeaders === false
                    && (str_starts_with(haystack: $key, needle: 'HTTP_X_FORWARDED') === true
                    || $key === 'HTTP_X_REAL_IP' || $key === 'HTTP_X_ORIGINAL_URI')
                ) {
                    return false;
                }

                return true;
            },
            mode: ARRAY_FILTER_USE_KEY
        );

        $keys = array_keys($headers);

        return array_combine(
            array_map(
                callback: function ($key) {
                    return strtolower(string: substr(string: $key, offset: 5));
                },
                array: $keys
                    ),
            $headers
        );
    }//end getHeaders()

    /**
     * Check conditions for using an endpoint.
     *
     * @param ObjectEntity $endpoint The endpoint for which the checks should be done.
     * @param IRequest     $request  The inbound request.
     *
     * @return array
     * @throws Exception
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-3
     */
    private function checkConditions(ObjectEntity $endpoint, IRequest $request): array
    {
        $endpointData       = $endpoint->getObject();
        $conditions         = ($endpointData['conditions'] ?? []);
        $data['parameters'] = $request->getParams();
        $data['headers']    = $this->getHeaders(server: $_SERVER, proxyHeaders: true);

        $result = JsonLogic::apply(logic: $conditions, data: $data);

        if ($result === true || $result === [] || $result === null) {
            return [];
        }

        return $result;
    }//end checkConditions()

    /**
     * Handles requests for source-based endpoints
     *
     * @param ObjectEntity $endpoint The endpoint configuration
     * @param IRequest     $request  The incoming request
     *
     * @return JSONResponse
     * @throws GuzzleException|LoaderError|SyntaxError|\OCP\DB\Exception
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-3
     */
    private function handleSourceRequest(ObjectEntity $endpoint, IRequest $request): JSONResponse
    {
        $endpointData = $endpoint->getObject();
        $headers      = $this->getHeaders(server: $_SERVER);

        // Fetch the source entity by targetId.
        $source = $this->orObjectService->find(id: ($endpointData['targetId'] ?? ''), register: 'openconnector', schema: 'source');

        // Proxy the request to the source via CallService.
        $callLog     = $this->callService->call(
            source: $source,
            endpoint: $endpointData['endpoint'] ?? '',
            method: $request->getMethod(),
            config: [
                'query'   => $request->getParams(),
                'headers' => $headers,
                'body'    => $this->getRawContent(),
            ]
        );
        $callLogData = $callLog->getObject();

        return new JSONResponse(
            $callLogData['response'] ?? [],
            $callLogData['statusCode'] ?? 200
        );
    }//end handleSourceRequest()

    /**
     * Generates url based on available endpoints for the object type.
     *
     * @param string                            $id           The id of the object to generate an url for.
     * @param \OCA\OpenRegister\Db\SchemaMapper $schemaMapper The mapper to get schemas
     * @param int|null                          $register     The register of the object (aids performance).
     * @param int|null                          $schema       The schema of the object (aids performance).
     * @param array                             $parentIds    The ids of the main object on subobjects.
     *
     * @return string The generated url.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-3
     */
    public function generateEndpointUrl(
        string $id,
        \OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
        ?int $register=null,
        ?int $schema=null,
        array $parentIds=[]
    ): string {
        if ($register === null) {
            $object   = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find($id);
            $register = $object->getRegister();
            $schema   = $object->getSchema();
        }

        $target    = "$register/$schema";
        $epMatches = $this->orObjectService->findAll(
            config: [
                'filters' => [
                    'register' => 'openconnector',
                    'schema'   => 'endpoint',
                    'targetId' => $target,
                    'method'   => 'GET',
                ],
            ]
        );
        $endpoints = $epMatches['results'] ?? $epMatches;

        if (count($endpoints) === 0) {
            return $id;
        }

        $epEntity          = $endpoints[0];
        $filteredEndpoints = array_filter(
                $endpoints,
                function (ObjectEntity $epEntity) {
                    $epData = $epEntity->getObject();
                    return in_array(needle: '{{id}}', haystack: ($epData['endpointArray'] ?? [])) === true;
                }
                );

        if (count($filteredEndpoints) > 0) {
            $epEntity = array_shift($filteredEndpoints);
        }

        $location = ($epEntity->getObject())['endpointArray'] ?? [];

        // Determine schema title (lowercased).
        $schemaTitle = strtolower($schemaMapper->find($schema)->getTitle());

        // Use first parentId if available.
        $parentId = ($parentIds[0] ?? null);

        // Make sure we are dealing with a sub endpoint.
        $isSubEndpoint = false;
        foreach ($location as $key => $part) {
            if (preg_match('#{{([^}]+)}}#', $part, $matches) === 1) {
                $placeholder = trim($matches[1]);
                if ($placeholder === "{$schemaTitle}_id") {
                    $isSubEndpoint = true;
                }
            }
        }

        foreach ($location as $key => $part) {
            if (preg_match('#{{([^}]+)}}#', $part, $matches) === 1) {
                $placeholder = trim($matches[1]);

                if ($placeholder === 'id' && $parentId !== null && $isSubEndpoint === true) {
                    // Replace {{id}} with parent id if set.
                    $location[$key] = $parentId;
                } else if ($placeholder === 'id') {
                    // Otherwise, replace {{id}} with current object id.
                    $location[$key] = $id;
                } else if ($placeholder === "{$schemaTitle}_id") {
                    // Replace {{schematitle_id}} with object id.
                    $location[$key] = $id;
                }
            }
        }

        $path = implode(separator: '/', array: $location);
        return $this->urlGenerator->getBaseUrl().'/apps/openconnector/api/endpoint/'.$path;
    }//end generateEndpointUrl()

    /**
     * Saves object to OpenRegister.
     *
     * @param ObjectEntity $rule The save_object rule to apply.
     * @param array        $data The current rule data envelope (body/headers/parameters).
     *
     * @return array The updated $data with the saved object merged into the body.
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-2
     */
    private function processSaveObjectRule(ObjectEntity $rule, array $data): array
    {
        $configuration = $rule->getObject()['configuration'] ?? [];
        $register      = $configuration['save_object']['register'];
        $schema        = $configuration['save_object']['schema'];
        $mapping       = $configuration['save_object']['mapping'] ?? null;

        if (isset($mapping) === true) {
            $data = $this->processMapping(rule: $rule, mapping: $mapping, data: $data);
        }

        $objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
        $data['body']  = $objectService->saveObject(register: $register, schema: $schema, object: $data['body']);

        return $data;
    }//end processSaveObjectRule()

    /**
     * Processes rules for an endpoint request.
     *
     * @param ObjectEntity   $endpoint  The endpoint being processed.
     * @param IRequest       $request   The incoming request.
     * @param array          $data      Current request data envelope.
     * @param string         $timing    Rule timing to filter by ("before" or "after").
     * @param string|null    $objectId  Optional object id (for rules scoped to a single object).
     * @param FlowToken|null $flowToken Optional flow token threaded through the rule chain.
     *
     * @return array|JSONResponse Returns modified data or error response if rule fails.
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-1
     */
    private function processRules(
        ObjectEntity $endpoint,
        IRequest $request,
        array $data,
        string $timing,
        ?string $objectId=null,
        FlowToken $flowToken=null
    ): array|Response {
        $endpointData = $endpoint->getObject();
        $rules        = $endpointData['rules'] ?? [];
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
            usort($ruleEntities, fn($a, $b) => (($a->getObject())['order'] ?? 0) - (($b->getObject())['order'] ?? 0));

            // Process each rule in order.
            foreach ($ruleEntities as $rule) {
                // Skip if rule action doesn't match request method.
                // if (strtolower($ruleData['action']) !== strtolower($request->getMethod())) {
                // continue;
                // }.
                $ruleData    = $rule->getObject();
                $logicResult = null;

                $data['flowToken'] = $flowToken->__serialize();

                $conditionsPassed = $this->checkRuleConditions(rule: $rule, data: $data, logicResult: $logicResult);
                $timingMatches    = (($ruleData['timing'] ?? 'before') === $timing);

                // Check rule conditions.
                if ($conditionsPassed === false || $timingMatches === false) {
                    $this->logger->info(
                        'Rule condition check failed for endpoint '.($endpointData['name'] ?? '')
                        .' and rule '.($ruleData['name'] ?? '')
                        .' of type: '.($ruleData['type'] ?? '')
                    );

                    continue;
                }

                if (is_string($logicResult) === true && json_decode(json: $logicResult, associative: true) !== null) {
                    $data['logicResult'] = json_decode($logicResult, true);
                } else {
                    $data['logicResult'] = $logicResult;
                }

                $this->logger->info(
                    'Applying rule for endpoint '.($endpointData['name'] ?? '')
                    .' with rule '.($ruleData['name'] ?? '')
                    .' of type '.($ruleData['type'] ?? '')
                );

                // At this moment, setting flowToken in $data when processing rules will result in data contamination.
                unset($data['flowToken']);

                // Process rule based on type.
                try {
                    $result = match (($ruleData['type'] ?? '')) {
                        'save_object' => $this->processSaveObjectRule(rule: $rule, data: $data),
                        'authentication' => $this->processAuthenticationRule(rule: $rule, data: $data),
                        'error' => $this->processErrorRule(rule: $rule, data: $data),
                        'mapping' => $this->processMappingRule(rule: $rule, data: $data),
                        'synchronization' => $this->processSyncRule(rule: $rule, data: $data, flowToken: $flowToken),
                        'javascript' => $this->processJavaScriptRule(rule: $rule, data: $data),
                        'fileparts_create' => $this->processFilePartRule(rule: $rule, data: $data, endpoint: $endpoint, objectId: $objectId),
                        'filepart_upload' => $this->processFilePartUploadRule(rule: $rule, data: $data, request: $request, objectId: $objectId),
                        'download' => $this->processDownloadRule(rule: $rule, data: $data, objectId: $objectId),
                        'extend_input' => $this->processExtendInputRule(rule: $rule, data: $data),
                        'extend_external_input' => $this->ruleService->extendExternalUrl(rule: $rule, data: $data),
                        'audit_trail' => $this->processAuditTrailRule(rule: $rule, endpoint: $endpoint, data: $data, objectId: $objectId),
                        'write_file' => $this->processWriteFileRule(rule: $rule, data: $data, objectId: $objectId, flowToken: $flowToken),
                        'locking' => $this->processLockingRule(rule: $rule, data: $data, objectId: $objectId),
                        'override' => $this->processOverrideRule(rule: $rule, data: $data, objectId: $objectId),
                        'webhook_signature' => $this->processWebhookSignatureRule(rule: $rule, data: $data, request: $request),
                        'custom' => $this->processCustomRule(rule: $rule, data: $data),
                        default => throw new Exception('Unsupported rule type: '.($ruleData['type'] ?? '')),
                    };
                } catch (Exception $e) {
                    $message = 'Failed to apply rule for endpoint '.($endpointData['name'] ?? '')
                        .' with rule '.($ruleData['name'] ?? '')
                        .' of type '.($ruleData['type'] ?? '')
                        .'. With error message: '.$e->getMessage();
                    $this->logger->error($message);
                    return new JSONResponse(['error' => 'Rule processing failed'], 500);
                }//end try

                // If result is JSONResponse, return error immediately.
                if ($result instanceof JSONResponse === true || $result instanceof DataDownloadResponse === true) {
                    return $result;
                }

                // Update data with rule result.
                $data = $result;

                $this->logger->info(
                    'Successfully applied rule for endpoint '.($endpointData['name'] ?? '')
                    .' with rule '.($ruleData['name'] ?? '')
                    .' of type '.($ruleData['type'] ?? '')
                );
            }//end foreach

            unset($data['body']['_extendedInput']);

            return $data;
        } catch (Exception $e) {
            $this->logger->error('Error processing rules: '.$e->getMessage());
            return new JSONResponse(['error' => 'Rule processing failed'], 500);
        }//end try
    }//end processRules()

    /**
     * This rule, that only can be run on timing 'after' overrides the content of a written object by the updated contents in the flow token.
     *
     * @param ObjectEntity $rule     The rule to process.
     * @param array        $data     The data from the flow token.
     * @param string       $objectId The object to override.
     *
     * @return array The updated object.
     *
     * @throws ContainerExceptionInterface
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws NotFoundExceptionInterface
     * @throws \OCP\DB\Exception
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-2
     */
    private function processOverrideRule(ObjectEntity $rule, array $data, string $objectId): array
    {

        $this->objectService->getOpenRegisters()->clearCurrents();
        $object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find($objectId);
        $object->setObject($data['body']);
        $object = $this->objectService->getOpenRegisters()->saveObject(
            object: $object,
            register: $object->getRegister(),
            schema: $object->getSchema(),
            uuid: $object->getUuid()
        );
        $this->objectService->getOpenRegisters()->clearCurrents();

        $data['body'] = $object->jsonSerialize();

        return $data;
    }//end processOverrideRule()

    /**
     * Get a rule by its ID using OR ObjectService
     *
     * @param string $id The unique identifier of the rule
     *
     * @return ObjectEntity|null The rule entity if found, or null if not found
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-3
     */
    private function getRuleById(string $id): ?ObjectEntity
    {
        try {
            return $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'rule');
        } catch (Exception $e) {
            $this->logger->error('Error fetching rule: '.$e->getMessage());
            return null;
        }
    }//end getRuleById()

    /**
     * Verify an inbound webhook signature over the RAW request body.
     *
     * Acts as a pre-pipeline gate: it reads the raw body bytes (before any
     * decode/mapping), verifies the configured signature header with
     * constant-time comparison via WebhookSignatureService, and short-circuits
     * the pipeline with an undifferentiated 401 on ANY verification failure
     * (missing/malformed header, digest mismatch, stale timestamp). On success
     * the unchanged $data flows on to the remaining rules.
     *
     * Operators MUST order this rule ahead of any body-transforming or
     * side-effecting rule (lowest `order`); it never mutates $data.
     *
     * @param ObjectEntity $rule    The webhook_signature rule.
     * @param array        $data    The request data of the pipeline.
     * @param IRequest     $request The inbound request (for raw body access).
     *
     * @return array|JSONResponse The unchanged $data on pass, or a 401 JSONResponse.
     *
     * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-4
     */
    private function processWebhookSignatureRule(ObjectEntity $rule, array $data, IRequest $request): array|JSONResponse
    {
        $configuration = ($rule->getObject()['configuration'] ?? []);
        // Config lives in the type slot (configuration.webhook_signature), with
        // a root-level fallback for hand-authored rules.
        $config = ($configuration['webhook_signature'] ?? $configuration);

        $scheme     = ($config['scheme'] ?? 'openconnector');
        $secret     = (string) ($config['secret'] ?? '');
        $headerName = ($config['header'] ?? 'X-OpenConnector-Signature');
        $tolerance  = (int) ($config['toleranceSeconds'] ?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS);

        // Read the raw body bytes BEFORE any decode/mapping.
        $rawBody = $this->getRawContent();

        // Case-insensitive header lookup.
        $headerValue = (string) $request->getHeader($headerName);

        $verified = $this->webhookSignatureService->verify(
            rawBody: $rawBody,
            headerValue: $headerValue,
            config: [
                'scheme'           => $scheme,
                'secret'           => $secret,
                'toleranceSeconds' => $tolerance,
            ]
        );

        if ($verified === false) {
            // Undifferentiated error body: never leak which check failed.
            return new JSONResponse(['error' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
        }

        return $data;

    }//end processWebhookSignatureRule()

    /**
     * Processes authentication rules
     *
     * @param ObjectEntity $rule The rule to process
     * @param array        $data The data of the request
     *
     * @return array|JSONResponse the unchanged $data array if authentication succeeds, or a JSONResponse containing an error on authentication.
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-3
     */
    private function processAuthenticationRule(ObjectEntity $rule, array $data): array|JSONResponse
    {
        $configuration = $rule->getObject()['configuration'] ?? [];

        // Normalise all incoming header keys to lowercase once so all subsequent
        // lookups are case-insensitive without multiple fallback variants.
        $normalisedHeaders = [];
        foreach (($data['headers'] ?? []) as $key => $value) {
            $normalisedHeaders[strtolower((string) $key)] = $value;
        }

        // Default to the Authorization header (lowercase-normalised lookup).
        $header = ($normalisedHeaders['authorization'] ?? '');

        if (isset($configuration['authentication']) === false) {
            return $data;
        }

        if (isset($configuration['authentication']['header']) === true) {
            // Convert configured header name to lowercase + underscore variant
            // for a single normalised lookup against $normalisedHeaders.
            $lookupKey = strtolower((string) $configuration['authentication']['header']);
            $header    = ($normalisedHeaders[$lookupKey] ?? null);
        }

        if ($header === '' || $header === null) {
            return new JSONResponse(
                ['error' => 'forbidden', 'details' => 'you are not allowed to access this endpoint unauththenticated'],
                Http::STATUS_FORBIDDEN
            );
        }

        switch ($configuration['authentication']['type']) {
            case 'apikey':
                try {
                    $this->authorizationService->authorizeApiKey(header: $header, keys: $configuration['authentication']['keys']);
                } catch (AuthenticationException $exception) {
                    return new JSONResponse(
                        data: ['error' => $exception->getMessage(), 'details' => $exception->getDetails()],
                        statusCode: 401
                    );
                }
                break;
            case 'jwt':
            case 'jwt-zgw':
                try {
                    $this->authorizationService->authorizeJwt(authorization: $header);
                } catch (AuthenticationException $exception) {
                    return new JSONResponse(
                        data: ['error' => $exception->getMessage(), 'details' => $exception->getDetails()],
                        statusCode: 401
                    );
                }
                break;
            case 'basic':
                try {
                    $this->authorizationService->authorizeBasic(
                        $header,
                        $configuration['authentication']['users'],
                        $configuration['authentication']['groups']
                    );
                } catch (AuthenticationException $exception) {
                    return new JSONResponse(
                        data: ['error' => $exception->getMessage(), 'details' => $exception->getDetails()],
                        statusCode: 401
                    );
                }
                break;
            case 'oauth':
                try {
                    $this->authorizationService->authorizeOAuth(
                        $header,
                        $configuration['authentication']['users'],
                        $configuration['authentication']['groups']
                    );
                } catch (AuthenticationException $exception) {
                    return new JSONResponse(
                        data: ['error' => $exception->getMessage(), 'details' => $exception->getDetails()],
                        statusCode: 401
                    );
                }
                break;
            default:
                return new JSONResponse(data: ['error' => 'The authentication method is not supported'], statusCode: Http::STATUS_NOT_IMPLEMENTED);
        }//end switch

        return $data;
    }//end processAuthenticationRule()

    /**
     * Processes an error rule.
     *
     * @param ObjectEntity $rule The rule object containing error details.
     * @param array        $data Optional rule data containing JSON-logic result for inclusion.
     *
     * @return JSONResponse Response containing error details and HTTP status code.
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-3
     */
    private function processErrorRule(ObjectEntity $rule, array $data=[]): JSONResponse
    {
        $config = $rule->getObject()['configuration'] ?? [];

        $response = [
            'error'   => $config['error']['name'],
            'message' => $config['error']['message'],
        ];

        // Include the json logic result as errors array.
        if ($config['error']['includeJsonLogicResult'] === true) {
            $response['errors'] = $data['logicResult'];
        }

        return new JSONResponse(
            $response,
            $config['error']['code']
        );
    }//end processErrorRule()

    /**
     * Executes mapping on data from endpoint flow.
     *
     * @param ObjectEntity $rule    The mapping rule context.
     * @param ObjectEntity $mapping The mapping object to apply.
     * @param array        $data    The current rule data envelope (body/headers/parameters).
     *
     * @return array The updated $data with mapped body merged in.
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-2
     */
    private function processMapping(ObjectEntity $rule, ObjectEntity $mapping, array $data): array
    {
        $config   = $rule->getObject()['configuration'] ?? [];
        $ruleData = $rule->getObject();
        // Todo: We should just remove this if statement and use mapping to loop through results instead.
        if (isset($data['body']['results']) === true
            && strtolower($ruleData['action'] ?? '') === 'get'
            && (isset($config['mapResults']) === false || $config['mapResults'] === true)
        ) {
            foreach (($data['body']['results']) as $key => $result) {
                $data['body']['results'][$key] = $this->mappingService->executeMapping($mapping, $result);
            }

            return $data;
        }

        $data['body'] = $this->mappingService->executeMapping($mapping, $data['body']);

        return $data;
    }//end processMapping()

    /**
     * Processes a mapping rule
     *
     * @param ObjectEntity $rule The rule object containing mapping details
     * @param array        $data The data to be processed through the mapping rule
     *
     * @return array The processed data after applying the mapping rule
     * @throws DoesNotExistException When the mapping configuration does not exist
     * @throws MultipleObjectsReturnedException When multiple mapping objects are returned unexpectedly
     * @throws LoaderError When there is an error loading the mapping
     * @throws SyntaxError When there is a syntax error in the mapping configuration
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-2
     */
    private function processMappingRule(ObjectEntity $rule, array $data): array
    {
        $config  = $rule->getObject()['configuration'] ?? [];
        $mapping = $this->mappingService->getMapping($config['mapping']);

        $data = $this->processMapping(rule: $rule, mapping: $mapping, data: $data);

        return $data;
    }//end processMappingRule()

    /**
     * Extends input for performing business logic
     *
     * @param ObjectEntity $rule The rule containing the configuration which parameters could be extended
     * @param array        $data The data array containing the input parameters.
     *
     * @return array The data array with the extended parameters in the 'extendedParameters' key.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-2
     */
    private function processExtendInputRule(ObjectEntity $rule, array $data): array
    {
        $parameters = new Dot($data['parameters']);
        $config     = $rule->getObject()['configuration'] ?? [];
        $extendedParameters = new Dot();

        foreach ($config['extend_input']['properties'] as $property) {
            $value = $parameters->get($property);

            if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
                $exploded = explode(separator: '/', string: $value);
                $value    = end($exploded);
            }

            // Only allow uuids in the extend_input rule.
            if (Uuid::isValid($value) === false) {
                continue;
            }

            $extends = [];
            if (isset($config['extend_input']['extends']) === true && isset($config['extend_input']['extends'][$property]) === true) {
                $extends = $config['extend_input']['extends'][$property];
            }

            try {
                $object = $this->objectService->getOpenRegisters()->find(id: $value, extend: $extends);
                $this->objectService->getOpenRegisters()->clearCurrents();
            } catch (DoesNotExistException $exception) {
                $this->objectService->getOpenRegisters()->clearCurrents();
                continue;
            }

            $extendedParameters->add($property, $object->jsonSerialize());
        }//end foreach

        if (isset($data['extendedParameters']) === true) {
            $data['extendedParameters'] = array_merge($extendedParameters->all(), $data['extendedParameters']);
        } else {
            $data['extendedParameters'] = $extendedParameters->all();
        }

        $data['body']['_extendedInput'] = $data['extendedParameters'];

        return $data;
    }//end processExtendInputRule()

    /**
     * Fetches the audit trail for an object, returns a specific audit rule if the path parameter audittrail-id is specified.
     *
     * @param ObjectEntity $rule     The rule to execute
     * @param ObjectEntity $endpoint The endpoint on which the rule is executed
     * @param array        $data     The data from the request.
     * @param string       $objectId The object id for which the request was done.
     *
     * @return array|Response The updated data array, or a json response with a not found error.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-2
     */
    private function processAuditTrailRule(ObjectEntity $rule, ObjectEntity $endpoint, array $data, string $objectId): array|Response
    {
        $endpointData   = $endpoint->getObject();
        $pathParameters = $this->getPathParameters(endpointArray: ($endpointData['endpointArray'] ?? []), path: $data['path']);

        if (isset($pathParameters['audittrail-id']) === true) {
            $auditrule = $this->objectService->getOpenRegisters()->getLogs($objectId, filters: ['uuid' => $pathParameters['audittrail-id']]);

            if (count($auditrule) === 1) {
                $data['body'] = $auditrule[0];
                return $data;
            }

            return new JSONResponse(
                data: ['error' => 'Not found', 'reason' => 'The resource you are looking for does not exist'],
                statusCode: HTTP::STATUS_NOT_FOUND
            );
        }

        $audittrail = $this->objectService->getOpenRegisters()->getLogs($objectId);

        $data['body'] = $audittrail;

        return $data;
    }//end processAuditTrailRule()

    /**
     * Process a locking rule, either locking or unlocking a resource.
     *
     * @param ObjectEntity $rule     Rule containing configuration for the execution of the rule.
     * @param array        $data     The data to update.
     * @param string       $objectId The object id of the object to lock or unlock.
     *
     * @return array The updated data array.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \OCP\Files\NotFoundException
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-2
     */
    private function processLockingRule(ObjectEntity $rule, array $data, string $objectId): array
    {
        $config = $rule->getObject()['configuration'] ?? [];

        if ($config['locking']['action'] === 'lock') {
            $process = (Uuid::v4())->jsonSerialize();
            $object  = $this->objectService->getOpenRegisters()->lockObject(
                identifier: $objectId,
                process: $process,
                duration: ($config['locking']['duration'] ?? 3600)
            );
        } else if ($config['locking']['action'] === 'unlock') {
            $object = $this->objectService->getOpenRegisters()->unlockObject(identifier: $objectId);
        } else {
            // Unknown locking action — leave $data untouched.
            return $data;
        }

        $data['body'] = $object->jsonSerialize();

        return $data;
    }//end processLockingRule()

    /**
     * Process a custom rule
     *
     * @param ObjectEntity $rule The rule to process
     * @param array        $data The data to process
     *
     * @return array The updated data array.
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-5
     */
    private function processCustomRule(ObjectEntity $rule, array $data): array|JSONResponse
    {
        return $this->ruleService->processCustomRule(rule: $rule, data: $data);
    }//end processCustomRule()

    /**
     * Process a rule to write files.
     *
     * @param ObjectEntity $rule      The rule to process.
     * @param array        $data      The data to write.
     * @param string       $objectId  The object to write the data to.
     * @param FlowToken    $flowToken The flow token carrying the request/response state.
     *
     * @return array
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-4
     */
    private function processWriteFileRule(Rule $rule, array $data, string $objectId, FlowToken $flowToken): array
    {
        $ruleConfig = $rule->getObject()['configuration'] ?? [];
        if (isset($ruleConfig['write_file']) === false) {
            throw new Exception('No configuration found for write_file');
        }

        $config         = $ruleConfig['write_file'];
        $dataDot        = new Dot($data);
        $flowTokenArray = $flowToken->getRequestOriginal();
        $flowTokenArray['body'] = $flowTokenArray['parameters'];
        $flowTokenDot           = new Dot($flowTokenArray);

        $files = $dataDot[$config['filePath']] ?? $flowTokenDot[$config['filePath']];
        if (isset($files) === false || empty($files) === true) {
            return $dataDot->jsonSerialize();
        }

        // Check if associative array.
        if (is_array($files) === true && isset($files[0]) === true & array_keys($files[0]) !== range(0, count($files[0]) - 1)) {
            $result = [];
            foreach ($files as $key => $value) {
                // Check for tags.
                $tags     = [];
                $fileName = null;
                if (is_array($value) === true) {
                    $content = $value['content'];
                    if (isset($value['label']) === true && isset($config['tags']) === true
                        && in_array(needle: $value['label'], haystack: $config['tags']) === true
                    ) {
                        $tags = [$value['label']];
                    }

                    if (isset($value['filename']) === true) {
                        $fileName = basename($value['filename']);
                    }
                } else {
                    $content = $value;
                }

                try {
                    // Write file with OpenRegister ObjectService.
                    $objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
                    $fileService   = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');
                    $file          = $fileService->addFile(
                        objectEntity: $objectService->find($objectId),
                        fileName: $fileName,
                        content: base64_decode($content)
                    );

                    $tags = array_merge($config['tags'] ?? [], ["object:$objectId"]);
                    if ($file instanceof \OCP\Files\File === true) {
                        // $this->attachTagsToFile(fileId: $file->getId(), tags: $tags);
                    }

                    $result[$key] = $file->getPath();
                } catch (Exception $exception) {
                    // Swallow per-file write errors so the rule can continue with the remaining files.
                }
            }//end foreach

            $dataDot[$config['filePath']] = $result;
        } else {
            $content  = $files;
            $fileName = basename($dataDot[$config['fileNamePath']] ?? $flowTokenDot[$config['fileNamePath']]);

            try {
                // Write file with OpenRegister ObjectService.
                $objectService = $this->containerInterface->get('OCA\OpenRegister\Service\ObjectService');
                $fileService   = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');
                $file          = $fileService->addFile(
                    objectEntity: $objectService->find($objectId),
                    fileName: $fileName,
                    content: base64_decode($content)
                );

                $tags = array_merge($config['tags'] ?? [], ["object:$objectId"]);
                if ($file instanceof File === true) {
                    // $this->attachTagsToFile(fileId: $file->getId(), tags: $tags);
                }

                $dataDot[$config['filePath']] = $file->getPath();
            } catch (Exception $exception) {
                // Swallow single-file write errors so the rule can complete.
            }
        }//end if

        return $dataDot->jsonSerialize();
    }//end processWriteFileRule()

    /**
     * Processes a synchronization rule.
     *
     * @param ObjectEntity $rule      The rule object containing synchronization details.
     * @param array        $data      The data to be synchronized.
     * @param FlowToken    $flowToken The current flow token threaded through the synchronization.
     *
     * @return array The data after synchronization processing.
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-4
     */
    private function processSyncRule(ObjectEntity $rule, array $data, FlowToken $flowToken): array
    {
        $config = $rule->getObject()['configuration'] ?? [];

        // Check if base requirement is in config.
        if (isset($config['synchronization']) === false) {
            return $data;
        }

        if (is_array($config['synchronization']) === true && isset($config['synchronization']['synchronization']) === true) {
            $synchronizationId = $config['synchronization']['synchronization'];
        } else {
            $synchronizationId = $config['synchronization'];
        }

        $this->logger->debug('[EndpointService] processSyncRule ruleId='.$rule->getUuid().' synchronizationId='.$synchronizationId);

        // Fetch the synchronization.
        if (is_numeric($synchronizationId) === true) {
            $synchronization = $this->synchronizationService->getSynchronization(id: (int) $synchronizationId);
        } else {
            $synchronization = $this->synchronizationService->getSynchronization(filters: ['reference' => $synchronizationId]);
        }

        $this->logger->debug(
            '[EndpointService] processSyncRule synchronization fetched id='.$synchronization->getUuid()
            .' name='.$synchronization->getName()
        );

        // Check if the synchronization should be in test mode.
        if (isset($data['body']['isTest']) === true) {
            $test = $data['body']['isTest'];
        } else if (isset($config['isTest']) === true) {
            $test = $config['isTest'];
        } else {
            $test = false;
        }

        // Check if the synchronization should be forced.
        if (isset($data['body']['force']) === true) {
            $force = $data['body']['force'];
        } else if (isset($config['force']) === true) {
            $force = $config['force'];
        } else {
            $force = false;
        }

        $object = null;
        if (isset($data['body']) === true) {
            $object = $data['body'];
        }

        // Set $object to a different variable becuase we might update $object with reference and want to keep what we send to synchronize.
        $sendObject = $object;

        // If we have an objectIdPath, pull the id from the body and fetch the object from the database.
        $fetchedObject = null;

        if (isset($config['synchronization']['preDelay']) === true && is_int($config['synchronization']['preDelay']) === true) {
            sleep($config['synchronization']['preDelay']);
        }

        if (isset($config['synchronization']['objectIdPath']) === true) {
            $dataDot = new Dot($data['body']);

            $id = $dataDot->get($config['synchronization']['objectIdPath']);

            if (filter_var(value: $id, filter: FILTER_VALIDATE_URL) !== false) {
                $idExploded = explode(separator: '/', string: $id);
                $id         = end($idExploded);
            }

            $this->objectService->getOpenRegisters()->clearCurrents();
            $fetchedObject = $this->objectService->getOpenRegisters()->find($id);
            $foData        = $fetchedObject->jsonSerialize();
            $foData['synchronization_trigger'] = true;
            $fetchedObject->setObject($foData);
        }

        // Run synchronization.
        $mutationType = null;
        $sourceConfig = $synchronization->getSourceConfig();
        if (isset($sourceConfig['synchronizationType']) === true && $sourceConfig['synchronizationType'] === 'delete') {
            $mutationType = 'delete';
        }

        $this->logger->debug(
            '[EndpointService] processSyncRule calling synchronize syncId='.$synchronization->getUuid()
            .' isTest='.var_export($test, true)
            .' force='.var_export($force, true)
            .' mutationType='.var_export($mutationType, true)
        );
        $log = $this->synchronizationService->synchronize(
            synchronization: $synchronization,
            isTest: $test,
            force: $force,
            object: $fetchedObject,
            mutationType: $mutationType,
            data: $object,
            flowToken: $flowToken
        );
        $this->logger->debug(
            '[EndpointService] processSyncRule synchronize complete syncId='.$synchronization->getUuid()
        );

        // $object got updated through reference.
        $returnedObject = $object;

        if (isset($config['synchronization']['retainResponse']) === true) {
            $retainResponse = (bool) $config['synchronization']['retainResponse'];
        } else {
            $retainResponse = false;
        }

        if (isset($config['synchronization']['postDelay']) === true && is_int($config['synchronization']['postDelay']) === true) {
            sleep($config['synchronization']['postDelay']);
        }

        if (isset($config['synchronizationConfig']['mergeResultToKey']) === true && $retainResponse === false) {
            // Merge result to root send object.
            if ($config['synchronizationConfig']['mergeResultToKey'] === '#') {
                $data['body'] = array_merge($sendObject, $returnedObject);
                // Merge result to configured key in send object.
            } else {
                $sendObject[$config['synchronizationConfig']['mergeResultToKey']] = $returnedObject;
                $data['body'] = $sendObject;
            }

            // Overwrite body with result.
        } else if (isset($config['synchronizationConfig']['overwriteObjectWithResult']) === true
            && filter_var(
                $config['synchronizationConfig']['overwriteObjectWithResult'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            ) === true
            && $retainResponse === false
        ) {
            $data['body'] = $returnedObject;
        } else if ($retainResponse === false) {
            $data['body'] = $log;
        }//end if

        return $data;
    }//end processSyncRule()

    /**
     * Processes a file part creation rule.
     *
     * @param ObjectEntity $rule     The rule to process.
     * @param array        $data     The created object in array form.
     * @param ObjectEntity $endpoint The endpoint the file part rule is bound to.
     * @param string|null  $objectId The id of the resulting object.
     *
     * @return array The updated object data.
     *
     * @throws ContainerExceptionInterface
     * @throws DoesNotExistException
     * @throws GuzzleException
     * @throws LoaderError
     * @throws MultipleObjectsReturnedException
     * @throws NotFoundExceptionInterface
     * @throws SyntaxError
     * @throws \OCA\OpenRegister\Exception\ValidationException
     * @throws \OCP\Files\InvalidPathException
     * @throws \OCP\Files\NotFoundException
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-4
     */
    private function processFilePartRule(ObjectEntity $rule, array $data, ObjectEntity $endpoint, ?string $objectId=null): array|JSONResponse
    {
        if ($objectId === null) {
            throw new Exception('Filepart rules can only be applied after the object has been created');
        }

        $ruleConfig = $rule->getObject()['configuration'] ?? [];
        if (isset($ruleConfig['fileparts_create']) === false) {
            throw new Exception('No configuration found for fileparts_create');
        }

        $config       = $ruleConfig['fileparts_create'];
        $endpointData = $endpoint->getObject();
        $targetId     = explode('/', $endpointData['targetId'] ?? '');

        $registerId    = $targetId[0];
        $superSchemaId = $targetId[1];

        $sizeLocation     = $config['sizeLocation'];
        $schemaId         = $config['schemaId'];
        $filenameLocation = $config['filenameLocation'] ?? 'filename';
        $filePartLocation = $config['filePartLocation'] ?? 'fileParts';

        $mapping = null;
        if (isset($config['mappingId']) === true) {
            $mapping = $this->mappingService->getMapping($config['mappingId']);
        }

        $openRegister = $this->objectService->getOpenRegisters();
        $openRegister->setRegister($registerId);
        $openRegister->setSchema($superSchemaId);

        $object = $openRegister->find(id: $objectId);
        // $location = $object->getFolder();
        $fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');
        $location    = $fileService->getObjectFolder($object)->getPath();

        $dataDot  = new Dot($data);
        $size     = $dataDot[$sizeLocation];
        $filename = $dataDot[$filenameLocation];

        $fileParts = $this->storageService->createUpload($location, $filename, $size, $objectId);

        $fileParts = array_map(
          function ($filePart) use ($mapping, $registerId, $schemaId, $openRegister) {

            if ($mapping !== null) {
                $formatted = $this->mappingService->executeMapping(mapping: $mapping, input: $filePart);
            } else {
                $formatted = $filePart;
            }

            try {
                $object = $this->objectService->getOpenRegisters()->saveObject(
                    register: $registerId,
                    schema: $schemaId,
                    object: $formatted,
                    uuid: $formatted['id']
                );
                return $this->replaceInternalReferences(mapper: $openRegister, object: $object);
            } catch (ValidationException $exception) {
                return $this->objectService->getOpenRegisters()->handleValidationException($exception);
            }

          },
          $fileParts
          );

        $errors = array_filter(
          $fileParts,
          function ($part) {
            if ($part instanceof JSONResponse) {
                return true;
            }
          }
          );

        if (count($errors) > 0) {
            return array_shift($errors);
        }

        $dataDot[$filePartLocation] = $fileParts;

        $filepartIds = array_map(
                function ($filePart) {
                    return $filePart['id'];
                },
                $fileParts
                );

        $saveObject = clone $dataDot;
        $saveObject[$filePartLocation] = $filepartIds;

        $openRegister->saveObject(register: $registerId, schema: $schemaId, object: $saveObject->jsonSerialize());

        return $dataDot->jsonSerialize();
    }//end processFilePartRule()

    /**
     * Processes the upload of a file part.
     *
     * @param ObjectEntity $rule     The rule to process.
     * @param array        $data     The data from the object in array form.
     * @param IRequest     $request  The current request used to read the uploaded part body.
     * @param string|null  $objectId The id of the object.
     *
     * @return array The updated object data.
     *
     * @throws DoesNotExistException
     * @throws LoaderError
     * @throws MultipleObjectsReturnedException
     * @throws SyntaxError
     * @throws \OCP\Files\InvalidPathException
     * @throws \OCP\Files\NotFoundException
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-4
     */
    private function processFilePartUploadRule(ObjectEntity $rule, array $data, IRequest $request, ?string $objectId=null): array
    {
        $ruleConfig = $rule->getObject()['configuration'] ?? [];
        if (isset($ruleConfig['filepart_upload']) === false) {
            throw new Exception('No configuration found for filepart_upload');
        }

        $config = $ruleConfig['filepart_upload'];

        $mappedData = $data;

        if (isset($config['mappingId']) === true) {
            $mapping    = $this->mappingService->getMapping($config['mappingId']);
            $mappedData = $this->mappingService->executeMapping(mapping: $mapping, input: $mappedData);
        }

        $mappedData['successful'] = $this->storageService->writePart(
            partId: $mappedData['order'],
            partUuid: $mappedData['id'],
            data: $mappedData['data']
        );

        unset($data['data']);

        if (isset($config['mappingOutId']) === true) {
            $mappedData = $this->mappingService->executeMapping(
                mapping: $this->mappingService->getMapping(mappingId: $config['mappingOutId']),
                input: $mappedData
            );
        }

        $object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find($objectId);
        $object->setObject($mappedData);
        $this->objectService->getOpenRegisters()->getMapper('objectEntity')->update($object);

        $data['body'] = $mappedData;

        return $data;
    }//end processFilePartUploadRule()

    /**
     * Processes a JavaScript rule
     *
     * @param ObjectEntity $rule The rule object containing JavaScript execution details
     * @param array        $data The input data to be processed by the JavaScript rule
     *
     * @return array The processed data after executing the JavaScript rule
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-4
     */
    private function processJavaScriptRule(ObjectEntity $rule, array $data): array
    {
        $config = $rule->getObject()['configuration'] ?? [];
        // @todo: Here we need to implement the JavaScript execution logic
        // For now, just return the data unchanged
        return $data;
    }//end processJavaScriptRule()

    /**
     * Downloads a file based upon configuration
     *
     * @param ObjectEntity $rule     The rule to execute.
     * @param array        $data     The data to perform the rule on.
     * @param string       $objectId The id of the requested object.
     *
     * @return Response A response containing the file requested.
     *
     * @throws ContainerExceptionInterface
     * @throws DoesNotExistException
     * @throws NotFoundExceptionInterface
     * @throws \OCP\Files\NotFoundException
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-4
     */
    private function processDownloadRule(ObjectEntity $rule, array $data, string $objectId): Response
    {
        $config = $rule->getObject()['configuration'] ?? [];

        /*
         * @var ObjectEntity $object
         */

        $object = $this->objectService->getOpenRegisters()->getMapper('objectEntity')->find(identifier: $objectId);

        if (isset($data['parameters']['filename']) === true) {
            $filename = $data['parameters']['filename'];
        }

        if (isset($config['filenamePosition']) === true) {
            $dot      = new Dot($object->jsonSerialize());
            $filename = $dot->get($config['filenamePosition']);
        }

        $fileService = $this->containerInterface->get('OCA\OpenRegister\Service\FileService');
        $files       = $fileService->getFiles(object: $object, sharedFilesOnly: false);

        // Try to get filename from object its files (only works when object has 1 file).
        if (isset($filename) === false && count($object->getFiles()) === 1) {
            $filename = $object->getFiles()[0]['title'];
        }

        // Try to get filename from files found with fileservice (only works when object has 1 file).
        if (isset($filename) === false && count($files) === 1) {
            $filename = $files[0]->getName();
        }

        if (isset($filename) === false) {
            throw new Exception('File could not be determined');
        }

        if (isset($data['parameters']['version']) === true) {
            /*
             * @var File $file
             */

             $file = $fileService->getFile(object: $object, file: $filename, version: $data['parameters']['version']);
        } else if (isset($data['parameters']['versie']) === true) {
            /*
             * @var File $file
             *
             * @TODO: This can be nicer by mapping, but let's first get something sure.
             */

             $file = $fileService->getFile(object: $object, file: $filename, version: $data['parameters']['versie']);
        } else {
            /*
             * @var File $file
             */

            $file = $fileService->getFile(object: $object, file: $filename);
        }//end if

        $response = new DataDownloadResponse(data: $file->getContent(), filename: $file->getName(), contentType: $file->getMimeType());

        return $response;
    }//end processDownloadRule()

    /**
     * Checks if rule conditions are met.
     *
     * @param ObjectEntity $rule        The rule object containing conditions to be checked.
     * @param array        $data        The input data against which the conditions are evaluated.
     * @param mixed        $logicResult Reference parameter — receives the raw JSON-logic apply result.
     *
     * @return bool True if conditions are met, false otherwise.
     *
     * @throws Exception
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-1
     */
    private function checkRuleConditions(ObjectEntity $rule, array $data, mixed &$logicResult): bool
    {
        $conditions = $rule->getObject()['conditions'] ?? [];
        if (empty($conditions) === true) {
            return true;
        }

        return ($logicResult = JsonLogic::apply($conditions, $data)) == true;
    }//end checkRuleConditions()

    /**
     * Updates request object with processed rule data.
     *
     * @param FlowToken $flowToken The current flow token to be amended.
     * @param array     $ruleData  The processed rule data to update the request with.
     *
     * @return FlowToken The updated flow token with the amended request.
     *
     * @spec openspec/changes/retrofit-2026-05-25-rule-pipeline/tasks.md#task-1
     */
    private function updateRequestWithRuleData(FlowToken $flowToken, array $ruleData): FlowToken
    {
        $parameters = $ruleData['body']['_parameters'] ?? $flowToken->getRequestAmended()['parameters'];
        $method     = $ruleData['body']['_method'] ?? $flowToken->getRequestAmended()['method'];
        $headers    = $ruleData['body']['_headers'] ?? $flowToken->getRequestAmended()['headers'];

        $requestAmended = $flowToken->getRequestAmended();

        $requestAmended['method']     = $method;
        $requestAmended['parameters'] = $parameters;
        $requestAmended['headers']    = $headers;

        $flowToken->setRequestAmended($requestAmended);

        return $flowToken;
        // Return the overridden request.
    }//end updateRequestWithRuleData()

    /**
     * Parse raw content into structured data based on content type.
     *
     * @param IRequest $request The current request, used to inspect headers and raw body.
     *
     * @return mixed Parsed data (array for JSON/XML) or original string.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-5
     */
    private function parseContent(IRequest $request): mixed
    {
        $contentType = $request->getHeader('Content-Type');

        if (str_contains($contentType, 'multipart/form-data') === true) {
            [$post, $files] = request_parse_body();

            $parsedFiles = array_map(
                    function ($file) {
                        return file_get_contents($file['tmp_name']);

                    },
                    $files
                    );

            return array_merge($post, $parsedFiles);
        }

        $content = $this->getRawContent();

        // Try JSON decode first.
        $json = json_decode($content, true);
        if ($json !== null) {
            return $json;
        }

        // Try XML decode if content type suggests XML or content looks like XML.
        if ($contentType === 'application/xml' || $contentType === 'text/xml'
            || ($contentType === '' && $this->looksLikeXml(content: $content) === true)
        ) {
            libxml_use_internal_errors(true);
            $xml = SafeXmlParser::parse($content);
            libxml_clear_errors();

            if ($xml !== false) {
                return json_decode(json_encode($xml), true);
            }
        }

        // Return original content as fallback.
        return $request->getParams();
    }//end parseContent()

    /**
     * Check if content appears to be XML.
     *
     * @param string $content Content to check.
     *
     * @return bool True if content is valid XML.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-5
     */
    private function looksLikeXml(string $content): bool
    {
        // Suppress XML errors.
        libxml_use_internal_errors(true);

        // Use the safe parser so the XXE loader cannot leak in from SOAPService.
        $result = SafeXmlParser::parse($content) !== false;

        // Clear any XML errors.
        libxml_clear_errors();

        return $result;
    }//end looksLikeXml()
}//end class
