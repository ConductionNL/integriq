<?php
/**
 * OpenConnector Admin Section.
 *
 * Registers the OpenConnector admin section in the Nextcloud settings UI.
 *
 * @category Sections
 * @package  OCA\OpenConnector\Sections
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Admin section provider for OpenConnector.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class OpenConnectorAdmin implements IIconSection
{

    /**
     * Localisation service.
     *
     * @var IL10N
     */
    private IL10N $l;

    /**
     * URL generator service.
     *
     * @var IURLGenerator
     */
    private IURLGenerator $urlGenerator;

    /**
     * Constructor.
     *
     * @param IL10N         $l            Localisation service.
     * @param IURLGenerator $urlGenerator URL generator service.
     */
    public function __construct(IL10N $l, IURLGenerator $urlGenerator)
    {
        $this->l            = $l;
        $this->urlGenerator = $urlGenerator;

    }//end __construct()

    /**
     * Return the section icon URL.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('openconnector', 'app-dark.svg');

    }//end getIcon()

    /**
     * Return the section identifier.
     *
     * @return string
     */
    public function getID(): string
    {
        return 'openconnector';

    }//end getID()

    /**
     * Return the section display name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->l->t('Open Connector');

    }//end getName()

    /**
     * Return the section priority within the settings UI.
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 97;

    }//end getPriority()
}//end class
