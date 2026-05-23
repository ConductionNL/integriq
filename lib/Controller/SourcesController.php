<?php
/**
 * OpenConnector sources controller.
 *
 * Controller for source listing pages and source-test/call-log endpoints.
 * Surfaces filtered call logs and runs ad-hoc test calls against a source.
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
 */

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Controller for source listing pages and source-test/call-log endpoints.
 *
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
     * Constructor for the SourcesController.
     *
     * @param string          $appName         The name of the app.
     * @param IRequest        $request         The request object.
     * @param IAppConfig      $config          The app configuration object.
     * @param OrObjectService $orObjectService The OR object service.
     * @param IL10N           $l               The localization service.
     *
     * @return void
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IAppConfig $config,
        private readonly OrObjectService $orObjectService,
        private readonly IL10N $l
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Returns the template of the main app's page.
     *
     * This method renders the main page of the application, adding any necessary data to the template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The rendered template response.
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
     * Retrieves call logs with filtering and pagination support.
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
     * @param SearchService $searchService Service used to strip special query parameters.
     *
     * @return JSONResponse A JSON response containing the filtered call logs and pagination.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function logs(SearchService $searchService): JSONResponse
    {
        try {
            // Get filters from request.
            $filters        = $this->request->getParams();
            $specialFilters = [];

            // Pagination using _page and _limit.
            if (isset($filters['_limit']) === true) {
                $limit = (int) $filters['_limit'];
            } else {
                $limit = 20;
            }

            if (isset($filters['_page']) === true) {
                $page = (int) $filters['_page'];
            } else {
                $page = 1;
            }

            $offset = (($page - 1) * $limit);
            unset($filters['_limit'], $filters['_page']);

            // Handle special filters.
            if (empty($filters['date_from']) === false) {
                $specialFilters['date_from'] = $filters['date_from'];
                unset($filters['date_from']);
            }

            if (empty($filters['date_to']) === false) {
                $specialFilters['date_to'] = $filters['date_to'];
                unset($filters['date_to']);
            }

            if (empty($filters['endpoint']) === false) {
                $specialFilters['endpoint_like'] = '%'.$filters['endpoint'].'%';
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
                $specialFilters['slow_requests'] = 5000;
                // 5 seconds in milliseconds.
                unset($filters['slow_requests']);
            }

            // Build search conditions and parameters.
            $searchConditions = [];
            $searchParams     = [];

            if (empty($specialFilters['date_from']) === false) {
                $searchConditions[] = "created >= ?";
                $searchParams[]     = $specialFilters['date_from'];
            }

            if (empty($specialFilters['date_to']) === false) {
                $searchConditions[] = "created <= ?";
                $searchParams[]     = $specialFilters['date_to'];
            }

            if (empty($specialFilters['endpoint_like']) === false) {
                $searchConditions[] = "endpoint LIKE ?";
                $searchParams[]     = $specialFilters['endpoint_like'];
            }

            if (empty($specialFilters['status_code_range']) === false) {
                $searchConditions[] = "status_code >= ? AND status_code <= ?";
                $searchParams       = array_merge($searchParams, $specialFilters['status_code_range']);
            }

            if (empty($specialFilters['slow_requests']) === false) {
                $searchConditions[] = "JSON_EXTRACT(response, '$.responseTime') > ?";
                $searchParams[]     = $specialFilters['slow_requests'];
            }

            $sortFields = [];
            if (empty($filters['_sort']) === false && is_array($filters['_sort']) === true) {
                // Have some control of what to be sortable.
                $allowedSortFields = ['created', 'status_code', 'endpoint'];
                foreach ($filters['_sort'] as $field => $direction) {
                    if (in_array($field, $allowedSortFields, true) === true) {
                        if (strtoupper($direction) === 'DESC') {
                            $dir = 'DESC';
                        } else {
                            $dir = 'ASC';
                        }

                        $sortFields[$field] = $dir;
                    }
                }

                unset($filters['_sort']);
            }

            // Remove special query params from filters.
            $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

            // Get call logs with filters and pagination via OR ObjectService.
            $orFilters = array_merge(['register' => 'openconnector', 'schema' => 'call_log'], $filters);
            $matches   = $this->orObjectService->findAll(
                    config: [
                        'filters' => $orFilters,
                        'limit'   => $limit,
                        'offset'  => $offset,
                    ]
                    );
            $callLogs  = ($matches['results'] ?? $matches);
            $total     = ($matches['total'] ?? count($callLogs));

            if ($limit > 0) {
                $pages = ceil($total / $limit);
            } else {
                $pages = 1;
            }

            if ($limit > 0) {
                $currentPage = (floor($offset / $limit) + 1);
            } else {
                $currentPage = 1;
            }

            // Return flattened paginated response.
            return new JSONResponse(
                    [
                        'results'       => $callLogs,
                        'page'          => $currentPage,
                        'pages'         => $pages,
                        'results_count' => count($callLogs),
                        'total'         => $total,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Failed to retrieve logs: %s', [$e->getMessage()])], 500);
        }//end try
    }//end logs()

    /**
     * Test a source.
     *
     * This method fires a test call to the source and returns the response.
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
     * @param CallService $callService The CallService used to dispatch the outbound test call.
     * @param string      $id          The UUID of the source to test (post chain-B/C: OR IDs are UUIDs, not ints).
     *
     * @return JSONResponse A JSON response containing the test results.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function test(CallService $callService, string $id): JSONResponse
    {
        // ObjectService::find() throws DoesNotExistException on a missing
        // UUID — catch it so the response is a clean 404 instead of 500.
        try {
            $source = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'source');
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }

        // Get the request data.
        $requestData = $this->request->getParams();

        // Build Guzzle call configuration array.
        $config = [];

        // Add headers if present.
        if (isset($requestData['headers']) === true && is_array($requestData['headers']) === true) {
            $config['headers'] = $requestData['headers'];
        }

        // Add query parameters if present.
        if (isset($requestData['query']) === true && is_array($requestData['query']) === true) {
            $config['query'] = $requestData['query'];
        }

        // Set method, default to POST if not provided.
        $method = ($requestData['method'] ?? 'GET');

        // Set endpoint.
        $endpoint = ($requestData['endpoint'] ?? '');

        // Set body if present.
        if (isset($requestData['body']) === true) {
            $config['body'] = $requestData['body'];
        }

        // Set content type based on the type parameter.
        if (isset($requestData['type']) === true) {
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

        // Fire the call.
        $timeStart = microtime(true);
        $callLog   = $callService->call(source: $source, endpoint: $endpoint, method: $method, config: $config);
        $timeEnd   = microtime(true);

        return new JSONResponse($callLog->getObject());
    }//end test()
}//end class
