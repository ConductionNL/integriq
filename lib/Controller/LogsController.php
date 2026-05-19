<?php

declare(strict_types=1);

/**
 * LogsController
 * 
 * Controller for managing synchronization logs
 *
 * @category   Controller
 * @package    OCA\OpenConnector\Controller
 * @author     Conduction.nl <info@conduction.nl>
 * @copyright  Conduction.nl 2024
 * @license    EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version    1.0.0
 * @link       https://github.com/ConductionNL/openconnector
 */

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Db\SynchronizationLog;
use OCA\OpenConnector\Db\SynchronizationLogMapper;
use OCA\OpenConnector\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for managing synchronization logs
 *
 * This controller handles CRUD operations for synchronization logs,
 * including filtering, pagination, and statistics.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class LogsController extends Controller
{
    /**
     * The synchronization log mapper
     *
     * @var SynchronizationLogMapper
     */
    private SynchronizationLogMapper $synchronizationLogMapper;

    /**
     * The object service
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * The localization service
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * Constructor for the LogsController
     *
     * @param string                   $appName                  The application name
     * @param IRequest                 $request                  The request interface
     * @param SynchronizationLogMapper $synchronizationLogMapper The synchronization log mapper
     * @param ObjectService            $objectService            The object service
     * @param IL10N                    $l                        The localization service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        SynchronizationLogMapper $synchronizationLogMapper,
        ObjectService $objectService,
        IL10N $l
    ) {
        parent::__construct($appName, $request);

        $this->synchronizationLogMapper = $synchronizationLogMapper;
        $this->objectService = $objectService;
        $this->l = $l;
    }

    /**
     * Get all synchronization logs
     *
     * This method returns a list of all synchronization logs with optional filtering and pagination.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int|null    $limit  Maximum number of results to return (default: 20)
     * @param int|null    $offset Starting offset for results (default: 0)
     * @param string|null $level  Filter by log level
     * @param string|null $message Search in log messages
     * @param string|null $synchronizationId Filter by synchronization ID
     * @param string|null $dateFrom Filter logs from this date
     * @param string|null $dateTo Filter logs until this date
     *
     * @return JSONResponse The logs list response
     */
    public function index(
        ?int $limit = 20,
        ?int $offset = 0,
        ?string $level = null,
        ?string $message = null,
        ?string $synchronizationId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): JSONResponse {
        // Build filters array
        $filters = [];
        
        // Add individual filters if provided
        if ($level !== null) {
            $filters['level'] = $level;
        }
        if ($message !== null) {
            $filters['message'] = $message;
        }
        if ($synchronizationId !== null) {
            $filters['synchronization_id'] = $synchronizationId;
        }
        if ($dateFrom !== null) {
            $filters['date_from'] = $dateFrom;
        }
        if ($dateTo !== null) {
            $filters['date_to'] = $dateTo;
        }

        // Get logs with pagination
        $logs = $this->synchronizationLogMapper->findAll($limit, $offset, $filters);
        $total = $this->synchronizationLogMapper->getTotalCount($filters);

        // Calculate pagination info
        $pages = $limit > 0 ? ceil($total / $limit) : 1;
        $currentPage = $limit > 0 ? floor($offset / $limit) + 1 : 1;

        return new JSONResponse([
            'results' => $logs,
            'pagination' => [
                'page' => $currentPage,
                'pages' => $pages,
                'results' => count($logs),
                'total' => $total
            ]
        ]);
    }

    /**
     * Get a specific synchronization log
     *
     * This method returns a single synchronization log by its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the log to retrieve
     *
     * @return JSONResponse The log response
     * @throws OCSNotFoundException When the log is not found
     */
    public function show(string $id): JSONResponse
    {
        try {
            $log = $this->synchronizationLogMapper->find((int) $id);
            return new JSONResponse($log);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Log not found')], 404);
        }
    }

    /**
     * Delete a synchronization log
     *
     * This method deletes a synchronization log by its ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $id The ID of the log to delete
     *
     * @return JSONResponse The deletion response
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $log = $this->synchronizationLogMapper->find((int) $id);
            $this->synchronizationLogMapper->delete($log);
            
            return new JSONResponse(['message' => $this->l->t('Log deleted successfully')]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Log not found or could not be deleted')], 404);
        }
    }

    /**
     * Get log statistics
     *
     * This method returns statistical information about synchronization logs.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The statistics response
     */
    public function statistics(): JSONResponse
    {
        try {
            // Get basic counts by level
            $errorCount = $this->synchronizationLogMapper->getTotalCount(['level' => 'error']);
            $warningCount = $this->synchronizationLogMapper->getTotalCount(['level' => 'warning']);
            $infoCount = $this->synchronizationLogMapper->getTotalCount(['level' => 'info']);
            $successCount = $this->synchronizationLogMapper->getTotalCount(['level' => 'success']);
            $debugCount = $this->synchronizationLogMapper->getTotalCount(['level' => 'debug']);

            // Calculate level distribution
            $levelDistribution = [
                'error' => $errorCount,
                'warning' => $warningCount,
                'info' => $infoCount,
                'success' => $successCount,
                'debug' => $debugCount,
            ];

            return new JSONResponse([
                'errorCount' => $errorCount,
                'warningCount' => $warningCount,
                'infoCount' => $infoCount,
                'successCount' => $successCount,
                'debugCount' => $debugCount,
                'levelDistribution' => $levelDistribution,
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Could not fetch statistics')], 500);
        }
    }

    /**
     * Export logs as CSV
     *
     * This method exports synchronization logs as a CSV file.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string|null $level Filter by log level
     * @param string|null $message Search in log messages
     * @param string|null $synchronizationId Filter by synchronization ID
     * @param string|null $dateFrom Filter logs from this date
     * @param string|null $dateTo Filter logs until this date
     *
     * @return JSONResponse The export response
     */
    public function export(
        ?string $level = null,
        ?string $message = null,
        ?string $synchronizationId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): JSONResponse {
        try {
            // Build filters array (same as index method)
            $filters = [];
            
            if ($level !== null) {
                $filters['level'] = $level;
            }
            if ($message !== null) {
                $filters['message'] = $message;
            }
            if ($synchronizationId !== null) {
                $filters['synchronization_id'] = $synchronizationId;
            }
            if ($dateFrom !== null) {
                $filters['date_from'] = $dateFrom;
            }
            if ($dateTo !== null) {
                $filters['date_to'] = $dateTo;
            }

            // Get all logs matching filters (no pagination for export)
            $logs = $this->synchronizationLogMapper->findAll(null, null, $filters);

            // Create CSV content
            $csvData = "ID,UUID,Level,Message,Synchronization ID,User ID,Session ID,Created,Expires\n";
            
            foreach ($logs as $log) {
                $csvData .= sprintf(
                    "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                    $log->getId() ?? '',
                    $log->getUuid() ?? '',
                    $log->getLevel() ?? '',
                    '"' . str_replace('"', '""', $log->getMessage() ?? '') . '"',
                    $log->getSynchronizationId() ?? '',
                    $log->getUserId() ?? '',
                    $log->getSessionId() ?? '',
                    $log->getCreated() ? $log->getCreated()->format('Y-m-d H:i:s') : '',
                    $log->getExpires() ? $log->getExpires()->format('Y-m-d H:i:s') : ''
                );
            }

            // Return CSV as response
            return new JSONResponse([
                'filename' => 'synchronization_logs_' . date('Y-m-d_H-i-s') . '.csv',
                'content' => $csvData,
                'contentType' => 'text/csv'
            ]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Could not export logs')], 500);
        }
    }
} 