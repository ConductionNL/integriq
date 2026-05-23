<?php
/**
 * OpenConnector Consumers Controller.
 *
 * Placeholder controller that renders the consumers tab of the OpenConnector
 * Vue UI.
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

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller backing the consumers tab in the OpenConnector UI.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class ConsumersController extends Controller
{
    /**
     * Constructor for the ConsumerController.
     *
     * @param string     $appName The name of the app.
     * @param IRequest   $request The request object.
     * @param IAppConfig $config  The app configuration object.
     * @param IL10N      $l       The localization service.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private IAppConfig $config,
        private IL10N $l
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Returns the template of the main app's page.
     *
     * This method renders the main page of the application, adding any
     * necessary data to the template.
     *
     * @return TemplateResponse The rendered template response.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            'openconnector',
            'index',
            []
        );

    }//end page()
}//end class
