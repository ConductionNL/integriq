<?php

namespace OCA\OpenConnector\Dashboard;

use OCA\OpenConnector\AppInfo\Application;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class RecentCallsWidget implements IWidget
{


    public function __construct(
        private IL10N $l10n,
        private IURLGenerator $url
    ) {

    }//end __construct()


    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'openconnector_recent_calls_widget';

    }//end getId()


    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return $this->l10n->t('Recente calls');

    }//end getTitle()


    /**
     * @inheritDoc
     */
    public function getOrder(): int
    {
        return 11;

    }//end getOrder()


    /**
     * @inheritDoc
     */
    public function getIconClass(): string
    {
        return 'icon-openconnector-widget';

    }//end getIconClass()


    /**
     * @inheritDoc
     */
    public function getUrl(): ?string
    {
        return null;

    }//end getUrl()


    /**
     * @inheritDoc
     */
    public function load(): void
    {
        Util::addScript(Application::APP_ID, Application::APP_ID.'-recentCallsWidget');
        Util::addStyle(Application::APP_ID, 'dashboardWidgets');

    }//end load()


}//end class
