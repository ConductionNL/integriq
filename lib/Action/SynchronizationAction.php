<?php
/**
 * OpenConnector Synchronization Action.
 *
 * Cron action that runs the synchronization process for a given
 * synchronization ID, delegating to the SynchronizationService.
 *
 * @category Action
 * @package  OCA\OpenConnector\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Action;

use Exception;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Handles the synchronization of data from a source to the target.
 */
class SynchronizationAction
{

    /**
     * Synchronization service used to perform the actual sync.
     *
     * @var SynchronizationService
     */
    private SynchronizationService $syncService;

    /**
     * OpenRegister object service used to resolve synchronization records.
     *
     * @var OrObjectService
     */
    private OrObjectService $orObjectService;

    /**
     * Constructor.
     *
     * @param SynchronizationService $syncService     Synchronization service used to perform the sync.
     * @param OrObjectService        $orObjectService Service used to resolve synchronization records.
     */
    public function __construct(
        SynchronizationService $syncService,
        OrObjectService $orObjectService,
    ) {
        $this->syncService     = $syncService;
        $this->orObjectService = $orObjectService;

    }//end __construct()

    /**
     * Executes the synchronization process based on the provided arguments.
     *
     * This method checks for a valid synchronization ID, processes a synchronization contract if provided,
     * or performs a general synchronization action. It returns a stack trace of operations performed.
     *
     * @param array $argument An array of arguments that can include 'synchronizationId' and 'synchronizationContractId'.
     *
     * @return array Returns an array containing the stack trace of actions performed and any warnings or messages.
     *
     * @throws Exception Throws an exception if the synchronization process fails or encounters an error.
     *
     * @todo Make this method more generic to handle different synchronization processes.
     * @todo Implement proper error handling when 'synchronizationId' is missing or invalid.
     * @todo Improve handling for testing purposes and synchronization contract logic.
     */
    public function run(array $argument=[]): array
    {
        $response = [];

        // If we do not have a synchronization Id then everything is wrong.
        $response['message'] = $response['stackTrace'][] = 'Check for a valid synchronization ID';
        if (isset($argument['synchronizationId']) === false) {
            // @todo Implement error handling.
            $response['level']        = 'ERROR';
            $response['stackTrace'][] = $response['message'] = 'No synchronization ID provided';

            return $response;
        }

        // Let's find a synchronization.
        $response['stackTrace'][] = 'Getting synchronization: '.$argument['synchronizationId'];
        $synchronization          = $this->orObjectService->find(
            id: (string) $argument['synchronizationId'],
            register: 'openconnector',
            schema: 'synchronization'
        );

        if ($synchronization === null) {
            $response['level']        = 'WARNING';
            $response['stackTrace'][] = $response['message'] = 'Synchronization not found: '.$argument['synchronizationId'];
            return $response;
        }

        // Doing the synchronization.
        $response['stackTrace'][] = 'Doing the synchronization';
        try {
            $objects = $this->syncService->synchronize($synchronization);
        } catch (TooManyRequestsHttpException $e) {
            $response['level']        = 'WARNING';
            $response['stackTrace'][] = $response['message'] = 'Stopped synchronization: '.$e->getMessage();
            if (isset($e->getHeaders()['X-RateLimit-Reset']) === true) {
                $response['nextRun']      = $e->getHeaders()['X-RateLimit-Reset'];
                $response['stackTrace'][] = 'Returning X-RateLimit-Reset header to update Job nextRun: '.$response['nextRun'];
            }

            return $response;
        } catch (Exception $e) {
            $response['level']        = 'ERROR';
            $response['stackTrace'][] = $response['message'] = 'Failed to synchronize: '.$e->getMessage();
            return $response;
        }

        $response['level'] = 'INFO';

        $objectCount = 0;
        if (is_array($objects) === true) {
            if (empty($objects['result']['contracts']) === false) {
                $objectCount = count($objects['result']['contracts']);
            } else {
                $objectCount = $objects['result']['objects']['found'];
            }
        }

        $response['stackTrace'][] = $response['message'] = 'Synchronized '.$objectCount.' successfully';

        // Let's report back about what we have just done.
        return $response;

    }//end run()
}//end class
