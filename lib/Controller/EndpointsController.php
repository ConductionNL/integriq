<?php

namespace OCA\OpenConnector\Controller;

use Exception;
use OCA\OpenConnector\Http\XMLResponse;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenConnector\Service\EndpointService;
use OCA\OpenConnector\Service\EndpointCacheService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling endpoint related operations
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 */
class EndpointsController extends Controller
{

    /**
     * CORS allowed methods
     *
     * @var string
     */
    private string $corsMethods;

    /**
     * CORS allowed headers
     *
     * @var string
     */
    private string $corsAllowedHeaders;

    /**
     * CORS max age
     *
     * @var int
     */
    private int $corsMaxAge;

    /**
     * Constructor for the EndpointsController
     *
     * @param string               $appName              The name of the app
     * @param IRequest             $request              The request object
     * @param IAppConfig           $config               The app configuration object
     * @param EndpointService      $endpointService      Service for handling endpoint operations
     * @param AuthorizationService $authorizationService Service for handling authorization
     * @param ObjectService        $objectService        Service for direct ObjectService operations
     * @param EndpointCacheService $endpointCacheService Service for cached endpoint lookups
     * @param LoggerInterface      $logger               Service for logging
     * @param IL10N                $l                    The localization service
     */
    public function __construct(
        $appName,
        IRequest $request,
        private IAppConfig $config,
        private EndpointService $endpointService,
        private AuthorizationService $authorizationService,
        private ObjectService $objectService,
        private EndpointCacheService $endpointCacheService,
        private LoggerInterface $logger,
        private IL10N $l,
        $corsMethods='PUT, POST, GET, DELETE, PATCH',
        $corsAllowedHeaders='Authorization, Content-Type, Accept',
        $corsMaxAge=1728000
    ) {
        parent::__construct($appName, $request);
        $this->corsMethods        = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge         = $corsMaxAge;
    }//end __construct()

    /**
     * Returns the template of the main app's page
     *
     * This method renders the main page of the application, adding any necessary data to the template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The rendered template response
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            'openconnector',
            'index',
            []
        );
    }//end page()

    /**
     * Handles generic path requests by matching against registered endpoints
     *
     * This method checks if the current path matches any registered endpoint patterns
     * and forwards the request to the appropriate endpoint service if found
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @param  string $_path
     * @return JSONResponse|XMLResponse The response from the endpoint service or 404 if no match
     * @throws Exception
     */
    public function handlePath(string $_path): Response
    {
        try {
            // Find matching endpoint for the given path and method (using cache)
            $endpoint = $this->endpointCacheService->findByPathRegex(
                path: $_path,
                method: $this->request->getMethod()
            );

            // If no matching endpoint found, return 404
            if ($endpoint === null) {
                return new JSONResponse(
                    data: ['error' => $this->l->t('No matching endpoint found for path and method: %1$s %2$s', [$_path, $this->request->getMethod()])],
                    statusCode: 404
                );
            }
        } catch (\Exception $e) {
            // Multiple endpoints found (handled by cache service)
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 409
            );
        }//end try

        // OPTIMIZATION: For simple endpoints with no rules/conditions/mappings, bypass EndpointService
        $response = $this->isSimpleEndpoint($endpoint) ? $this->handleSimpleSchemaRequest($endpoint, $_path) : $this->endpointService->handleRequest($endpoint, $this->request, $_path);

        // Check if the Accept header is set to XML
        $acceptHeader = $this->request->getHeader('Accept');
        if (stripos($acceptHeader, 'application/xml') !== false && $response instanceof JSONResponse === true) {
            // Convert JSON response to XML response
            $response = new XMLResponse($response->getData(), $response->getStatus(), $response->getHeaders(), $_path);
        }

        return $this->authorizationService->corsAfterController($this->request, $response);
    }//end handlePath()

    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     * @since           7.0.0
     *
     * @return Response The CORS response
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function preflightedCors(): Response
    {
        // Determine the origin
        $origin = $this->request->server['HTTP_ORIGIN'] ?? '*';

        // Create and configure the response
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;
    }//end preflightedCors()

    /**
     * Retrieves endpoint logs with filtering and pagination support
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the filtered endpoint logs and pagination
     */
    public function logs(SearchService $searchService): JSONResponse
    {
        return new JSONResponse(['error' => $this->l->t('Failed to retrieve logs: Endpoint logging is not available at this time')], 500);
    }//end logs()

    /**
     * Check if an endpoint is simple (no rules, conditions, mappings, configurations)
     *
     * @param  ObjectEntity $endpoint The endpoint to check
     * @return bool True if the endpoint is simple and can be optimized
     */
    private function isSimpleEndpoint(ObjectEntity $endpoint): bool
    {
        $data = $endpoint->getObject();
        // Check if endpoint has no complex processing requirements
        $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        return empty($data['rules']) &&
               empty($data['conditions']) &&
               empty($data['inputMapping']) &&
               empty($data['outputMapping']) &&
               empty($data['configurations']) &&
               ($data['targetType'] ?? '') === 'register/schema' &&
               in_array($this->request->getMethod(), $allowedMethods);
    }//end isSimpleEndpoint()

    /**
     * Handle simple schema requests directly without EndpointService overhead
     *
     * @param  ObjectEntity $endpoint The endpoint configuration
     * @param  string       $path     The request path
     * @return JSONResponse The direct response from ObjectService
     */
    private function handleSimpleSchemaRequest(ObjectEntity $endpoint, string $path): JSONResponse
    {
        try {
            $endpointData = $endpoint->getObject();
            // Parse target register and schema from targetId (e.g., "20/111")
            $targetId = $endpointData['targetId'] ?? '';
            if (empty($targetId)) {
                $this->logger->error('Simple endpoint has empty targetId', ['endpoint' => $endpointData['endpoint'] ?? '']);
                return new JSONResponse(['error' => $this->l->t('Endpoint misconfigured: empty targetId')], 500);
            }

            $target = explode('/', $targetId);
            if (count($target) !== 2 || !is_numeric($target[0]) || !is_numeric($target[1])) {
                $this->logger->error(
                'Simple endpoint has invalid targetId format',
                [
                    'endpoint' => $endpointData['endpoint'] ?? '',
                    'targetId' => $targetId,
                    'parsed'   => $target,
                ]
                );
                return new JSONResponse(['error' => $this->l->t('Endpoint misconfigured: invalid targetId format. Expected "register/schema"')], 500);
            }

            $register = (int) $target[0];
            $schema   = (int) $target[1];

            // Get path parameters and request data
            $endpointArray = $endpointData['endpointArray'] ?? explode('/', $endpointData['endpoint'] ?? '');
            $pathParams    = $this->getPathParameters($endpointArray, $path);
            $parameters    = $this->request->getParams();
            $method        = $this->request->getMethod();

            // Get the ObjectService mapper for this register/schema
            try {
                $mapper = $this->objectService->getMapper(schema: $schema, register: $register);
            } catch (\Exception $e) {
                $this->logger->error(
                'Failed to get ObjectService mapper',
                [
                    'endpoint' => $endpointData['endpoint'] ?? '',
                    'register' => $register,
                    'schema'   => $schema,
                    'error'    => $e->getMessage(),
                ]
                );
                return new JSONResponse(['error' => $this->l->t('Schema or register not found: %s', [$e->getMessage()])], 404);
            }

            // Handle different HTTP methods
            switch ($method) {
                case 'GET':
                    // Handle single object request (has ID in path)
                    if (isset($pathParams['id']) && $pathParams['id'] === end($pathParams)) {
                        $object = $mapper->find($pathParams['id']);
                        return new JSONResponse($object->jsonSerialize());
                    }

                    // Remove _path as parameters (not needed and breaks things)
                    unset($parameters['_path']);

                    // Handle collection request (list objects)
                    $result = $mapper->findAllPaginated(requestParams: $parameters);

                    // Debug: log the register and schema we're querying
                    $this->logger->info(
                    'Simple endpoint query',
                    [
                        'endpoint'     => $endpointData['endpoint'] ?? '',
                        'register'     => $register,
                        'schema'       => $schema,
                        'targetId'     => $targetId,
                        'parameters'   => $parameters,
                        'result_total' => $result['total'] ?? 0,
                    ]
                    );

                    // Use the existing structure with minimal changes: serialize objects and rename 'total' to 'count'
                    $returnArray            = $result;
                    $returnArray['count']   = $result['total'];
                    $returnArray['results'] = array_map(fn($obj) => $obj->jsonSerialize(), $result['results']);
                    unset($returnArray['total']); // Remove 'total' since we renamed it to 'count'

                    // Add pagination links if needed
                    if ($result['page'] < $result['pages']) {
                        $parameters['page']  = $result['page'] + 1;
                        $returnArray['next'] = $this->buildPaginationUrl($parameters, $path);
                    }

                    if ($result['page'] > 1) {
                        $parameters['page']      = $result['page'] - 1;
                        $returnArray['previous'] = $this->buildPaginationUrl($parameters, $path);
                    }
                    return new JSONResponse($returnArray);

                case 'POST':
                    // Create new object
                    $object = $mapper->createFromArray(object: $parameters);
                    return new JSONResponse($object->jsonSerialize(), 201);

                case 'PUT':
                    // Full update of existing object
                    if (!isset($pathParams['id'])) {
                        return new JSONResponse(['error' => $this->l->t('ID required for PUT request')], 400);
                    }

                    $object = $mapper->updateFromArray($pathParams['id'], $parameters, true, false);
                    return new JSONResponse($object->jsonSerialize());

                case 'PATCH':
                    // Partial update of existing object
                    if (!isset($pathParams['id'])) {
                        return new JSONResponse(['error' => $this->l->t('ID required for PATCH request')], 400);
                    }

                    $object = $mapper->updateFromArray($pathParams['id'], $parameters, true, true);
                    return new JSONResponse($object->jsonSerialize());

                case 'DELETE':
                    // Delete object
                    if (!isset($pathParams['id'])) {
                        return new JSONResponse(['error' => $this->l->t('ID required for DELETE request')], 400);
                    }

                    $success = $mapper->delete(['id' => $pathParams['id']]);
                    if (!$success) {
                        return new JSONResponse(['error' => $this->l->t('Failed to delete object')], 500);
                    }
                    return new JSONResponse([], 204);

                default:
                    return new JSONResponse(['error' => $this->l->t('Method not supported')], 405);
            }//end switch
        } catch (Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Simple endpoint error: %s', [$e->getMessage()])], 500);
        }//end try
    }//end handleSimpleSchemaRequest()

    /**
     * Parse path parameters from endpoint pattern and actual path
     *
     * @param  array  $endpointArray The endpoint pattern array
     * @param  string $path          The actual request path
     * @return array The parsed parameters
     */
    private function getPathParameters(array $endpointArray, string $path): array
    {
        $pathParts = explode('/', $path);
        $params    = [];

        foreach ($endpointArray as $index => $pattern) {
            if (str_starts_with($pattern, '{{') && str_ends_with($pattern, '}}')) {
                $key = trim($pattern, '{}');
                if (isset($pathParts[$index])) {
                    $params[$key] = $pathParts[$index];
                }
            }
        }

        return $params;
    }//end getPathParameters()

    /**
     * Build pagination URL for simple endpoints
     *
     * @param  array  $parameters Query parameters
     * @param  string $path       The request path
     * @return string The pagination URL
     */
    private function buildPaginationUrl(array $parameters, string $path): string
    {
        $baseUrl = $this->request->getServerProtocol().'://'.$this->request->getServerHost().'/apps/openconnector/api/endpoint/'.$path;

        return $baseUrl.'?'.http_build_query($parameters);
    }//end buildPaginationUrl()
}//end class
