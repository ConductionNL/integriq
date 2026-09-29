<?php

/**
 * Tests for GatewayDeliveryRequestedListener.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-sibling-app-sends-through-a-gateway-with-a-typed-event-req-sg-010
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\GatewayDeliveryRequestedEvent;
use OCA\Integriq\EventListener\GatewayDeliveryRequestedListener;
use OCA\Integriq\Gateway\Adapter\CorvGateway;
use OCA\Integriq\Gateway\Adapter\GgkGateway;
use OCA\Integriq\Gateway\Adapter\PublicationGateway;
use OCA\Integriq\Gateway\Adapter\WkpbGateway;
use OCA\Integriq\Gateway\GatewayDelivery;
use OCA\Integriq\Gateway\GatewayTransport;
use OCA\Integriq\PropertySource\PropertySourceResolver;
use OCA\Integriq\PropertySource\ResolvedValue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A sibling sends through a statutory gateway by event. The adapters are the
 * real classes; only the wire (GatewayTransport) and the BAG lookup are doubled.
 */
class GatewayDeliveryRequestedListenerTest extends TestCase {

	/**
	 * The wire.
	 *
	 * @var GatewayTransport&MockObject
	 */
	private GatewayTransport $transport;

	/**
	 * The listener over the real adapters.
	 *
	 * @var GatewayDeliveryRequestedListener
	 */
	private GatewayDeliveryRequestedListener $listener;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->transport = $this->createMock(GatewayTransport::class);
		$resolver = $this->getMockBuilder(PropertySourceResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['suggest', 'resolve', 'resolveSuggestion', 'manual'])
			->getMock();
		$resolver->method('resolve')->willReturn(
			ResolvedValue::fromSource(['street' => 'Kerkstraat'], 'bag', 'adres-1', 1758182400)
		);

		$this->listener = new GatewayDeliveryRequestedListener(
			corv: new CorvGateway($this->transport),
			ggk: new GgkGateway($this->transport),
			wkpb: new WkpbGateway($this->transport, $resolver),
			publication: new PublicationGateway($this->transport),
		);

	}//end setUp()

	/**
	 * Dispatch a request.
	 *
	 * @param string $gateway The gateway id.
	 * @param array<string,mixed> $request The request.
	 *
	 * @return GatewayDeliveryRequestedEvent
	 */
	private function dispatch(string $gateway, array $request): GatewayDeliveryRequestedEvent {
		$event = new GatewayDeliveryRequestedEvent(gatewayId: $gateway, request: $request, sourceApp: 'dossiq', config: ['source' => 'corv-test']);
		$this->listener->handle($event);
		return $event;

	}//end dispatch()

	/**
	 * A valid CORV message goes over the wire and the delivery comes back on the event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-sibling-app-sends-through-a-gateway-with-a-typed-event-req-sg-010
	 */
	public function testACorvMessageIsSentAndTheDeliveryReturned(): void {
		$this->transport->expects($this->once())->method('send')
			->with('corv', $this->anything(), ['source' => 'corv-test'])
			->willReturn(GatewayDelivery::delivered('corv', 'corv-2026-0001'));

		$event = $this->dispatch(
			gateway: 'corv',
			request: [
				'messageType' => 'zorgmelding',
				'message' => ['bsn' => '999993653', 'melder' => 'gemeente', 'meldingsdatum' => '2026-09-18', 'aanleiding' => 'zorg'],
			]
		);

		$this->assertTrue($event->isHandled());
		$this->assertTrue($event->getDelivery()['delivered']);
		$this->assertSame('corv-2026-0001', $event->getDelivery()['identifier']);

	}//end testACorvMessageIsSentAndTheDeliveryReturned()

	/**
	 * An invalid GGK message never reaches the wire; the refused delivery says why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-sibling-app-sends-through-a-gateway-with-a-typed-event-req-sg-010
	 */
	public function testAnInvalidGgkMessageNeverLeaves(): void {
		$this->transport->expects($this->never())->method('send');

		$event = $this->dispatch(gateway: 'ggk', request: ['messageType' => 'nope', 'message' => []]);

		$this->assertTrue($event->isHandled());
		$this->assertFalse($event->getDelivery()['delivered']);
		$this->assertFalse($event->getDelivery()['transmitted']);

	}//end testAnInvalidGgkMessageNeverLeaves()

	/**
	 * A WKPB restriction is registered through the event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-sibling-app-sends-through-a-gateway-with-a-typed-event-req-sg-010
	 */
	public function testAWkpbRestrictionIsRegistered(): void {
		$this->transport->method('send')->willReturn(GatewayDelivery::delivered('wkpb', 'wkpb-2026-77'));

		$event = $this->dispatch(
			gateway: 'wkpb',
			request: [
				'propertyReference' => 'adres-1',
				'restriction' => ['restrictionType' => 'gemeentelijk monument', 'decisionDate' => '2026-09-18', 'decisionReference' => 'B-2026-1'],
			]
		);

		$this->assertSame('wkpb-2026-77', $event->getDelivery()['identifier']);

	}//end testAWkpbRestrictionIsRegistered()

	/**
	 * A publication carrying the document inline is refused before sending.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-sibling-app-sends-through-a-gateway-with-a-typed-event-req-sg-010
	 */
	public function testAnInlinePublicationIsRefusedBeforeSending(): void {
		$this->transport->expects($this->never())->method('send');

		$event = $this->dispatch(
			gateway: 'publicatie',
			request: [
				'reference' => ['app' => 'opencatalogi', 'id' => 'p-1', 'url' => 'https://example.org/p-1', 'document' => 'base64...'],
				'instruction' => ['publicationType' => 'gemeenteblad', 'effectiveDate' => '2026-10-01'],
			]
		);

		$this->assertFalse($event->getDelivery()['delivered']);

	}//end testAnInlinePublicationIsRefusedBeforeSending()

	/**
	 * An unknown gateway and a message request without a type are refused on the event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-sibling-app-sends-through-a-gateway-with-a-typed-event-req-sg-010
	 */
	public function testUnknownGatewaysAndIncompleteRequestsAreRefused(): void {
		$this->transport->expects($this->never())->method('send');

		$unknown = $this->dispatch(gateway: 'digid', request: []);
		$this->assertFalse($unknown->isHandled());
		$this->assertSame('unknown-gateway', $unknown->getRefusal()['code']);

		$untyped = $this->dispatch(gateway: 'corv', request: ['message' => []]);
		$this->assertFalse($untyped->isHandled());
		$this->assertSame('invalid-request', $untyped->getRefusal()['code']);

	}//end testUnknownGatewaysAndIncompleteRequestsAreRefused()
}//end class
