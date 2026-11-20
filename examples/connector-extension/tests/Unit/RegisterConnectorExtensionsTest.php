<?php

/**
 * The listener contributes the channel under this app's id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\IntegriqExampleChannel\Tests\Unit;

use OCA\Integriq\Event\RegisterConnectorExtensionsEvent;
use OCA\IntegriqExampleChannel\AppInfo\Application;
use OCA\IntegriqExampleChannel\Intake\WebhookChannel;
use OCA\IntegriqExampleChannel\Listener\RegisterConnectorExtensions;
use PHPUnit\Framework\TestCase;

/**
 * The collect event, as IntegrIQ dispatches it.
 */
final class RegisterConnectorExtensionsTest extends TestCase {

	/**
	 * The channel is added once, with this app's id.
	 *
	 * @return void
	 */
	public function testTheChannelIsContributedUnderThisApp(): void {
		$event = new RegisterConnectorExtensionsEvent();
		(new RegisterConnectorExtensions())->handle($event);

		$channels = $event->getIntakeChannels();
		$this->assertCount(1, $channels);
		$this->assertInstanceOf(WebhookChannel::class, $channels[0]['adapter']);
		$this->assertSame(Application::APP_ID, $channels[0]['appId']);
		$this->assertSame([], $event->getPaymentProviders());
	}//end testTheChannelIsContributedUnderThisApp()
}//end class
