<?php
/**
 * OpenConnector Synchronization Action.
 *
 * Cron action that runs a synchronization. Post OpenRegister-cutover the
 * synchronization is resolved through SynchronizationService::getSynchronization()
 * (backed by OpenRegister, register `openconnector`, schema `synchronization`),
 * the legacy SynchronizationMapper having been removed.
 *
 * @category Action
 * @package  OCA\OpenConnector\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Action;

use Exception;
use OCA\OpenConnector\Service\SynchronizationService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * This action handles the synchronization of data from the source to the target.
 *
 * @package OCA\OpenConnector\Action
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class SynchronizationAction
{

    /**
     * The synchronization service that runs the synchronization.
     *
     * @var SynchronizationService
     */
    private SynchronizationService $synchronizationService;

    /**
     * Constructor.
     *
     * @param SynchronizationService $synchronizationService The synchronization service.
     */
    public function __construct(
        SynchronizationService $synchronizationService,
    ) {
        $this->synchronizationService = $synchronizationService;

    }//end __construct()

    /**
     * Executes the synchronization process based on the provided arguments.
     *
     * This method checks for a valid synchronization ID and performs the
     * synchronization action, returning a stack trace of operations performed.
     *
     * @param array $argument An array of arguments that can include 'synchronizationId' and 'force'.
     *
     * @return array Returns an array containing the stack trace of actions performed and any warnings or messages.
     *
     * @throws Exception Throws an exception if the synchronization process fails or encounters an error.
     */
    public function run(array $argument=[]): array
    {
        $response = [];

        // If we do not have a synchronization id then everything is wrong.
        $response['message'] = $response['stackTrace'][] = 'Check for a valid synchronization ID';
        if (isset($argument['synchronizationId']) === false) {
            $response['level']        = 'ERROR';
            $response['stackTrace'][] = $response['message'] = 'No synchronization ID provided';

            return $response;
        }

        // Resolve the synchronization (OpenRegister ids are UUID strings).
        $response['stackTrace'][] = 'Getting synchronization: '.$argument['synchronizationId'];
        try {
            $synchronization = $this->synchronizationService->getSynchronization(id: (string) $argument['synchronizationId']);
        } catch (DoesNotExistException $e) {
            $response['level']        = 'WARNING';
            $response['stackTrace'][] = $response['message'] = 'Synchronization not found: '.$argument['synchronizationId'];

            return $response;
        }

        $force = filter_var(($argument['force'] ?? false), FILTER_VALIDATE_BOOLEAN);
        if ($force === true) {
            $response['stackTrace'][] = 'Force enabled for synchronization job';
        }

        // Run the synchronization.
        $response['stackTrace'][] = 'Doing the synchronization';
        try {
            $objects = $this->synchronizationService->synchronize(
                synchronization: $synchronization,
                force: $force
            );
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
        }//end try

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

        // Report back about what we have just done.
        return $response;

    }//end run()
}//end class
