<?php
/**
 * OpenConnector Event Action.
 *
 * Placeholder action that can be hooked into the cron job list and invoked
 * when an event-driven job fires.
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

/**
 * Runs event-driven actions wired into the OpenConnector cron job list.
 */
class EventAction
{

    /**
     * Service used to execute outbound calls.
     *
     * @var CallService
     */
    private CallService $callService;

    /**
     * Constructor.
     *
     * @param CallService $callService Service used to execute outbound calls.
     */
    public function __construct(
        CallService $callService,
    ) {
        $this->callService = $callService;

    }//end __construct()

    /**
     * Run the event action.
     *
     * @param array $argument The arguments for the action.
     *
     * @return array The result of the action.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function run(array $argument=[]): array
    {
        // @todo Implement this.
        // Let's report back about what we have just done.
        return [];

    }//end run()
}//end class
