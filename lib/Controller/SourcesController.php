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

use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenConnector\Settings\OpenConnectorAdmin;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for source-test and call-log endpoints.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
 */
class SourcesController extends Controller
{
    /**
     * Constructor for the SourcesController.
     *
     * @param string            $appName         The name of the app.
     * @param IRequest          $request         The request object.
     * @param OrObjectService   $orObjectService The OR object service.
     * @param IL10N             $l               The localization service.
     * @param IUserSession      $userSession     The user session.
     * @param ActionAuthService $actionAuth      The action authorization service.
     * @param LoggerInterface   $logger          Logger for source-test failures.
     *
     * @return void
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly OrObjectService $orObjectService,
        private readonly IL10N $l,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Retrieves call logs with filtering and pagination support.
     *
     * Admin-only: gated at the middleware layer via #[AuthorizedAdminSetting].
     *
     * @param SearchService $searchService Service used to strip special query parameters.
     *
     * @return JSONResponse A JSON response containing the filtered call logs and pagination.
     *
     * @spec openspec/specs/logs-and-statistics/spec.md
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
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
     * @param CallService $callService The CallService used to dispatch the outbound test call.
     * @param string      $id          The UUID of the source to test.
     *
     * @return JSONResponse A JSON response containing the test results.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/logs-and-statistics/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function test(CallService $callService, string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], \OCP\AppFramework\Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'source.test');

        // ObjectService::find() throws DoesNotExistException on a missing
        // UUID — catch it so the response is a clean 404 instead of 500.
        try {
            $source = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'source', _rbac: false, _multitenancy: false);
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

        // Fire the call with persistLog:false — an interactive connection test returns the
        // live response but must NOT write a CallLog: persisting one runs a full OpenRegister
        // object save plus a source-rate-limit mutation (test noise + latency). And any engine
        // failure must surface as clean JSON, never an empty 200 the UI cannot interpret, so
        // the whole call is wrapped: the Test-connection modal always gets a readable result.
        try {
            $callLog = $callService->call(
                source: $source,
                endpoint: $endpoint,
                method: $method,
                config: $config,
                persistLog: false
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Source test failed: '.$e->getMessage(),
                ['app' => 'openconnector', 'sourceId' => $id, 'exception' => $e]
            );
            return new JSONResponse(
                data: ['error' => $this->l->t('The source test could not be completed: %s', [$e->getMessage()])],
                statusCode: \OCP\AppFramework\Http::STATUS_BAD_GATEWAY
            );
        }

        $result = $callLog->getObject();
        if (is_array($result) === false || isset($result['response']) === false) {
            // The engine returned without a usable response (e.g. an early-exit CallLog).
            // Give the UI an explicit error rather than an empty/opaque body.
            return new JSONResponse(
                data: ['error' => $this->l->t('The source test returned no response data.')],
                statusCode: \OCP\AppFramework\Http::STATUS_BAD_GATEWAY
            );
        }

        return new JSONResponse($result);
    }//end test()

    /**
     * Manually trips the circuit breaker for a Source (REQ-009).
     *
     * Admin-only, CSRF-protected — deliberately NOT `@NoAdminRequired`
     * (Decision 6, retry-and-circuit-breaker-policies design.md): tripping a
     * breaker is an operationally sensitive action that can black-hole
     * traffic to an upstream, so it follows the `dead-letter-replay`
     * admin-only posture rather than this app's older IDOR-prone convention.
     *
     * @param CallService $callService The CallService used to trip the breaker.
     * @param string      $id          The UUID of the source to trip.
     *
     * @return JSONResponse The updated breaker state, or 404 when the source is unknown.
     *
     * @no-admin-idor-exempt Admin-only via #[AuthorizedAdminSetting]; not a
     * #[NoAdminRequired] endpoint. The IDOR gate misattributes the preceding
     * test() method's @NoAdminRequired across the method boundary.
     *
     * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function tripCircuitBreaker(CallService $callService, string $id): JSONResponse
    {
        try {
            $source = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'source', _rbac: false, _multitenancy: false);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }

        $saved = $callService->tripCircuitBreaker(source: $source);
        $data  = $saved->getObject();

        return new JSONResponse(
            [
                'uuid'                   => $saved->getUuid(),
                'circuitBreakerState'    => $data['circuitBreakerState'],
                'circuitBreakerOpenedAt' => $data['circuitBreakerOpenedAt'],
            ]
        );
    }//end tripCircuitBreaker()

    /**
     * Manually resets the circuit breaker for a Source (REQ-009).
     *
     * Admin-only, CSRF-protected — see {@see tripCircuitBreaker()} for the
     * rationale.
     *
     * @param CallService $callService The CallService used to reset the breaker.
     * @param string      $id          The UUID of the source to reset.
     *
     * @return JSONResponse The updated breaker state, or 404 when the source is unknown.
     *
     * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
     */
    #[AuthorizedAdminSetting(OpenConnectorAdmin::class)]
    public function resetCircuitBreaker(CallService $callService, string $id): JSONResponse
    {
        try {
            $source = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'source', _rbac: false, _multitenancy: false);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }

        $saved = $callService->resetCircuitBreaker(source: $source);
        $data  = $saved->getObject();

        return new JSONResponse(
            [
                'uuid'                       => $saved->getUuid(),
                'circuitBreakerState'        => $data['circuitBreakerState'],
                'circuitBreakerFailureCount' => $data['circuitBreakerFailureCount'],
            ]
        );
    }//end resetCircuitBreaker()
}//end class
