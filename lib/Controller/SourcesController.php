<?php

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Db\Source;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Db\CallLogMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class SourcesController extends Controller
{
    /**
     * Constructor for the SourcesController
     *
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration object
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly SourceMapper $sourceMapper,
        private readonly CallLogMapper $callLogMapper,
        private readonly IL10N $l
    )
    {
        parent::__construct($appName, $request);
    }

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
    }

    /**
     * Retrieves a list of all sources
     *
     * This method returns a JSON response containing an array of all sources in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the list of sources
     */
    public function index(ObjectService $objectService, SearchService $searchService): JSONResponse
    {
        $filters = $this->request->getParams();
        $fieldsToSearch = ['name', 'description'];

        $searchParams = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(filters: $filters, fieldsToSearch:  $fieldsToSearch);
        $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

        return new JSONResponse(['results' => $this->sourceMapper->findAll(limit: null, offset: null, filters: $filters, searchConditions: $searchConditions, searchParams: $searchParams)]);
    }

    /**
     * Retrieves a single source by its ID
     *
     * This method returns a JSON response containing the details of a specific source.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the source to retrieve
     * @return JSONResponse A JSON response containing the source details
     */
    public function show(string $id): JSONResponse
    {
        try {
            return new JSONResponse($this->sourceMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }
    }

    /**
     * Creates a new source
     *
     * This method creates a new source based on POST data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the created source
     */
    public function create(): JSONResponse
    {
        $data = $this->request->getParams();

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }

        if (isset($data['id'])) {
            unset($data['id']);
        }

        return new JSONResponse($this->sourceMapper->createFromArray(object: $data));
    }

    /**
     * Updates an existing source
     *
     * This method updates an existing source based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the source to update
     * @return JSONResponse A JSON response containing the updated source details
     */
    public function update(int $id): JSONResponse
    {
        $data = $this->request->getParams();

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($data[$key]);
            }
        }
        if (isset($data['id'])) {
            unset($data['id']);
        }
        return new JSONResponse($this->sourceMapper->updateFromArray(id: (int) $id, object: $data));
    }

    /**
     * Deletes a source
     *
     * This method deletes a source based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the source to delete
     * @return JSONResponse An empty JSON response
     */
    public function destroy(int $id): JSONResponse
    {
        $this->sourceMapper->delete($this->sourceMapper->find((int) $id));

        return new JSONResponse([]);
    }

    /**
     * Retrieves call logs with filtering and pagination support
     *
     * This method returns call logs based on query parameters,
     * with support for various filtering parameters to narrow down the results.
     *
     * Query Parameters:
     * - source_id: Filter logs by source ID
     * - date_from: Filter logs created after this date
     * - date_to: Filter logs created before this date
     * - endpoint: Filter logs by endpoint (partial match)
     * - status_code: Filter logs by status code range (comma-separated min,max)
     * - slow_requests: Filter logs with response time > 5000ms
     * - limit: Number of results per page (default: 20)
     * - offset: Offset for pagination (default: 0)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the filtered call logs and pagination
     */
    public function logs(SearchService $searchService): JSONResponse
    {
        try {
            // Get filters from request
            $filters = $this->request->getParams();
            $specialFilters = [];

            // Pagination using _page and _limit
            $limit = isset($filters['_limit']) ? (int)$filters['_limit'] : 20;
            $page = isset($filters['_page']) ? (int)$filters['_page'] : 1;
            $offset = ($page - 1) * $limit;
            unset($filters['_limit'], $filters['_page']);

            // Handle special filters
            if (empty($filters['date_from']) === false) {
                $specialFilters['date_from'] = $filters['date_from'];
                unset($filters['date_from']);
            }
            if (empty($filters['date_to']) === false) {
                $specialFilters['date_to'] = $filters['date_to'];
                unset($filters['date_to']);
            }
            if (empty($filters['endpoint']) === false) {
                $specialFilters['endpoint_like'] = '%' . $filters['endpoint'] . '%';
                unset($filters['endpoint']);
            }
            if (empty($filters['status_code']) === false) {
                $statusCodes = explode(',', $filters['status_code']);
                if (count($statusCodes) === 2) {
                    $specialFilters['status_code_range'] = $statusCodes;
                }
                unset($filters['status_code']);
            }
            if (empty($filters['slow_requests']) === false) {
                $specialFilters['slow_requests'] = 5000; // 5 seconds in milliseconds
                unset($filters['slow_requests']);
            }

            // Build search conditions and parameters
            $searchConditions = [];
            $searchParams = [];

            if (empty($specialFilters['date_from']) === false) {
                $searchConditions[] = "created >= ?";
                $searchParams[] = $specialFilters['date_from'];
            }

            if (empty($specialFilters['date_to']) === false) {
                $searchConditions[] = "created <= ?";
                $searchParams[] = $specialFilters['date_to'];
            }

            if (empty($specialFilters['endpoint_like']) === false) {
                $searchConditions[] = "endpoint LIKE ?";
                $searchParams[] = $specialFilters['endpoint_like'];
            }

            if (empty($specialFilters['status_code_range']) === false) {
                $searchConditions[] = "status_code >= ? AND status_code <= ?";
                $searchParams = array_merge($searchParams, $specialFilters['status_code_range']);
            }

            if (empty($specialFilters['slow_requests']) === false) {
                $searchConditions[] = "JSON_EXTRACT(response, '$.responseTime') > ?";
                $searchParams[] = $specialFilters['slow_requests'];
            }

            $sortFields = [];
            if (empty($filters['_sort']) === false && is_array($filters['_sort']) === true) {
                // Have some control of what to be sortable
                $allowedSortFields = ['created', 'status_code', 'endpoint']; 
                foreach ($filters['_sort'] as $field => $direction) {
                    if (in_array($field, $allowedSortFields, true) === true) {
                        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                        $sortFields[$field] = $dir;
                    }
                }
                unset($filters['_sort']);
            }


            // Remove special query params from filters
            $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

            // Get call logs with filters and pagination
            $callLogs = $this->callLogMapper->findAll(
                limit: $limit,
                offset: $offset,
                filters: $filters,
                searchConditions: $searchConditions,
                searchParams: $searchParams,
                sortFields: $sortFields
            );

            // Get total count for pagination
            $total = $this->callLogMapper->getTotalCount($filters);
            $pages = $limit > 0 ? ceil($total / $limit) : 1;
            $currentPage = $limit > 0 ? floor($offset / $limit) + 1 : 1;

            // Return flattened paginated response
            return new JSONResponse([
                'results' => $callLogs,
                'page' => $currentPage,
                'pages' => $pages,
                'results_count' => count($callLogs),
                'total' => $total
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Failed to retrieve logs: %s', [$e->getMessage()])], 500);
        }
    }

    /**
     * Test a source
     *
     * This method fires a test call to the source and returns the response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * Endpoint: /api/source-test/{id}
     * Properties:
     *   query: (expected key-value array)
     *   headers: (expected key-value array)
     *   method: (string, one of POST, GET, PUT, DELETE) -> defaults to POST
     *   endpoint: (string) can be empty
     *   type: (string, one of: json, xml, yaml)
     *   body: (string)
     *
     * @param int $id The ID of the source to test
     * @return JSONResponse A JSON response containing the test results
     */
    public function test(CallService $callService,int $id): JSONResponse
    {
        // get the source
        try {
            $source = $this->sourceMapper->find(id: (int) $id);
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }

        // Get the request data
        $requestData = $this->request->getParams();

        // Build Guzzle call configuration array
        $config = [];

        // Add headers if present
        if (isset($requestData['headers']) && is_array($requestData['headers'])) {
            $config['headers'] = $requestData['headers'];
        }

        // Add query parameters if present
        if (isset($requestData['query']) && is_array($requestData['query'])) {
            $config['query'] = $requestData['query'];
        }

        // Set method, default to POST if not provided
        $method = $requestData['method'] ?? 'GET';

        // Set endpoint
        $endpoint = $requestData['endpoint'] ?? '';

        // Set body if present
        if (isset($requestData['body'])) {
            $config['body'] = $requestData['body'];
        }

        // Set content type based on the type parameter
        if (isset($requestData['type'])) {
            switch ($requestData['type']) {
                case 'json':
                    $config['headers']['Content-Type'] = 'application/json';
                    break;
                case 'xml':
                    $config['headers']['Content-Type'] = 'application/xml';
                    break;
                case 'yaml':
                    $config['headers']['Content-Type'] = 'application/x-yaml';
                    break;
            }
        }

        // fire the call

        $time_start = microtime(true);
        $callLog = $callService->call($source, $endpoint, $method, $config);
        $time_end = microtime(true);

        return new JSONResponse($callLog->jsonSerialize());
    }
}
