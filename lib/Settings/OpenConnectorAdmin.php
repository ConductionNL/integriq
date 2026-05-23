<?php
/**
 * OpenConnector Admin Settings.
 *
 * Renders the admin settings form for the OpenConnector application.
 *
 * @category Settings
 * @package  OCA\OpenConnector\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

/**
 * Admin settings panel for OpenConnector.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class OpenConnectorAdmin implements ISettings
{

    /**
     * Localisation service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * Nextcloud config service.
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Constructor.
     *
     * @param IConfig $config Nextcloud config service.
     * @param IL10N   $l      Localisation service.
     */
    public function __construct(IConfig $config, IL10N $l)
    {
        $this->config = $config;
        $this->l      = $l;

    }//end __construct()

    /**
     * Render the admin settings form.
     *
     * @return TemplateResponse
     */
    public function getForm()
    {
        $parameters = [
            'mySetting' => $this->config->getSystemValue('open_connector_setting', true),
        ];

        return new TemplateResponse('openconnector', 'settings/admin', $parameters, '');

    }//end getForm()

    /**
     * Return the section identifier where this settings panel belongs.
     *
     * @return string
     */
    public function getSection()
    {
        // Name of the previously created section.
        return 'openconnector';

    }//end getSection()

    /**
     * Return the form priority within the admin section.
     *
     * Forms are arranged in ascending order of the priority values. The
     * returned value must be between 0 and 100.
     *
     * @return int
     */
    public function getPriority()
    {
        return 10;

    }//end getPriority()
}//end class
