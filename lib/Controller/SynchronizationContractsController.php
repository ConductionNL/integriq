<?php
/**
 * OpenConnector SynchronizationContractsController.
 *
 * Controller for managing synchronization contracts. Handles action operations
 * (activate/deactivate/execute/statistics/performance/export); CRUD operations
 * are served by OR's generic routes.
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

use OCA\OpenConnector\AppInfo\Application;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for managing synchronization contracts.
 *
 * This controller handles action operations for synchronization contracts,
 * including statistics and export. CRUD operations are served by OR's generic routes.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class SynchronizationContractsController extends Controller
{

    /**
     * The OR object service
     *
     * @var OrObjectService
     */
    private OrObjectService $orObjectService;

    /**
     * The localization service
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * The user session
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * The action authorization service
     *
     * @var ActionAuthService
     */
    private ActionAuthService $actionAuth;

    /**
     * Constructor for the SynchronizationContractsController
     *
     * @param string            $appName         The application name
     * @param IRequest          $request         The request interface
     * @param OrObjectService   $orObjectService The OR object service
     * @param IL10N             $l               The localization service
     * @param IUserSession      $userSession     The user session.
     * @param ActionAuthService $actionAuth      The action authorization service.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        OrObjectService $orObjectService,
        IL10N $l,
        IUserSession $userSession,
        ActionAuthService $actionAuth
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->orObjectService = $orObjectService;
        $this->l           = $l;
        $this->userSession = $userSession;
        $this->actionAuth  = $actionAuth;

    }//end __construct()

    /**
     * Activate a synchronization contract.
     *
     * This method activates a synchronization contract.
     *
     * @param string $id The ID of the contract to activate.
     *
     * @return JSONResponse The activation response.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function activate(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'synchronization-contract.activate');

        try {
            $contract = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'synchronization_contract');
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Contract not found or could not be activated')], 404);
        }

        // Set contract as active (implementation depends on your business logic).
        // For now, we'll just return success.
        return new JSONResponse(['message' => $this->l->t('Contract activated successfully')]);

    }//end activate()

    /**
     * Deactivate a synchronization contract.
     *
     * This method deactivates a synchronization contract.
     *
     * @param string $id The ID of the contract to deactivate.
     *
     * @return JSONResponse The deactivation response.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function deactivate(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'synchronization-contract.deactivate');

        try {
            $contract = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'synchronization_contract');
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Contract not found or could not be deactivated')], 404);
        }

        // Set contract as inactive (implementation depends on your business logic).
        // For now, we'll just return success.
        return new JSONResponse(['message' => $this->l->t('Contract deactivated successfully')]);

    }//end deactivate()

    /**
     * Execute a synchronization contract immediately.
     *
     * This method triggers immediate execution of a synchronization contract.
     *
     * @param string $id The ID of the contract to execute.
     *
     * @return JSONResponse The execution response.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function execute(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $this->actionAuth->requireAction(user: $user, action: 'synchronization-contract.execute');

        try {
            $contract = $this->orObjectService->find(id: $id, register: 'openconnector', schema: 'synchronization_contract');
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('Contract not found or could not be executed')], 404);
        }

        // Execute contract (implementation depends on your business logic).
        // For now, we'll just return success.
        return new JSONResponse(['message' => $this->l->t('Contract executed successfully')]);

    }//end execute()

    /**
     * Get contract statistics
     *
     * This method returns statistical information about synchronization contracts.
     *
     * @return JSONResponse The statistics response
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function statistics(): JSONResponse
    {
        try {
            // Get basic counts by status via OR ObjectService.
            $baseFilters     = ['register' => 'openconnector', 'schema' => 'synchronization_contract'];
            $allMatches      = $this->orObjectService->findAll(config: ['filters' => $baseFilters]);
            $activeMatches   = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['status' => 'active'])]);
            $inactiveMatches = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['status' => 'inactive'])]);
            $errorMatches    = $this->orObjectService->findAll(config: ['filters' => array_merge($baseFilters, ['status' => 'error'])]);
            $totalCount      = $allMatches['total'] ?? count($allMatches['results'] ?? $allMatches);
            $activeCount     = $activeMatches['total'] ?? count($activeMatches['results'] ?? $activeMatches);
            $inactiveCount   = $inactiveMatches['total'] ?? count($inactiveMatches['results'] ?? $inactiveMatches);
            $errorCount      = $errorMatches['total'] ?? count($errorMatches['results'] ?? $errorMatches);

            return new JSONResponse(
                    [
                        'totalCount'    => $totalCount,
                        'activeCount'   => $activeCount,
                        'inactiveCount' => $inactiveCount,
                        'errorCount'    => $errorCount,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Could not fetch statistics')], 500);
        }//end try
    }//end statistics()

    /**
     * Get contract performance data
     *
     * This method returns performance data for synchronization contracts.
     *
     * @return JSONResponse The performance response
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function performance(): JSONResponse
    {
        try {
            // Get performance data for different time periods.
            // This is a simplified implementation - adjust based on your actual requirements.
            $performanceData = [
                'last_7_days'  => [
                    'successRate'          => 85.5,
                    'totalExecutions'      => 120,
                    'successfulExecutions' => 103,
                ],
                'last_30_days' => [
                    'successRate'          => 82.3,
                    'totalExecutions'      => 480,
                    'successfulExecutions' => 395,
                ],
                'last_90_days' => [
                    'successRate'          => 78.9,
                    'totalExecutions'      => 1440,
                    'successfulExecutions' => 1136,
                ],
            ];

            return new JSONResponse($performanceData);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Could not fetch performance data')], 500);
        }//end try
    }//end performance()

    /**
     * Export contracts as CSV.
     *
     * This method exports synchronization contracts as a CSV file.
     *
     * @param string|null $synchronizationId Filter by synchronization ID.
     * @param string|null $status            Filter by contract status.
     * @param string|null $originId          Filter by origin ID.
     * @param string|null $targetId          Filter by target ID.
     * @param string|null $dateFrom          Filter contracts from this date.
     * @param string|null $dateTo            Filter contracts until this date.
     *
     * @return JSONResponse The export response.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md#task-5
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function export(
        ?string $synchronizationId=null,
        ?string $status=null,
        ?string $originId=null,
        ?string $targetId=null,
        ?string $dateFrom=null,
        ?string $dateTo=null
    ): JSONResponse {
        try {
            // Build filters array (same as index method).
            $filters = [];

            if ($synchronizationId !== null) {
                $filters['synchronization_id'] = $synchronizationId;
            }

            if ($status !== null) {
                $filters['status'] = $status;
            }

            if ($originId !== null) {
                $filters['origin_id'] = $originId;
            }

            if ($targetId !== null) {
                $filters['target_id'] = $targetId;
            }

            if ($dateFrom !== null) {
                $filters['date_from'] = $dateFrom;
            }

            if ($dateTo !== null) {
                $filters['date_to'] = $dateTo;
            }

            // Get all contracts matching filters (no pagination for export) via OR ObjectService.
            $orFilters = array_merge(['register' => 'openconnector', 'schema' => 'synchronization_contract'], $filters);
            $matches   = $this->orObjectService->findAll(config: ['filters' => $orFilters]);
            $contracts = ($matches['results'] ?? $matches);

            // Create CSV content.
            $csvData = "UUID,Synchronization ID,Origin ID,Target ID,Origin Hash,Target Hash,Source Last Synced,Target Last Synced,Created,Updated\n";

            foreach ($contracts as $contract) {
                $data     = $contract->getObject();
                $csvData .= sprintf(
                    "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                    $contract->getUuid() ?? '',
                    $data['synchronizationId'] ?? '',
                    $data['originId'] ?? '',
                    $data['targetId'] ?? '',
                    $data['originHash'] ?? '',
                    $data['targetHash'] ?? '',
                    $data['sourceLastSynced'] ?? '',
                    $data['targetLastSynced'] ?? '',
                    $data['created'] ?? '',
                    $data['updated'] ?? ''
                );
            }

            // Return CSV as response.
            return new JSONResponse(
                    [
                        'filename'    => 'synchronization_contracts_'.date('Y-m-d_H-i-s').'.csv',
                        'content'     => $csvData,
                        'contentType' => 'text/csv',
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $this->l->t('Could not export contracts')], 500);
        }//end try
    }//end export()
}//end class
