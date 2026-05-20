<?php

namespace OCA\OpenConnector\Controller;

use Exception;
use OCA\OpenConnector\Db\JobMapper;
use OCA\OpenConnector\Db\JobLogMapper;
use OCA\OpenConnector\Service\JobService;
use OCA\OpenConnector\Service\SearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\AppFramework\Db\DoesNotExistException;

/**
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
     * Constructor for the JobController
     *
     * @param string       $appName       The name of the app
     * @param IRequest     $request       The request object
     * @param IAppConfig   $config        The app configuration object
     * @param JobMapper    $jobMapper     The job mapper (used by run/test action methods)
     * @param JobLogMapper $jobLogMapper  The job log mapper (used by logs action)
     * @param JobService   $jobService    The job service (used by run/test action methods)
     * @param IL10N        $l             The localization service
     */
    public function __construct(
        $appName,
        IRequest $request,
        private IAppConfig $config,
        private JobMapper $jobMapper,
        private JobLogMapper $jobLogMapper,
        private JobService $jobService,
        private IL10N $l
    ) {
        parent::__construct($appName, $request);
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
     * Retrieves job logs with filtering and pagination support
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
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the filtered job logs and pagination
     */
    public function logs(SearchService $searchService): JSONResponse
    {
        try {
            // Get filters from request
            $filters        = $this->request->getParams();
            $specialFilters = [];

            // Pagination using _page and _limit
            $limit  = isset($filters['_limit']) ? (int) $filters['_limit'] : 20;
            $page   = isset($filters['_page']) ? (int) $filters['_page'] : 1;
            $offset = ($page - 1) * $limit;
            unset($filters['_limit'], $filters['_page']);

            // Handle special filters
            if (!empty($filters['date_from'])) {
                $specialFilters['date_from'] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $specialFilters['date_to'] = $filters['date_to'];
            }

            if (!empty($filters['status'])) {
                $specialFilters['status'] = $filters['status'];
            }

            if (!empty($filters['slow_executions'])) {
                $specialFilters['slow_executions'] = 5000; // 5 seconds in milliseconds
            }

            // Build search conditions and parameters
            $searchConditions = [];
            $searchParams     = [];

            if (!empty($specialFilters['date_from'])) {
                $searchConditions[] = "created >= ?";
                $searchParams[]     = $specialFilters['date_from'];
            }

            if (!empty($specialFilters['date_to'])) {
                $searchConditions[] = "created <= ?";
                $searchParams[]     = $specialFilters['date_to'];
            }

            if (!empty($specialFilters['status'])) {
                $searchConditions[] = "status = ?";
                $searchParams[]     = $specialFilters['status'];
            }

            if (!empty($specialFilters['slow_executions'])) {
                $searchConditions[] = "execution_time > ?";
                $searchParams[]     = $specialFilters['slow_executions'];
            }

            // Remove special query params from filters
            $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

            // Get job logs with filters and pagination
            $jobLogs = $this->jobLogMapper->findAll(
                limit: $limit,
                offset: $offset,
                filters: $filters,
                searchConditions: $searchConditions,
                searchParams: $searchParams
            );

            // Get total count for pagination
            $total       = $this->jobLogMapper->getTotalCount($filters);
            $pages       = $limit > 0 ? ceil($total / $limit) : 1;
            $currentPage = $limit > 0 ? floor($offset / $limit) + 1 : 1;

            // Return flattened paginated response
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
     * Executes a job
     *
     * This method executes a job based on its ID and returns the execution results.
     * The job can be executed with optional parameters provided in the request body.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  int $id The ID of the job to execute
     * @return JSONResponse A JSON response containing the execution results
     */
    public function run(int $id): JSONResponse
    {
        try {
            // Get the job
            $job = $this->jobMapper->find($id);

            // Get execution parameters from request
            $parameters = $this->request->getParams();

            // Remove non-parameter fields
            foreach ($parameters as $key => $value) {
                if (str_starts_with($key, '_')) {
                    unset($parameters[$key]);
                }
            }

            // Determine if forceRun is set
            $forceRun = isset($parameters['forceRun']) ? filter_var($parameters['forceRun'], FILTER_VALIDATE_BOOLEAN) : false;

            // Execute the job
            $result = $this->jobService->executeJob($job, $forceRun);

            // Return the execution results
            return new JSONResponse($result);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Job not found')], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Failed to execute job: %s', [$e->getMessage()])], 500);
        }//end try
    }//end run()

    /**
     * Test a job
     *
     * This method executes a job based on its ID and returns the execution results.
     * The job can be executed with optional parameters provided in the request body.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  int $id The ID of the job to execute
     * @return JSONResponse A JSON response containing the execution results
     */
    public function test(int $id): JSONResponse
    {
        try {
            // Get the job
            $job = $this->jobMapper->find($id);

            // Get execution parameters from request
            $parameters = $this->request->getParams();

            // Remove non-parameter fields
            foreach ($parameters as $key => $value) {
                if (str_starts_with($key, '_')) {
                    unset($parameters[$key]);
                }
            }

            // Always force run for test
            $forceRun = true;

            // Execute the job
            $result = $this->jobService->executeJob($job, $forceRun);

            // Return the execution results
            return new JSONResponse($result);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Job not found')], 404);
        } catch (Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Failed to execute job: %s', [$e->getMessage()])], 500);
        }//end try
    }//end test()
}//end class
