<?php
/**
 * OpenConnector Settings Controller
 *
 * This file contains the controller class for handling settings endpoints in the OpenConnector application.
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

use OCA\OpenConnector\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling settings-related operations.
 *
 * Provides endpoints for retrieving system statistics and 
 * configuration information for the OpenConnector application.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class SettingsController extends Controller
{

    /**
     * The OpenRegister object service.
     *
     * @var \OCA\OpenRegister\Service\ObjectService|null The OpenRegister object service.
     */
    private ?\OCA\OpenRegister\Service\ObjectService $objectService = null;

    /**
     * SettingsController constructor.
     *
     * @param string             $appName         The name of the app
     * @param IRequest           $request         Request object
     * @param ContainerInterface $container       The container
     * @param IAppManager        $appManager      The app manager
     * @param IGroupManager      $groupManager    The group manager
     * @param SettingsService    $settingsService Settings service for business logic
     * @param LoggerInterface    $logger          Logger for error handling
     * @param IUserSession       $userSession     The user session
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly IGroupManager $groupManager,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly IL10N $l
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            return $this->objectService;
        }

        throw new \RuntimeException('OpenRegister service is not available.');

    }//end getObjectService()


    /**
     * Attempts to retrieve the Configuration service from the container.
     *
     * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
     * @throws \RuntimeException If the service is not available.
     */
    public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            return $configurationService;
        }

        throw new \RuntimeException('Configuration service is not available.');

    }//end getConfigurationService()


    /**
     * Get comprehensive database statistics for the settings dashboard.
     *
     * Returns counts and size information for all OpenConnector tables,
     * as well as warning counts for items requiring attention.
     *
     * @return JSONResponse JSON response containing statistics data
     * 
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function stats(): JSONResponse
    {
        try {
            $this->logger->debug('Statistics endpoint called', [
                'endpoint' => '/api/settings/stats',
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            $stats = $this->settingsService->getStats();

            $this->logger->debug('Statistics retrieved successfully', [
                'totalTables' => count($stats['totals']),
                'totalWarnings' => count($stats['warnings']),
                'executionTime' => microtime(true)
            ]);

            return new JSONResponse($stats);
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve statistics', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'error' => $this->l->t('Failed to retrieve statistics'),
                'message' => $e->getMessage()
            ], 500);
        }

    }//end stats()


    /**
     * Get the current settings including retention configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response containing the current settings.
     */
    public function getSettings(): JSONResponse
    {
        try {
            $this->logger->debug('Get settings endpoint called', [
                'endpoint' => '/api/settings',
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            $settings = $this->settingsService->getSettings();

            $user    = $this->userSession->getUser();
            $isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());

            $this->logger->debug('Settings retrieved successfully', [
                'hasRetention' => isset($settings['retention']),
                'executionTime' => microtime(true)
            ]);

            return new JSONResponse([
                'openRegisters' => in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()),
                'isAdmin'       => $isAdmin,
                'config'        => $settings,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve settings', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'error' => $this->l->t('Failed to retrieve settings'),
                'message' => $e->getMessage()
            ], 500);
        }

    }//end getSettings()


    /**
     * Update the settings configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response containing the updated settings.
     */
    public function updateSettings(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            $this->logger->debug('Update settings endpoint called', [
                'endpoint' => '/api/settings',
                'hasRetention' => isset($data['retention']),
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            $result = $this->settingsService->updateSettings($data);

            $this->logger->info('Settings updated successfully', [
                'updatedFields' => array_keys($data),
                'executionTime' => microtime(true)
            ]);

            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update settings', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'error' => $this->l->t('Failed to update settings'),
                'message' => $e->getMessage()
            ], 500);
        }

    }//end updateSettings()


    /**
     * Rebase all logs with current retention settings.
     *
     * This method recalculates deletion times for all logs based on current retention settings
     * using database operations for optimal performance.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response containing the rebase operation result.
     */
    public function rebase(): JSONResponse
    {
        try {
            $this->logger->info('Rebase endpoint called', [
                'endpoint' => '/api/settings/rebase',
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            $result = $this->settingsService->rebase();

            $this->logger->info('Rebase operation completed', [
                'success' => $result['success'] ?? false,
                'duration' => $result['duration'] ?? 'unknown',
                'errors' => count($result['errors'] ?? []),
                'results' => $result['retentionResults'] ?? []
            ]);

            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Failed to perform rebase operation', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JSONResponse([
                'error' => $this->l->t('Failed to perform rebase operation'),
                'message' => $e->getMessage()
            ], 500);
        }

    }//end rebase()


}//end class

