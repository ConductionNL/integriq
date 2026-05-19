<?php

namespace OCA\OpenConnector\Controller;

use Exception;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenConnector\Db\Job;
use OCA\OpenConnector\Db\JobMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\BackgroundJob\IJobList;
use OCA\OpenConnector\Db\JobLogMapper;
use OCA\OpenConnector\Service\JobService;
use OCP\AppFramework\Db\DoesNotExistException;

use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Db\SynchronizationMapper;

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
     * @param string $appName The name of the app
     * @param IRequest $request The request object
     * @param IAppConfig $config The app configuration object
     */
    public function __construct(
        $appName,
        IRequest $request,
        private IAppConfig $config,
        private JobMapper $jobMapper,
        private JobLogMapper $jobLogMapper,
        private JobService $jobService,
        private IJobList $jobList,
        private SynchronizationService $synchronizationService,
        private SynchronizationMapper $synchronizationMapper,
        private IL10N $l
    )
    {
        parent::__construct($appName, $request);
        $this->jobList = $jobList;
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
     * Retrieves a list of all jobs
     *
     * This method returns a JSON response containing an array of all jobs in the system.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the list of jobs
     */
    public function index(ObjectService $objectService, SearchService $searchService): JSONResponse
    {
        $filters = $this->request->getParams();
        $fieldsToSearch = ['name', 'description'];

        $searchParams = $searchService->createMySQLSearchParams(filters: $filters);
        $searchConditions = $searchService->createMySQLSearchConditions(filters: $filters, fieldsToSearch:  $fieldsToSearch);
        $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

        return new JSONResponse(['results' => $this->jobMapper->findAll(limit: null, offset: null, filters: $filters, searchConditions: $searchConditions, searchParams: $searchParams)]);
    }

    /**
     * Retrieves a single job by its ID
     *
     * This method returns a JSON response containing the details of a specific job.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the job to retrieve
     * @return JSONResponse A JSON response containing the job details
     */
    public function show(string $id): JSONResponse
    {
        try {
            return new JSONResponse($this->jobMapper->find(id: (int) $id));
        } catch (DoesNotExistException $exception) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }
    }

    /**
     * Creates a new job
     *
     * This method creates a new job based on POST data.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the created job
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

        // Create the job
        $job = $this->jobMapper->createFromArray(object: $data);
        // Let's schedule the job
        $job = $this->jobService->scheduleJob($job);

        return new JSONResponse($job);
    }

    /**
     * Updates an existing job
     *
     * This method updates an existing job based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the job to update
     * @return JSONResponse A JSON response containing the updated job details
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

        // Create the job
        $job = $this->jobMapper->updateFromArray(id: (int) $id, object: $data);
        // Let's schedule the job
        $job = $this->jobService->scheduleJob($job);

        return new JSONResponse($job);
    }

    /**
     * Deletes a job
     *
     * This method deletes a job based on its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the job to delete
     * @return JSONResponse An empty JSON response
     */
    public function destroy(int $id): JSONResponse
    {
        $this->jobMapper->delete($this->jobMapper->find((int) $id));

        return new JSONResponse([]);
    }

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
            $filters = $this->request->getParams();
            $specialFilters = [];

            // Pagination using _page and _limit
            $limit = isset($filters['_limit']) ? (int)$filters['_limit'] : 20;
            $page = isset($filters['_page']) ? (int)$filters['_page'] : 1;
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
            $searchParams = [];

            if (!empty($specialFilters['date_from'])) {
                $searchConditions[] = "created >= ?";
                $searchParams[] = $specialFilters['date_from'];
            }

            if (!empty($specialFilters['date_to'])) {
                $searchConditions[] = "created <= ?";
                $searchParams[] = $specialFilters['date_to'];
            }

            if (!empty($specialFilters['status'])) {
                $searchConditions[] = "status = ?";
                $searchParams[] = $specialFilters['status'];
            }

            if (!empty($specialFilters['slow_executions'])) {
                $searchConditions[] = "execution_time > ?";
                $searchParams[] = $specialFilters['slow_executions'];
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
            $total = $this->jobLogMapper->getTotalCount($filters);
            $pages = $limit > 0 ? ceil($total / $limit) : 1;
            $currentPage = $limit > 0 ? floor($offset / $limit) + 1 : 1;

            // Return flattened paginated response
            return new JSONResponse([
                'results' => $jobLogs,
                'page' => $currentPage,
                'pages' => $pages,
                'results_count' => count($jobLogs),
                'total' => $total
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Failed to retrieve logs: %s', [$e->getMessage()])], 500);
        }
    }

    /**
     * Executes a job
     *
     * This method executes a job based on its ID and returns the execution results.
     * The job can be executed with optional parameters provided in the request body.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the job to execute
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
        }
    }

    /**
     * Test a job
     *
     * This method executes a job based on its ID and returns the execution results.
     * The job can be executed with optional parameters provided in the request body.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The ID of the job to execute
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
        }
    }
}
