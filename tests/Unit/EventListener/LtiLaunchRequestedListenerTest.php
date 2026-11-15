<?php

/**
 * LtiLaunchRequestedListener Unit Tests
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\LtiLaunchRequestedEvent;
use OCA\Integriq\EventListener\LtiLaunchRequestedListener;
use OCA\Integriq\Service\Lti\LtiPlatformLoginServiceInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A launch request reaches the login service; a failure is logged by class only and refused without its detail.
 *
 * @covers \OCA\Integriq\EventListener\LtiLaunchRequestedListener
 * @uses   \OCA\Integriq\Event\LtiLaunchRequestedEvent
 */
class LtiLaunchRequestedListenerTest extends TestCase {

	private LtiPlatformLoginServiceInterface&MockObject $login;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->login  = $this->createMock(LtiPlatformLoginServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A launch request from learniq.
	 *
	 * @return LtiLaunchRequestedEvent
	 */
	private function launch(): LtiLaunchRequestedEvent {
		return new LtiLaunchRequestedEvent(sourceApp: 'learniq', placementId: 'pl-1', deploymentUuid: 'dep-1', userId: 'alice');
	}//end launch()

	public function testTheLaunchIsHandedToTheLoginService(): void {
		$event = $this->launch();
		$this->login->expects($this->once())->method('initiateLogin')->with($this->identicalTo($event));
		$this->logger->expects($this->never())->method('error');

		(new LtiLaunchRequestedListener($this->login, $this->logger))->handle($event);

		$this->assertNull($event->getRefusal());
	}//end testTheLaunchIsHandedToTheLoginService()

	public function testAFailedInitiationIsLoggedWithoutItsMessageAndRefused(): void {
		$event = $this->launch();
		$this->login->method('initiateLogin')->willThrowException(new RuntimeException('private key secret-123 unreadable'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'LtiLaunchRequestedListener: launch initiation failed (RuntimeException)',
				['deployment' => 'dep-1', 'sourceApp' => 'learniq']
			);

		(new LtiLaunchRequestedListener($this->login, $this->logger))->handle($event);

		$this->assertSame(
			['code' => 'launch-failed', 'reason' => 'The launch could not be started; see the server log'],
			$event->getRefusal()
		);
	}//end testAFailedInitiationIsLoggedWithoutItsMessageAndRefused()

	public function testOtherEventsAreIgnored(): void {
		$this->login->expects($this->never())->method('initiateLogin');

		(new LtiLaunchRequestedListener($this->login, $this->logger))->handle(new Event());
	}//end testOtherEventsAreIgnored()
}//end class
