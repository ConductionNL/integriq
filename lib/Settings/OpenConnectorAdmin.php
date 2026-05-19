<?php
namespace OCA\OpenConnector\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class OpenConnectorAdmin implements ISettings {
	private IL10N $l;
	private IConfig $config;

	public function __construct(IConfig $config, IL10N $l) {
		$this->config = $config;
		$this->l = $l;
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm() {
		$parameters = [
			'mySetting' => $this->config->getSystemValue('open_connector_setting', true),
		];

		return new TemplateResponse('openconnector', 'settings/admin', $parameters, '');
	}

	public function getSection() {
		return 'openconnector'; // Name of the previously created section.
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of
	 * the admin section. The forms are arranged in ascending order of the
	 * priority values. It is required to return a value between 0 and 100.
	 *
	 * E.g.: 70
	 */
	public function getPriority() {
		return 10;
	}
}
