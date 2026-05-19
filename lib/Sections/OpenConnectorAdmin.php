<?php
namespace OCA\OpenConnector\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class OpenConnectorAdmin implements IIconSection {
    private IL10N $l;
    private IURLGenerator $urlGenerator;

    public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('openconnector', 'app-dark.svg');
    }

    public function getID(): string {
        return 'openconnector';
    }

    public function getName(): string {
        return $this->l->t('Open Connector');
    }

    public function getPriority(): int {
        return 97;
    }
}