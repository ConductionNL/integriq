<?php

/**
 * RegisterConnectorExtensions
 *
 * @category  Listener
 * @package   OCA\IntegriqExampleChannel\Listener
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\IntegriqExampleChannel\Listener;

use OCA\Integriq\Event\RegisterConnectorExtensionsEvent;
use OCA\IntegriqExampleChannel\AppInfo\Application;
use OCA\IntegriqExampleChannel\Intake\WebhookChannel;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds this app's intake channel when IntegrIQ collects its extensions.
 *
 * @template-implements IEventListener<Event>
 */
class RegisterConnectorExtensions implements IEventListener {

	/**
	 * Contribute the example channel under this app's id.
	 *
	 * @param Event $event The collect event.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterConnectorExtensionsEvent) === false) {
			return;
		}

		$event->addIntakeChannel(adapter: new WebhookChannel(), appId: Application::APP_ID);
	}//end handle()
}//end class
