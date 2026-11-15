<?php

/**
 * Unit tests for SubmissionRegistrationListener and its event.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\SubmissionRegistrationRequestedEvent;
use OCA\Integriq\EventListener\SubmissionRegistrationListener;
use OCA\Integriq\Service\Registration\RegistrationService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The listener writes the service's answer on the command.
 */
#[CoversClass(SubmissionRegistrationListener::class)]
#[CoversClass(SubmissionRegistrationRequestedEvent::class)]
class SubmissionRegistrationListenerTest extends TestCase {

	/**
	 * The command is answered with what the service registered.
	 *
	 * @return void
	 */
	public function testTheCommandIsAnswered(): void {
		$service = $this->createMock(RegistrationService::class);
		$service->expects($this->once())->method('register')
			->with('portaliq', 'zgw-milieu', ['reference' => 'OF-1'])
			->willReturn(['ok' => true, 'externalReference' => 'ZAAK-1', 'externalUrl' => 'u']);
		$event = new SubmissionRegistrationRequestedEvent(app: 'portaliq', target: 'zgw-milieu', handover: ['reference' => 'OF-1']);
		$this->assertFalse($event->isHandled());

		(new SubmissionRegistrationListener($service))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame(['ok' => true, 'externalReference' => 'ZAAK-1', 'externalUrl' => 'u'], $event->getResult());
		$this->assertSame(['portaliq', 'zgw-milieu', ['reference' => 'OF-1']], [$event->getApp(), $event->getTarget(), $event->getHandover()]);
	}//end testTheCommandIsAnswered()

	/**
	 * Another event is left alone.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$service = $this->createMock(RegistrationService::class);
		$service->expects($this->never())->method('register');

		(new SubmissionRegistrationListener($service))->handle(new Event());
	}//end testAnotherEventIsIgnored()
}//end class
