<?php

/**
 * The binding that was deliberately not guessed.
 *
 * 🔑 THE HANDLER WAS BUILT FIRST AND WAITED, WHICH WAS THE RIGHT ORDER.
 * `SubscriptionRequestHandler` takes the request as a payload, whatever carried
 * it, so none of its logic depended on the wire shape and all of it was testable
 * before the event existed. This listener is the one line that was blocked.
 *
 * 🔴 AND IT MUST NOT TAKE DOWN THE WRITE THAT ASKED FOR IT. The event is
 * dispatched inside OpenRegister's own work, so a subscription integriq cannot
 * fulfil has to fail as a subscription that did not happen, not as a save that
 * failed for a reason the user cannot act on.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\RegistrySubscriptionRequestedListener;
use OCA\Integriq\Service\Registry\SubscriptionRequestHandler;
use OCA\OpenRegister\Event\RegistrySubscriptionRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * `RegistrySubscriptionRequestedListener`.
 *
 * @covers \OCA\Integriq\EventListener\RegistrySubscriptionRequestedListener
 */
class RegistrySubscriptionRequestedListenerTest extends TestCase {

	/**
	 * The event OpenRegister dispatches.
	 *
	 * @return RegistrySubscriptionRequestedEvent The event.
	 */
	private function requestEvent(): RegistrySubscriptionRequestedEvent {
		return new RegistrySubscriptionRequestedEvent(
			'object-1',
			'register-1',
			'schema-1',
			'brp',
			'999999011'
		);
	}//end requestEvent()

	/**
	 * 🔑 THE EVENT'S OWN PAYLOAD IS WHAT THE HANDLER RECEIVES.
	 *
	 * Reading `getPayload()` rather than assembling one from the getters keeps
	 * the wire shape defined ONCE, on the side that emits it. Assembling it
	 * here would be a second definition, and the two would drift the first time
	 * OpenRegister added a field.
	 *
	 * @return void
	 */
	public function testTheEventsOwnPayloadReachesTheHandler(): void {
		$handler = $this->createMock(SubscriptionRequestHandler::class);
		$handler->expects($this->once())
			->method('handle')
			->with(
				$this->callback(
					static function (array $request): bool {
						return ($request['registry'] === 'brp'
							&& $request['identityValue'] === '999999011'
							&& $request['objectUuid'] === 'object-1');
					}
				)
			)
			->willReturn(null);

		(new RegistrySubscriptionRequestedListener($handler, new NullLogger()))
			->handle($this->requestEvent());
	}//end testTheEventsOwnPayloadReachesTheHandler()

	/**
	 * 🔴 A HANDLER THAT THROWS DOES NOT TAKE DOWN THE WRITE THAT ASKED.
	 *
	 * An unreachable registry, a timeout, a provider that raises: none of those
	 * are the fault of the person saving the object, and none of them should
	 * make their save fail.
	 *
	 * @return void
	 */
	public function testAFailingHandlerDoesNotEscape(): void {
		$handler = $this->createMock(SubscriptionRequestHandler::class);
		$handler->method('handle')->willThrowException(new RuntimeException('registry unreachable'));

		$listener = new RegistrySubscriptionRequestedListener($handler, new NullLogger());

		$listener->handle($this->requestEvent());

		// Reaching here IS the assertion: the throw was contained.
		$this->assertTrue(true);
	}//end testAFailingHandlerDoesNotEscape()

	/**
	 * A request the handler declines is not an error.
	 *
	 * The handler returns null for a registry it does not know, having already
	 * logged why. Treating that as a failure here would double every line.
	 *
	 * @return void
	 */
	public function testADeclinedRequestIsNotAnError(): void {
		$handler = $this->createMock(SubscriptionRequestHandler::class);
		$handler->method('handle')->willReturn(null);

		$listener = new RegistrySubscriptionRequestedListener($handler, new NullLogger());

		$listener->handle($this->requestEvent());

		$this->assertTrue(true);
	}//end testADeclinedRequestIsNotAnError()

	/**
	 * Another event is ignored.
	 *
	 * The control: a listener that handled anything it was given would pass the
	 * tests above while subscribing on events that are not subscription
	 * requests.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$handler = $this->createMock(SubscriptionRequestHandler::class);
		$handler->expects($this->never())->method('handle');

		(new RegistrySubscriptionRequestedListener($handler, new NullLogger()))
			->handle(new Event());
	}//end testAnUnrelatedEventIsIgnored()
}//end class
