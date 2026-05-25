<?php
/**
 * OpenConnector jobs controller.
 *
 * Controller for job listing, log retrieval, and ad-hoc job run/test endpoints.
 * Delegates execution to JobService and applies search-style log filtering.
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

use Exception;
use OCA\OpenConnector\Service\JobService;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for job listing, log retrieval, and ad-hoc job run/test endpoints.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class JobsController extends Controller
{
    /**
     * Constructor for the JobController.
     *
     * @param string          $appName         The name of the app.
     * @param IRequest        $request         The request object.
     * @param OrObjectService $orObjectService The OR object service.
     * @param JobService      $jobService      The job service (used by run/test action methods).
     * @param IL10N           $l               The localization service.
     *
     * @return void
     */
    public function __construct(
        $appName,
        IRequest $request,
        private OrObjectService $orObjectService,
        private JobService $jobService,
        private IL10N $l
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Returns the template of the main app's page.
     *
     * This method renders the main page of the application, adding any necessary data to the template.
     *
     * @return TemplateResponse The rendered template response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec exclude SPA-shell render — returns the index template only, no domain behavior (framework lifecycle).
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
     * Retrieves job logs with filtering and pagination support.
     *
     * This method returns job logs based on query parameters,
     * with support for various filtering parameters to narrow down the results.
     *
     * Query Parameters:
     * - job_id: Filter logs by job ID
     * - date_from: Filter logs created after this date
     * - date_to: Filter logs created before this date
     * - status: Filter logs by status
     * - slow_executions: Filter logs with execution time > 5000ms
     * - limit: Number of results per page (default: 20)
     * - offset: Offset for pagination (default: 0)
     *
     * @param SearchService $searchService Service used to strip special query parameters.
     *
     * @return JSONResponse A JSON response containing the filtered job logs and pagination.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-job-scheduling/tasks.md#task-1
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
            }

            if (empty($filters['date_to']) === false) {
                $specialFilters['date_to'] = $filters['date_to'];
            }

            if (empty($filters['status']) === false) {
                $specialFilters['status'] = $filters['status'];
            }

            if (empty($filters['slow_executions']) === false) {
                $specialFilters['slow_executions'] = 5000;
                // 5 seconds in milliseconds.
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

            if (empty($specialFilters['status']) === false) {
                $searchConditions[] = "status = ?";
                $searchParams[]     = $specialFilters['status'];
            }

            if (empty($specialFilters['slow_executions']) === false) {
                $searchConditions[] = "execution_time > ?";
                $searchParams[]     = $specialFilters['slow_executions'];
            }

            // Remove special query params from filters.
            $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

            // Get job logs with filters and pagination via OR ObjectService.
            $orFilters = array_merge(['register' => 'openconnector', 'schema' => 'job_log'], $filters);
            $matches   = $this->orObjectService->findAll(config: ['filters' => $orFilters, 'limit' => $limit, 'offset' => $offset]);
            $jobLogs   = ($matches['results'] ?? $matches);
            $total     = ($matches['total'] ?? count($jobLogs));
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
                        'results'       => $jobLogs,
                        'page'          => $currentPage,
                        'pages'         => $pages,
                        'results_count' => count($jobLogs),
                        'total'         => $total,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Failed to retrieve logs: %s', [$e->getMessage()])], 500);
        }//end try
    }//end logs()

    /**
     * Executes a job.
     *
     * This method executes a job based on its ID and returns the execution results.
     * The job can be executed with optional parameters provided in the request body.
     *
     * @param string $id The UUID of the job to execute (post chain-B/C: OR IDs are UUIDs, not ints).
     *
     * @return JSONResponse A JSON response containing the execution results.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-job-scheduling/tasks.md#task-2
     */
    public function run(string $id): JSONResponse
    {
        try {
            $job = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'job');
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Job not found')], 404);
        }

        try {
            // Get execution parameters from request.
            $parameters = $this->request->getParams();

            // Remove non-parameter fields.
            foreach ($parameters as $key => $value) {
                if (str_starts_with($key, '_') === true) {
                    unset($parameters[$key]);
                }
            }

            // Determine if forceRun is set.
            if (isset($parameters['forceRun']) === true) {
                $forceRun = filter_var($parameters['forceRun'], FILTER_VALIDATE_BOOLEAN);
            } else {
                $forceRun = false;
            }

            // Execute the job.
            $result = $this->jobService->executeJob(job: $job, forceRun: $forceRun);

            // Return the execution results (serialize the ObjectEntity to a plain array).
            if ($result === null) {
                return new JSONResponse(null);
            }

            return new JSONResponse($result->jsonSerialize());
        } catch (Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Failed to execute job: %s', [$e->getMessage()])], 500);
        }//end try
    }//end run()

    /**
     * Test a job.
     *
     * This method executes a job based on its ID and returns the execution results.
     * The job can be executed with optional parameters provided in the request body.
     *
     * @param string $id The UUID of the job to execute (post chain-B/C: OR IDs are UUIDs, not ints).
     *
     * @return JSONResponse A JSON response containing the execution results.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-job-scheduling/tasks.md#task-2
     */
    public function test(string $id): JSONResponse
    {
        try {
            $job = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'job');
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Job not found')], 404);
        }

        try {
            // Get execution parameters from request.
            $parameters = $this->request->getParams();

            // Remove non-parameter fields.
            foreach ($parameters as $key => $value) {
                if (str_starts_with($key, '_') === true) {
                    unset($parameters[$key]);
                }
            }

            // Always force run for test.
            $forceRun = true;

            // Execute the job.
            $result = $this->jobService->executeJob(job: $job, forceRun: $forceRun);

            // Return the execution results (serialize the ObjectEntity to a plain array).
            if ($result === null) {
                return new JSONResponse(null);
            }

            return new JSONResponse($result->jsonSerialize());
        } catch (Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Failed to execute job: %s', [$e->getMessage()])], 500);
        }//end try
    }//end test()
}//end class
