<?php

/**
 * What the roster import listener answers and logs for each delivery outcome.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\RosterImportRequestedEvent;
use OCA\Integriq\EventListener\RosterImportRequestedListener;
use OCA\Integriq\Sources\Roster\RosterDeliveryException;
use OCA\Integriq\Sources\Roster\RosterDeliveryServiceInterface;
use OCA\Integriq\Sources\Roster\RosterTargetConfigurationInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The listener hands the request to the delivery service and always answers.
 */
class RosterImportRequestedListenerTest extends TestCase {

	/**
	 * A delivery's result is the event's result, as the service gives it.
	 *
	 * @return void
	 */
	public function testADeliveryIsTheResult(): void {
		$delivery = $this->createMock(RosterDeliveryServiceInterface::class);
		$delivery->expects($this->once())->method('deliver')
			->with('somtoday', ['groupMap' => ['3a' => 'c']], 'job-1')
			->willReturn(['status' => 'delivered', 'count' => 3]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$event = new RosterImportRequestedEvent('learniq', 'somtoday', ['groupMap' => ['3a' => 'c']], 'job-1');
		(new RosterImportRequestedListener($delivery, $logger))->handle($event);

		$this->assertSame(['status' => 'delivered', 'count' => 3], $event->getResult());

	}//end testADeliveryIsTheResult()

	/**
	 * A refused delivery answers its own error code, any other failure
	 * `fetch-failed`, both logged with the app and the source.
	 *
	 * @return void
	 */
	public function testAFailedDeliveryIsAnsweredAndLogged(): void {
		$delivery = $this->createMock(RosterDeliveryServiceInterface::class);
		$delivery->method('deliver')->willReturnOnConsecutiveCalls(
			$this->throwException(new RosterDeliveryException('no-preset', 'no preset for somtoday')),
			$this->throwException(new RuntimeException('timeout'))
		);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
			$logged[] = [$message, $context];
		});
		$listener = new RosterImportRequestedListener($delivery, $logger);

		$refused = new RosterImportRequestedEvent('learniq', 'somtoday');
		$listener->handle($refused);
		$failed = new RosterImportRequestedEvent('learniq', 'magister');
		$listener->handle($failed);

		$answer = static fn (string $system, string $code, string $error): array => [
			'contractVersion' => RosterImportRequestedEvent::CONTRACT_VERSION,
			'status' => 'failed',
			'systemId' => $system,
			'target' => RosterTargetConfigurationInterface::TARGET,
			'errorCode' => $code,
			'error' => $error,
		];
		$this->assertSame($answer('somtoday', 'no-preset', 'no preset for somtoday'), $refused->getResult());
		$this->assertSame($answer('magister', 'fetch-failed', 'timeout'), $failed->getResult());
		$this->assertSame(
			[
				['roster-delivery.failed', ['app' => 'learniq', 'source' => 'somtoday', 'errorCode' => 'no-preset', 'error' => 'no preset for somtoday']],
				['roster-delivery.failed', ['app' => 'learniq', 'source' => 'magister', 'errorCode' => 'fetch-failed', 'error' => 'timeout']],
			],
			$logged
		);

	}//end testAFailedDeliveryIsAnsweredAndLogged()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$delivery = $this->createMock(RosterDeliveryServiceInterface::class);
		$delivery->expects($this->never())->method('deliver');

		(new RosterImportRequestedListener($delivery, $this->createMock(LoggerInterface::class)))->handle(new Event());

	}//end testAnotherEventIsIgnored()
}//end class
