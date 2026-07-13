<?php
/**
 * OpenConnector Ping Action.
 *
 * Cron action that executes a simple GET / ping call against a configured
 * source and records the call log entry.
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

use OCA\OpenConnector\Service\CallService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Runs ping actions wired into the OpenConnector cron job list.
 */
class PingAction
{

    /**
     * Service used to execute outbound calls.
     *
     * @var CallService
     */
    private CallService $callService;

    /**
     * OpenRegister object service used to resolve sources.
     *
     * @var OrObjectService
     */
    private OrObjectService $orObjectService;

    /**
     * Constructor.
     *
     * @param CallService     $callService     Service used to execute outbound calls.
     * @param OrObjectService $orObjectService Service used to resolve sources.
     */
    public function __construct(
        CallService $callService,
        OrObjectService $orObjectService,
    ) {
        $this->callService     = $callService;
        $this->orObjectService = $orObjectService;

    }//end __construct()

    /**
     * Executes a simple API-call (ping / GET) on a source by using the callService.
     *
     * The method logs actions performed during execution and returns a stack trace of the operations.
     *
     * @param array $arguments An array of arguments including optional 'sourceId' to define the source for the call.
     *
     * @return array An array containing the execution stack trace of the actions performed.
     *
     * @todo Make this method more generic to support additional actions.
     * @todo Add logging or better handling for cases when 'sourceId' is not provided.
     */
    public function run(array $arguments=[]): array
    {
        $response = [];
        $response['stackTrace'][] = 'Running PingAction';

        // For now we only have one action, so this is a bit overkill, but it is a good starting point.
        $sourceId = null;
        if (isset($arguments['sourceId']) === false || empty($arguments['sourceId']) === true) {
            // @todo Log and / or not default to just using the first source.
            $response['stackTrace'][] = "No sourceId in arguments, skipping ping";
            return $response;
        }

        $sourceId = (string) $arguments['sourceId'];
        $response['stackTrace'][] = "Found sourceId {$sourceId} in arguments";

        // System context (ocon#147): this runs as a scheduled action, where there is no
        // session at all — and the `source` schema is admin-only now.
        $source = $this->orObjectService->find(
            id: $sourceId,
            register: 'openconnector',
            schema: 'source',
            _rbac: false,
            _multitenancy: false
        );
        if ($source === null) {
            $response['stackTrace'][] = "Source not found for id: {$sourceId}";
            return $response;
        }

        $response['stackTrace'][] = "Calling callService...";
        $callLog = $this->callService->call($source);

        $response['stackTrace'][] = "Created callLog with uuid: ".$callLog->getUuid();

        // Let's report back about what we have just done.
        return $response;

    }//end run()
}//end class
