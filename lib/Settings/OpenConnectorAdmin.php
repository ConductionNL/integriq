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
use OCP\Settings\IDelegatedSettings;

/**
 * Admin settings panel for OpenConnector.
 *
 * Implements IDelegatedSettings (extends ISettings) so the form can be guarded
 * by #[AuthorizedAdminSetting(OpenConnectorAdmin::class)] on the controllers
 * that mutate OpenConnector configuration. NC's middleware resolves the
 * admin-gating from the class-string of the delegated settings section.
 */
class OpenConnectorAdmin implements IDelegatedSettings
{

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
     */
    public function __construct(IConfig $config)
    {
        $this->config = $config;

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

    /**
     * Human-readable name of the delegated settings section.
     *
     * @return string|null The section name, or null to use the section default.
     */
    public function getName(): ?string
    {
        return null;

    }//end getName()

    /**
     * App config keys an authorized (delegated) admin may manage.
     *
     * Returned as a map of appId => list of allowed config keys. OpenConnector
     * exposes no delegatable sub-keys yet, so this is intentionally empty; the
     * attribute still scopes the endpoints to full admins.
     *
     * @return array<string,string[]> Map of appId to allowed config keys.
     */
    public function getAuthorizedAppConfig(): array
    {
        return [];

    }//end getAuthorizedAppConfig()
}//end class
