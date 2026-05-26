<?php
/**
 * OpenConnector SynchronizationsController.
 *
 * Controller for the synchronization detail page, test/run actions, statistics,
 * log listings and exports.
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
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenConnector\AppInfo\Application;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\SearchService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for synchronization-related endpoints (detail, run, logs, export).
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class SynchronizationsController extends Controller
{
    /**
     * Constructor for the SynchronizationsController.
     *
     * @param string                 $appName                The name of the app.
     * @param IRequest               $request                The request object.
     * @param OrObjectService        $orObjectService        The OR object service.
     * @param SynchronizationService $synchronizationService The synchronization service.
     * @param IL10N                  $l                      The localization service.
     * @param LoggerInterface        $logger                 The logger.
     * @param IUserSession           $userSession            The user session.
     * @param ActionAuthService      $actionAuth             The action authorization service.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly OrObjectService $orObjectService,
        private readonly SynchronizationService $synchronizationService,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Retrieves call logs for a job.
     *
     * This method returns all the call logs associated with a source based on its ID.
     *
     * @param integer $id The ID of the source to retrieve logs for.
     *
     * @return JSONResponse A JSON response containing the call logs.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function contracts(int $id): JSONResponse
    {
        $matches   = $this->orObjectService->findAll(
            config: [
                'filters' => [
                    'register'          => 'openconnector',
                    'schema'            => 'synchronization_contract',
                    'synchronizationId' => (string) $id,
                ],
            ]
        );
        $contracts = ($matches['results'] ?? $matches);
        return new JSONResponse($contracts);

    }//end contracts()

    /**
     * Retrieves synchronization logs with filtering and pagination support.
     *
     * This method returns synchronization logs based on query parameters,
     * with support for various filtering parameters to narrow down the results.
     *
     * Query Parameters:
     * - synchronization_id: Filter logs by synchronization ID
     * - date_from: Filter logs created after this date
     * - date_to: Filter logs created before this date
     * - status: Filter logs by status
     * - slow_syncs: Filter logs with sync time > 5000ms
     * - limit: Number of results per page (default: 20)
     * - offset: Offset for pagination (default: 0)
     *
     * @param SearchService $searchService Search helper service injected by route resolution.
     *
     * @return JSONResponse A JSON response containing the filtered synchronization logs and pagination.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
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

            if (empty($filters['slow_syncs']) === false) {
                // 5 seconds in milliseconds.
                $specialFilters['slow_syncs'] = 5000;
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

            if (empty($specialFilters['slow_syncs']) === false) {
                $searchConditions[] = "sync_time > ?";
                $searchParams[]     = $specialFilters['slow_syncs'];
            }

            // Remove special query params from filters.
            $filters = $searchService->unsetSpecialQueryParams(filters: $filters);

            // Get synchronization logs with filters and pagination via OR ObjectService.
            $orFilters = array_merge(['register' => 'openconnector', 'schema' => 'synchronization_log'], $filters);
            $matches   = $this->orObjectService->findAll(config: ['filters' => $orFilters, 'limit' => $limit, 'offset' => $offset]);
            $syncLogs  = ($matches['results'] ?? $matches);
            $total     = ($matches['total'] ?? count($syncLogs));
            if ($limit > 0) {
                $pages       = ceil($total / $limit);
                $currentPage = (floor($offset / $limit) + 1);
            } else {
                $pages       = 1;
                $currentPage = 1;
            }

            // Return flattened paginated response.
            return new JSONResponse(
                    [
                        'results'       => $syncLogs,
                        'page'          => $currentPage,
                        'pages'         => $pages,
                        'results_count' => count($syncLogs),
                        'total'         => $total,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Failed to retrieve logs: %s', [$e->getMessage()])], 500);
        }//end try

    }//end logs()

    /**
     * Tests a synchronization.
     *
     * This method tests a synchronization without persisting anything to the database.
     *
     * @param string       $id    The ID of the synchronization.
     * @param boolean|null $force Whether to force synchronization regardless of changes (default: false).
     *
     * @return JSONResponse A JSON response containing the test results.
     *
     * @throws GuzzleException             On HTTP transport failure.
     * @throws ContainerExceptionInterface Container resolution failure.
     * @throws NotFoundExceptionInterface  Container lookup miss.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @example
     * Request:
     * POST with optional force parameter
     *
     * Response:
     * {
     *     "resultObject": {
     *         "fullName": "John Doe",
     *         "userAge": 30,
     *         "contactEmail": "john@example.com"
     *     },
     *     "isValid": true,
     *     "validationErrors": []
     * }
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function test(string $id, ?bool $force=false): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'synchronization.test');

        try {
            $synchronization = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'synchronization');
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }

        // Try to synchronize.
        try {
            $logAndContractArray = $this->synchronizationService->synchronize(
                synchronization: $synchronization,
                isTest: true,
                force: $force
            );

            // Return the result as a JSON response.
            return new JSONResponse(data: $logAndContractArray, statusCode: 200);
        } catch (Exception $e) {
            // Check if getHeaders method exists and use it if available.
            if (method_exists($e, 'getHeaders') === true) {
                $headers = $e->getHeaders();
            } else {
                $headers = [];
            }

            // If synchronization fails, return an error response.
            return new JSONResponse(
                data: [
                    'error'   => $this->l->t('Synchronization error'),
                    'message' => $e->getMessage(),
                ],
                statusCode: ($e->getCode() ?? 400),
                headers: $headers
            );
        }//end try

    }//end test()

    /**
     * Run a synchronization.
     *
     * Endpoint: /api/synchronizations-run/{id}.
     *
     * @param string $id The UUID of the synchronization to run (post chain-B/C: OR IDs are UUIDs, not ints).
     *
     * @return JSONResponse A JSON response containing the run results.
     *
     * @throws GuzzleException             On HTTP transport failure.
     * @throws ContainerExceptionInterface Container resolution failure.
     * @throws NotFoundExceptionInterface  Container lookup miss.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function run(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'synchronization.run');

        $parameters = $this->request->getParams();
        $test       = filter_var(($parameters['test'] ?? false), FILTER_VALIDATE_BOOLEAN);
        $force      = filter_var(($parameters['force'] ?? false), FILTER_VALIDATE_BOOLEAN);
        $source     = ($parameters['source'] ?? null);
        $data       = ($parameters['data'] ?? []);

        try {
            $synchronization = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'synchronization');
        } catch (DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => $this->l->t('Not Found')], statusCode: 404);
        }

        // Try to synchronize.
        try {
            $logAndContractArray = $this->synchronizationService->synchronize(
                synchronization: $synchronization,
                isTest: $test,
                force: $force,
                source: $source,
                data: $data
            );

            // Return the result as a JSON response.
            return new JSONResponse(data: $logAndContractArray, statusCode: 200);
        } catch (Exception $e) {
            // Check if getHeaders method exists and use it if available.
            if (method_exists($e, 'getHeaders') === true) {
                $headers = $e->getHeaders();
            } else {
                $headers = [];
            }

            // If synchronization fails, return an error response.
            return new JSONResponse(
                data: [
                    'error'   => $this->l->t('Synchronization error'),
                    'message' => $e->getMessage(),
                ],
                statusCode: 400,
                headers: $headers
            );
        }//end try

    }//end run()

    /**
     * Get synchronization statistics.
     *
     * This method returns statistical information about synchronizations including:
     * - Total count of synchronizations
     * - Count by different statuses
     * - Distribution data for visualization
     *
     * @return JSONResponse A JSON response containing statistical data about synchronizations.
     *
     * @psalm-return   JSONResponse
     * @phpstan-return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function statistics(): JSONResponse
    {
        try {
            // Get basic counts via OR ObjectService.
            $baseFilters    = ['register' => 'openconnector', 'schema' => 'synchronization'];
            $allMatches     = $this->orObjectService->findAll(config: ['filters' => $baseFilters]);
            $enabledMatches = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['isEnabled' => true])]);
            $totalCount     = ($allMatches['total'] ?? count($allMatches['results'] ?? $allMatches));
            $enabledCount   = ($enabledMatches['total'] ?? count($enabledMatches['results'] ?? $enabledMatches));
            $disabledCount  = ($totalCount - $enabledCount);

            // Calculate distribution.
            $statusDistribution = [
                'enabled'  => $enabledCount,
                'disabled' => $disabledCount,
            ];

            // Calculate enabled percentage.
            if ($totalCount > 0) {
                $enabledPercentage = round((($enabledCount / $totalCount) * 100), 2);
            } else {
                $enabledPercentage = 0;
            }

            return new JSONResponse(
                    [
                        'totalCount'         => $totalCount,
                        'enabledCount'       => $enabledCount,
                        'disabledCount'      => $disabledCount,
                        'statusDistribution' => $statusDistribution,
                        'enabledPercentage'  => $enabledPercentage,
                        'generatedAt'        => (new \DateTime())->format('c'),
                    ]
                    );
        } catch (\Exception $e) {
            // Log the error for debugging purposes.
            $this->logger->error('Error fetching synchronization statistics: '.$e->getMessage());

            // Return error response with appropriate status code.
            return new JSONResponse(
                    [
                        'error'   => $this->l->t('Could not fetch synchronization statistics'),
                        'message' => $this->l->t('An error occurred while retrieving statistical data'),
                    ],
                    500
                    );
        }//end try

    }//end statistics()

    /**
     * Get synchronization logs statistics.
     *
     * This method returns statistical information about synchronization logs including:
     * - Total counts by status (success, error, warning, info, debug)
     * - Status distribution data
     * - Performance metrics for synchronization operations
     *
     * @return JSONResponse A JSON response containing statistical data about synchronization logs.
     *
     * @psalm-return   JSONResponse
     * @phpstan-return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function logsStatistics(): JSONResponse
    {
        try {
            // Get basic counts by status/level via OR ObjectService.
            $baseFilters    = ['register' => 'openconnector', 'schema' => 'synchronization_log'];
            $allMatches     = $this->orObjectService->findAll(config: ['filters' => $baseFilters]);
            $successMatches = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['status' => 'success'])]);
            $errorMatches   = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['status' => 'error'])]);
            $warningMatches = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['status' => 'warning'])]);
            $infoMatches    = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['status' => 'info'])]);
            $debugMatches   = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['status' => 'debug'])]);
            $totalCount     = ($allMatches['total'] ?? count($allMatches['results'] ?? $allMatches));
            $successCount   = ($successMatches['total'] ?? count($successMatches['results'] ?? $successMatches));
            $errorCount     = ($errorMatches['total'] ?? count($errorMatches['results'] ?? $errorMatches));
            $warningCount   = ($warningMatches['total'] ?? count($warningMatches['results'] ?? $warningMatches));
            $infoCount      = ($infoMatches['total'] ?? count($infoMatches['results'] ?? $infoMatches));
            $debugCount     = ($debugMatches['total'] ?? count($debugMatches['results'] ?? $debugMatches));

            // Calculate status distribution for charts and visualizations.
            $statusDistribution = [
                'success' => $successCount,
                'error'   => $errorCount,
                'warning' => $warningCount,
                'info'    => $infoCount,
                'debug'   => $debugCount,
            ];

            // Calculate success rate as percentage.
            if ($totalCount > 0) {
                $successRate = round((($successCount / $totalCount) * 100), 2);
            } else {
                $successRate = 0;
            }

            // Get recent activity (logs from last 24 hours).
            // For simplicity, we'll estimate recent activity as a percentage of total logs.
            // This could be improved with a custom mapper method if needed.
            if ($totalCount > 0) {
                $recentLogsCount = max(1, (int) ($totalCount * 0.1));
            } else {
                $recentLogsCount = 0;
            }

            return new JSONResponse(
                    [
                        'totalCount'         => $totalCount,
                        'successCount'       => $successCount,
                        'errorCount'         => $errorCount,
                        'warningCount'       => $warningCount,
                        'infoCount'          => $infoCount,
                        'debugCount'         => $debugCount,
                        'statusDistribution' => $statusDistribution,
                        'successRate'        => $successRate,
                        'recentActivity'     => $recentLogsCount,
                        'generatedAt'        => (new \DateTime())->format('c'),
                    ]
                    );
        } catch (\Exception $e) {
            // Log the error for debugging purposes.
            $this->logger->error('Error fetching synchronization logs statistics: '.$e->getMessage());

            // Return error response with appropriate status code.
            return new JSONResponse(
                    [
                        'error'   => $this->l->t('Could not fetch synchronization logs statistics'),
                        'message' => $this->l->t('An error occurred while retrieving statistical data'),
                    ],
                    500
                    );
        }//end try

    }//end logsStatistics()

    /**
     * Export synchronization logs as CSV.
     *
     * This method exports synchronization logs as a CSV file with optional filtering.
     * The exported data includes all relevant log information formatted for analysis.
     *
     * @return JSONResponse A JSON response containing the CSV export data.
     *
     * @psalm-return   JSONResponse
     * @phpstan-return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function logsExport(): JSONResponse
    {
        try {
            // Get filters from request parameters.
            $filters = $this->request->getParams();

            // Remove pagination and other non-filter parameters.
            unset($filters['_limit'], $filters['_page'], $filters['_sort'], $filters['_order']);

            // Get all logs matching filters (no pagination for export) via OR ObjectService.
            $orFilters = array_merge(['register' => 'openconnector', 'schema' => 'synchronization_log'], $filters);
            $matches   = $this->orObjectService->findAll(config: ['filters' => $orFilters]);
            $logs      = ($matches['results'] ?? $matches);

            // Create CSV content with headers.
            $csvData = "UUID,Status,Message,Synchronization ID,Source ID,Target ID,User ID,Created,Execution Time\n";

            foreach ($logs as $log) {
                $data = $log->getObject();
                // Escape CSV values to prevent injection and handle commas.
                $csvData .= sprintf(
                    '%s,%s,"%s",%s,%s,%s,%s,%s,%s'."\n",
                    ($log->getUuid() ?? ''),
                    ($data['status'] ?? ''),
                    // Escape quotes in message.
                    str_replace('"', '""', ($data['message'] ?? '')),
                    ($data['synchronizationId'] ?? ''),
                    ($data['sourceId'] ?? ''),
                    ($data['targetId'] ?? ''),
                    ($data['userId'] ?? ''),
                    ($data['created'] ?? ''),
                    ($data['syncTime'] ?? '')
                );
            }

            // Generate filename with timestamp.
            $filename = ('synchronization_logs_'.date('Y-m-d_H-i-s').'.csv');

            return new JSONResponse(
                    [
                        'content'     => base64_encode($csvData),
                        'filename'    => $filename,
                        'contentType' => 'text/csv',
                        'recordCount' => count($logs),
                        'generatedAt' => (new \DateTime())->format('c'),
                    ]
                    );
        } catch (\Exception $e) {
            // Log the error for debugging purposes.
            $this->logger->error('Error exporting synchronization logs: '.$e->getMessage());

            // Return error response with appropriate status code.
            return new JSONResponse(
                    [
                        'error'   => $this->l->t('Could not export synchronization logs'),
                        'message' => $this->l->t('An error occurred while generating the export file'),
                    ],
                    500
                    );
        }//end try

    }//end logsExport()

    /**
     * Deletes a single synchronization log.
     *
     * This method deletes a synchronization log based on its ID.
     *
     * @param integer $id The ID of the synchronization log to delete.
     *
     * @return JSONResponse A JSON response indicating success or failure.
     *
     * @psalm-param    int $id
     * @psalm-return   JSONResponse
     * @phpstan-param  int $id
     * @phpstan-return JSONResponse
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deleteLog(int $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'synchronization.delete-log');

        try {
            $log = $this->orObjectService->find(id: (string) $id, register: 'openconnector', schema: 'synchronization_log');
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Log not found')], 404);
        }

        try {
            $this->orObjectService->deleteObject(uuid: $log->getUuid());
            return new JSONResponse(['message' => $this->l->t('Log deleted successfully')], 200);
        } catch (\Exception $exception) {
            return new JSONResponse(['error' => $this->l->t('Failed to delete log: %s', [$exception->getMessage()])], 500);
        }

    }//end deleteLog()
}//end class
