<?php
/**
 * OpenConnector EndpointsController.
 *
 * Controller for handling endpoint related operations: page render, generic
 * path matching (handlePath), CORS preflight, logs and the simple-endpoint
 * optimisation path.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-1
 */

namespace OCA\OpenConnector\Controller;

use Exception;
use OCA\OpenConnector\Http\XMLResponse;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\EndpointCacheService;
use OCA\OpenConnector\Service\EndpointService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\AppInfo\Application;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling endpoint related operations.
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
     * CORS allowed methods.
     *
     * @var string
     */
    private string $corsMethods;

    /**
     * CORS allowed headers.
     *
     * @var string
     */
    private string $corsAllowedHeaders;

    /**
     * CORS max age.
     *
     * @var integer
     */
    private int $corsMaxAge;

    /**
     * Constructor for the EndpointsController.
     *
     * @param string               $appName              The name of the app.
     * @param IRequest             $request              The request object.
     * @param EndpointService      $endpointService      Service for handling endpoint operations.
     * @param AuthorizationService $authorizationService Service for handling authorization.
     * @param ObjectService        $objectService        Service for direct ObjectService operations.
     * @param EndpointCacheService $endpointCacheService Service for cached endpoint lookups.
     * @param LoggerInterface      $logger               Service for logging.
     * @param IL10N                $l                    The localization service.
     * @param string               $corsMethods          Allowed CORS methods.
     * @param string               $corsAllowedHeaders   Allowed CORS headers.
     * @param integer              $corsMaxAge           CORS max age in seconds.
     */
    public function __construct(
        $appName,
        IRequest $request,
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
        parent::__construct(appName: $appName, request: $request);
        $this->corsMethods        = $corsMethods;
        $this->corsAllowedHeaders = $corsAllowedHeaders;
        $this->corsMaxAge         = $corsMaxAge;

    }//end __construct()

    /**
     * Handles generic path requests by matching against registered endpoints.
     *
     * This method checks if the current path matches any registered endpoint patterns
     * and forwards the request to the appropriate endpoint service if found.
     *
     * @param string $_path The path component appended after /api/endpoint/.
     *
     * @return JSONResponse|XMLResponse|Response The response from the endpoint service or 404 if no match.
     *
     * @throws Exception On underlying service failure.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-1
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function handlePath(string $_path): Response
    {
        try {
            // Find matching endpoint for the given path and method (using cache).
            $endpoint = $this->endpointCacheService->findByPathRegex(
                path: $_path,
                method: $this->request->getMethod()
            );

            // If no matching endpoint found, return 404.
            if ($endpoint === null) {
                return new JSONResponse(
                    data: [
                        'error' => $this->l->t(
                            'No matching endpoint found for path and method: %1$s %2$s',
                            [$_path, $this->request->getMethod()]
                        ),
                    ],
                    statusCode: 404
                );
            }
        } catch (\Exception $e) {
            // Multiple endpoints found (handled by cache service).
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 409
            );
        }//end try

        // OPTIMIZATION: For simple endpoints with no rules/conditions/mappings, bypass EndpointService.
        if ($this->isSimpleEndpoint(endpoint: $endpoint) === true) {
            $response = $this->handleSimpleSchemaRequest(endpoint: $endpoint, path: $_path);
        } else {
            $response = $this->endpointService->handleRequest($endpoint, $this->request, $_path);
        }

        // Check if the Accept header is set to XML.
        $acceptHeader = $this->request->getHeader('Accept');
        if (stripos($acceptHeader, 'application/xml') !== false && $response instanceof JSONResponse === true) {
            // Convert JSON response to XML response.
            $response = new XMLResponse(
                data: $response->getData(),
                status: $response->getStatus(),
                headers: $response->getHeaders(),
                path: $_path
            );
        }

        return $this->authorizationService->corsAfterController($this->request, $response);

    }//end handlePath()

    /**
     * Implements a preflighted CORS response for OPTIONS requests.
     *
     * @return Response The CORS response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     * @PublicPage
     *
     * @since 7.0.0
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-1
     */
    #[NoCSRFRequired]
    #[PublicPage]
    public function preflightedCors(): Response
    {
        // Determine the origin.
        $origin = ($this->request->server['HTTP_ORIGIN'] ?? '*');

        // Create and configure the response.
        $response = new Response();
        $response->addHeader('Access-Control-Allow-Origin', $origin);
        $response->addHeader('Access-Control-Allow-Methods', $this->corsMethods);
        $response->addHeader('Access-Control-Max-Age', (string) $this->corsMaxAge);
        $response->addHeader('Access-Control-Allow-Headers', $this->corsAllowedHeaders);
        $response->addHeader('Access-Control-Allow-Credentials', 'false');

        return $response;

    }//end preflightedCors()

    /**
     * Retrieves endpoint logs with filtering and pagination support.
     *
     * @param SearchService $searchService Search service injected by route resolution.
     *
     * @return JSONResponse A JSON response containing the filtered endpoint logs and pagination.
     *
     * Admin-only: gated at the middleware layer via #[AuthorizedAdminSetting].
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-1
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function logs(SearchService $searchService): JSONResponse
    {
        // Endpoint logging is not yet wired to the OR call_log schema.
        // Returning an empty paginated result (instead of 500) lets clients
        // poll without breaking; once the migration lands this returns a
        // real result set.
        return new JSONResponse(
                [
                    'results' => [],
                    'total'   => 0,
                    'page'    => 1,
                    'pages'   => 1,
                    'limit'   => (int) $this->request->getParam('_limit', 20),
                    'offset'  => 0,
                ]
                );

    }//end logs()

    /**
     * Check if an endpoint is simple (no rules, conditions, mappings, configurations).
     *
     * An endpoint must also carry `isPublic: true` to be eligible for the simple
     * path — without an explicit opt-in the request is routed through the full
     * EndpointService which runs the authentication-rule pipeline.  Only GET
     * requests are allowed on the simple path; write methods (POST/PUT/PATCH/
     * DELETE) are always routed through EndpointService so that OR's write RBAC
     * layer (OR #1949) is respected.
     *
     * @param ObjectEntity $endpoint The endpoint to check.
     *
     * @return boolean True if the endpoint qualifies for the optimised simple path.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-2
     */
    private function isSimpleEndpoint(ObjectEntity $endpoint): bool
    {
        $data = $endpoint->getObject();

        // Only GET is allowed on the simple path — writes must go through the
        // full EndpointService so OR's write RBAC (OR #1949/#1951) is honoured.
        if ($this->request->getMethod() !== 'GET') {
            return false;
        }

        // The endpoint must explicitly declare isPublic:true to opt in to
        // anonymous access via the simple path.
        if (($data['isPublic'] ?? false) !== true) {
            return false;
        }

        // Check if endpoint has no complex processing requirements.
        return empty($data['rules']) === true
            && empty($data['conditions']) === true
            && empty($data['inputMapping']) === true
            && empty($data['outputMapping']) === true
            && empty($data['configurations']) === true
            && ($data['targetType'] ?? '') === 'register/schema';

    }//end isSimpleEndpoint()

    /**
     * Handle simple GET-only schema requests directly without EndpointService overhead.
     *
     * Only reachable when isSimpleEndpoint() returns true, which requires:
     *   - HTTP method = GET
     *   - endpoint.isPublic = true
     *   - no rules / conditions / mappings / configurations
     * Write methods (POST/PUT/PATCH/DELETE) are always routed through the full
     * EndpointService so OR's ObjectService write RBAC (OR #1949/#1951) is
     * honoured end-to-end.
     *
     * @param ObjectEntity $endpoint The endpoint configuration.
     * @param string       $path     The request path.
     *
     * @return JSONResponse The direct response from ObjectService.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-2
     */
    private function handleSimpleSchemaRequest(ObjectEntity $endpoint, string $path): JSONResponse
    {
        try {
            $endpointData = $endpoint->getObject();
            // Parse target register and schema from targetId (e.g., "20/111").
            $targetId = ($endpointData['targetId'] ?? '');
            if (empty($targetId) === true) {
                $this->logger->error('Simple endpoint has empty targetId', ['endpoint' => $endpointData['endpoint'] ?? '']);
                return new JSONResponse(['error' => $this->l->t('Endpoint misconfigured: empty targetId')], 500);
            }

            $target = explode('/', $targetId);
            if (count($target) !== 2 || is_numeric($target[0]) === false || is_numeric($target[1]) === false) {
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

            // Get path parameters and request data.
            $endpointArray = ($endpointData['endpointArray'] ?? explode('/', $endpointData['endpoint'] ?? ''));
            $pathParams    = $this->getPathParameters(endpointArray: $endpointArray, path: $path);
            $parameters    = $this->request->getParams();

            // Get the ObjectService mapper for this register/schema.
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

            // Only GET is handled here.  isSimpleEndpoint() guarantees this but
            // we guard defensively so a future refactor cannot silently open write
            // access without going through OR's RBAC layer.
            if ($this->request->getMethod() !== 'GET') {
                return new JSONResponse(['error' => $this->l->t('Method not supported on simple endpoint')], 405);
            }

            // Handle single object request (has ID in path).
            if (isset($pathParams['id']) === true && $pathParams['id'] === end($pathParams)) {
                $object = $mapper->find($pathParams['id']);
                return new JSONResponse($object->jsonSerialize());
            }

            // Remove _path as parameters (not needed and breaks things).
            unset($parameters['_path']);

            // Handle collection request (list objects).
            $result = $mapper->findAllPaginated(requestParams: $parameters);

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

            // Serialize objects and rename 'total' to 'count'.
            $returnArray            = $result;
            $returnArray['count']   = $result['total'];
            $returnArray['results'] = array_map(fn($obj) => $obj->jsonSerialize(), $result['results']);
            unset($returnArray['total']);

            // Add pagination links if needed.
            if ($result['page'] < $result['pages']) {
                $parameters['page']  = ($result['page'] + 1);
                $returnArray['next'] = $this->buildPaginationUrl(parameters: $parameters, path: $path);
            }

            if ($result['page'] > 1) {
                $parameters['page']      = ($result['page'] - 1);
                $returnArray['previous'] = $this->buildPaginationUrl(parameters: $parameters, path: $path);
            }

            return new JSONResponse($returnArray);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Simple endpoint error: %s', [$e->getMessage()])], 500);
        }//end try

    }//end handleSimpleSchemaRequest()

    /**
     * Parse path parameters from endpoint pattern and actual path.
     *
     * @param array  $endpointArray The endpoint pattern array.
     * @param string $path          The actual request path.
     *
     * @return array The parsed parameters.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-1
     */
    private function getPathParameters(array $endpointArray, string $path): array
    {
        $pathParts = explode('/', $path);
        $params    = [];

        foreach ($endpointArray as $index => $pattern) {
            if (str_starts_with($pattern, '{{') === true && str_ends_with($pattern, '}}') === true) {
                $key = trim($pattern, '{}');
                if (isset($pathParts[$index]) === true) {
                    $params[$key] = $pathParts[$index];
                }
            }
        }

        return $params;

    }//end getPathParameters()

    /**
     * Build pagination URL for simple endpoints.
     *
     * @param array  $parameters Query parameters.
     * @param string $path       The request path.
     *
     * @return string The pagination URL.
     *
     * @spec openspec/changes/retrofit-2026-05-25-endpoint-runtime/tasks.md#task-1
     */
    private function buildPaginationUrl(array $parameters, string $path): string
    {
        $baseUrl = ($this->request->getServerProtocol().'://'.$this->request->getServerHost().'/apps/openconnector/api/endpoint/'.$path);

        return ($baseUrl.'?'.http_build_query($parameters));

    }//end buildPaginationUrl()
}//end class
