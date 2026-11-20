<?php

/**
 * Application of the example channel.
 *
 * @category  AppInfo
 * @package   OCA\IntegriqExampleChannel\AppInfo
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\IntegriqExampleChannel\AppInfo;

use OCA\Integriq\Event\RegisterConnectorExtensionsEvent;
use OCA\IntegriqExampleChannel\Listener\RegisterConnectorExtensions;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the one listener that contributes this app's channel to IntegrIQ.
 */
class Application extends App implements IBootstrap {

	public const APP_ID = 'integriq_example_channel';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}//end __construct()

	/**
	 * Listen for IntegrIQ's collect event. IntegrIQ dispatches it once per
	 * process, the first time one of its registries is used.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(RegisterConnectorExtensionsEvent::class, RegisterConnectorExtensions::class);
	}//end register()

	/**
	 * Nothing to do at boot.
	 *
	 * @param IBootContext $context The boot context.
	 *
	 * @return void
	 */
	public function boot(IBootContext $context): void {
	}//end boot()
}//end class
