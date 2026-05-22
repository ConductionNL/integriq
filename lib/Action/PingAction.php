<?php

namespace OCA\OpenConnector\Action;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * This class is used to run the action tasks for the OpenConnector app. It hooks into the cron job list and runs the classes that are set as the job class in the job.
 *
 * @package OCA\OpenConnector\Cron
 */
class PingAction
{

    private CallService $callService;

    private OrObjectService $orObjectService;

    public function __construct(
        CallService $callService,
        OrObjectService $orObjectService,
    ) {
        $this->callService     = $callService;
        $this->orObjectService = $orObjectService;
    }//end __construct()

    /**
     * Executes a simple API-call (ping / GET) on a source by using the callService.
     * The method logs actions performed during execution and returns a stack trace of the operations.
     *
     * @todo Make this method more generic to support additional actions.
     * @todo Add logging or better handling for cases when 'sourceId' is not provided.
     *
     * @param array $arguments An array of arguments including optional 'sourceId' to define the source for the call.
     *
     * @return array An array containing the execution stack trace of the actions performed.
     */
    public function run(array $arguments=[]): array
    {
        $response = [];
        $response['stackTrace'][] = 'Running PingAction';

        // For now we only have one action, so this is a bit overkill, but it's a good starting point
        $sourceId = null;
        if (isset($arguments['sourceId']) === false || empty($arguments['sourceId'])) {
            // @todo log and / or not default to just using the first source
            $response['stackTrace'][] = "No sourceId in arguments, skipping ping";
            return $response;
        }

        $sourceId = (string) $arguments['sourceId'];
        $response['stackTrace'][] = "Found sourceId {$sourceId} in arguments";

        $source = $this->orObjectService->find(id: $sourceId, register: 'openconnector', schema: 'source');
        if ($source === null) {
            $response['stackTrace'][] = "Source not found for id: {$sourceId}";
            return $response;
        }

        $response['stackTrace'][] = "Calling callService...";
        $callLog = $this->callService->call($source);

        $response['stackTrace'][] = "Created callLog with uuid: ".$callLog->getUuid();

        // Let's report back about what we have just done
        return $response;
    }//end run()
}//end class
