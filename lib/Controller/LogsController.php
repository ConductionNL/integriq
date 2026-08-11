<?php
/**
 * OpenConnector LogsController.
 *
 * Controller for managing synchronization logs. Handles CRUD operations,
 * filtering, pagination, statistics and CSV export.
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

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\SourceMappingService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for managing synchronization logs.
 *
 * This controller handles CRUD operations for synchronization logs,
 * including filtering, pagination, and statistics.
 *
 * Authorization posture (ADR-023, ADR-005 Rule 3): a synchronization log is
 * instance-wide operational audit data, not a per-user object — it records
 * which integration ran, against which source, carrying which payload, on
 * behalf of which `userId`. There is therefore no per-object owner to scope
 * to; the correct boundary is the action-RBAC matrix, which every other
 * log-reading surface in this app already uses (`source.logs`, `job.logs`,
 * `endpoint.logs`, `synchronization.logs`).
 *
 * The five `log.*` actions this controller now enforces were declared and
 * seeded in `lib/actions.seed.json` when action RBAC was introduced, and were
 * never called from anywhere. `#[NoAdminRequired]` plus an authentication
 * check was the whole of the access control, so any authenticated account
 * could read and DELETE any other account's audit record.
 *
 * @spec openspec/specs/logs-and-statistics/spec.md
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class LogsController extends Controller
{

    /**
     * The OR object service.
     *
     * @var OrObjectService
     */
    private OrObjectService $orObjectService;

    /**
     * The localization service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * Constructor for the LogsController.
     *
     * @param string            $appName         The application name.
     * @param IRequest          $request         The request interface.
     * @param OrObjectService   $orObjectService The OR object service.
     * @param IL10N             $l               The localization service.
     * @param IUserSession      $userSession     The user session.
     * @param ActionAuthService $actionAuth      The ADR-023 action authorization service.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        OrObjectService $orObjectService,
        IL10N $l,
        private readonly IUserSession $userSession,
        private readonly ActionAuthService $actionAuth,
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->orObjectService = $orObjectService;
        $this->l = $l;

    }//end __construct()

    /**
     * Get all synchronization logs.
     *
     * This method returns a list of all synchronization logs with optional filtering and pagination.
     *
     * @param integer|null $limit             Maximum number of results to return (default: 20).
     * @param integer|null $offset            Starting offset for results (default: 0).
     * @param string|null  $level             Filter by log level.
     * @param string|null  $message           Search in log messages.
     * @param string|null  $synchronizationId Filter by synchronization ID.
     * @param string|null  $dateFrom          Filter logs from this date.
     * @param string|null  $dateTo            Filter logs until this date.
     *
     * @return JSONResponse The logs list response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/logs-and-statistics/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(
        ?int $limit=20,
        ?int $offset=0,
        ?string $level=null,
        ?string $message=null,
        ?string $synchronizationId=null,
        ?string $dateFrom=null,
        ?string $dateTo=null
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'log.index');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        // Build filters array.
        $filters = [];

        // Add individual filters if provided.
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

        // Get logs with pagination via OR ObjectService.
        $orFilters = array_merge(['register' => 'openconnector', 'schema' => 'synchronization_log'], $filters);
        $matches   = $this->orObjectService->findAll(config: ['filters' => $orFilters, 'limit' => $limit, 'offset' => $offset]);
        $logs      = ($matches['results'] ?? $matches);
        $total     = ($matches['total'] ?? count($logs));

        // Calculate pagination info.
        if ($limit > 0) {
            $pages       = ceil($total / $limit);
            $currentPage = (floor($offset / $limit) + 1);
        } else {
            $pages       = 1;
            $currentPage = 1;
        }

        return new JSONResponse(
                [
                    'results'    => $logs,
                    'pagination' => [
                        'page'    => $currentPage,
                        'pages'   => $pages,
                        'results' => count($logs),
                        'total'   => $total,
                    ],
                ]
                );

    }//end index()

    /**
     * Get a specific synchronization log.
     *
     * This method returns a single synchronization log by its ID.
     *
     * @param string $id The ID of the log to retrieve.
     *
     * @return JSONResponse The log response.
     *
     * @throws OCSNotFoundException When the log is not found.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/logs-and-statistics/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'log.show');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        // `find()` does not only return null for a miss — it throws
        // DoesNotExistException. Without this catch an unknown id was a 500
        // rather than the 404 the null branch below was written to produce.
        //
        // OR's own rbac/multitenancy are left at their defaults. They used to
        // be switched off here in writing (`_rbac: false, _multitenancy:
        // false`), which meant no future OpenRegister default and no
        // `authorization` block on the `synchronization_log` schema could ever
        // narrow this read. The action check above is the guard that decides;
        // OR is a second layer, and a second layer that is disabled in source
        // can never become one.
        try {
            $log = $this->orObjectService->find(
                id: $id,
                register: 'openconnector',
                schema: 'synchronization_log'
            );
        } catch (DoesNotExistException $e) {
            $log = null;
        }

        if ($log === null) {
            return new JSONResponse(['error' => $this->l->t('Log not found')], 404);
        }

        return new JSONResponse($log->getObject());

    }//end show()

    /**
     * Delete a synchronization log.
     *
     * This method deletes a synchronization log by its ID.
     *
     * @param string $id The ID of the log to delete.
     *
     * @return JSONResponse The deletion response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/logs-and-statistics/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function destroy(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->actionAuth->requireAction(user: $user, action: 'log.destroy');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        // Both calls throw DoesNotExistException rather than only returning
        // null — including deleteObject(), which can lose a race with a
        // concurrent delete between the read above and the write below. Either
        // way the log is gone, which is the 404 this method already meant.
        //
        // OR's rbac/multitenancy are left at their defaults here for the same
        // reason as in show(): this is the destructive endpoint, and it is the
        // one that must not opt out of the platform's own layer in writing.
        try {
            $log = $this->orObjectService->find(
                id: $id,
                register: 'openconnector',
                schema: 'synchronization_log'
            );
            if ($log === null) {
                return new JSONResponse(['error' => $this->l->t('Log not found or could not be deleted')], 404);
            }

            $this->orObjectService->deleteObject(uuid: $log->getUuid());
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Log not found or could not be deleted')], 404);
        }

        return new JSONResponse(['message' => $this->l->t('Log deleted successfully')]);

    }//end destroy()

    /**
     * Get log statistics.
     *
     * This method returns statistical information about synchronization logs.
     *
     * @return JSONResponse The statistics response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/logs-and-statistics/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function statistics(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        // Outside the try/catch below on purpose: that block catches
        // \Exception and answers 500, and OCSForbiddenException is an
        // \Exception — a refusal placed inside it would be reported as a
        // server error and would look like an outage rather than a denial.
        try {
            $this->actionAuth->requireAction(user: $user, action: 'log.statistics');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            // Get basic counts by level via OR ObjectService.
            $baseFilters    = ['register' => 'openconnector', 'schema' => 'synchronization_log'];
            $errorMatches   = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['level' => 'error'])]);
            $warningMatches = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['level' => 'warning'])]);
            $infoMatches    = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['level' => 'info'])]);
            $successMatches = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['level' => 'success'])]);
            $debugMatches   = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['level' => 'debug'])]);
            $errorCount     = ($errorMatches['total'] ?? count($errorMatches['results'] ?? $errorMatches));
            $warningCount   = ($warningMatches['total'] ?? count($warningMatches['results'] ?? $warningMatches));
            $infoCount      = ($infoMatches['total'] ?? count($infoMatches['results'] ?? $infoMatches));
            $successCount   = ($successMatches['total'] ?? count($successMatches['results'] ?? $successMatches));
            $debugCount     = ($debugMatches['total'] ?? count($debugMatches['results'] ?? $debugMatches));

            // Calculate level distribution.
            $levelDistribution = [
                'error'   => $errorCount,
                'warning' => $warningCount,
                'info'    => $infoCount,
                'success' => $successCount,
                'debug'   => $debugCount,
            ];

            return new JSONResponse(
                    [
                        'errorCount'        => $errorCount,
                        'warningCount'      => $warningCount,
                        'infoCount'         => $infoCount,
                        'successCount'      => $successCount,
                        'debugCount'        => $debugCount,
                        'levelDistribution' => $levelDistribution,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Could not fetch statistics')], 500);
        }//end try

    }//end statistics()

    /**
     * Export logs as CSV.
     *
     * This method exports synchronization logs as a CSV file.
     *
     * @param string|null $level             Filter by log level.
     * @param string|null $message           Search in log messages.
     * @param string|null $synchronizationId Filter by synchronization ID.
     * @param string|null $dateFrom          Filter logs from this date.
     * @param string|null $dateTo            Filter logs until this date.
     *
     * @return JSONResponse The export response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/specs/logs-and-statistics/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function export(
        ?string $level=null,
        ?string $message=null,
        ?string $synchronizationId=null,
        ?string $dateFrom=null,
        ?string $dateTo=null
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        // Outside the try/catch below on purpose — see statistics().
        try {
            $this->actionAuth->requireAction(user: $user, action: 'log.export');
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        try {
            // Build filters array (same as index method).
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

            // Get all logs matching filters (no pagination for export).
            $orFilters = array_merge(['register' => 'openconnector', 'schema' => 'synchronization_log'], $filters);
            $matches   = $this->orObjectService->findAll(config: ['filters' => $orFilters]);
            $logs      = ($matches['results'] ?? $matches);

            // Create CSV content.
            $csvData = "UUID,Level,Message,Synchronization ID,User ID,Session ID,Created,Expires\n";

            foreach ($logs as $log) {
                $data = $log->getObject();
                if (isset($data['created']) === true) {
                    $created = $data['created'];
                } else {
                    $created = '';
                }

                if (isset($data['expires']) === true) {
                    $expires = $data['expires'];
                } else {
                    $expires = '';
                }

                $csvData .= sprintf(
                    "%s,%s,%s,%s,%s,%s,%s,%s\n",
                    ($log->getUuid() ?? ''),
                    ($data['level'] ?? ''),
                    '"'.str_replace('"', '""', ($data['message'] ?? '')).'"',
                    ($data['synchronizationId'] ?? ''),
                    ($data['userId'] ?? ''),
                    ($data['sessionId'] ?? ''),
                    $created,
                    $expires
                );
            }//end foreach

            // Return CSV as response.
            return new JSONResponse(
                    [
                        'filename'    => 'synchronization_logs_'.date('Y-m-d_H-i-s').'.csv',
                        'content'     => $csvData,
                        'contentType' => 'text/csv',
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Could not export logs')], 500);
        }//end try

    }//end export()
}//end class
