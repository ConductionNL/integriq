<?php

namespace OCA\OpenConnector\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class ConsumersController extends Controller
{
    /**
     * Constructor for the ConsumerController
     *
     * @param string     $appName The name of the app
     * @param IRequest   $request The request object
     * @param IAppConfig $config  The app configuration object
     * @param IL10N      $l       The localization service
     */
    public function __construct(
        $appName,
        IRequest $request,
        private IAppConfig $config,
        private IL10N $l
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Returns the template of the main app's page
     *
     * This method renders the main page of the application, adding any necessary data to the template.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse The rendered template response
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
