<?php

/**
 * Integriq GatewayDeliveryRequestedListener.
 *
 * Sends a sibling app's request through the statutory gateway it names.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
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

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\GatewayDeliveryRequestedEvent;
use OCA\Integriq\Gateway\Adapter\CorvGateway;
use OCA\Integriq\Gateway\Adapter\GgkGateway;
use OCA\Integriq\Gateway\Adapter\PublicationGateway;
use OCA\Integriq\Gateway\Adapter\WkpbGateway;
use OCA\Integriq\Gateway\GatewayDelivery;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * The production caller the CORV, GGK, WKPB and publication adapters lacked.
 *
 * Each adapter validates before anything leaves and records the outcome as a
 * GatewayDelivery; this listener only picks the adapter and reads the request
 * shape that adapter takes. It adds no rule of its own about the message.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-sibling-app-sends-through-a-gateway-with-a-typed-event-req-sg-010
 *
 * @template-implements IEventListener<Event>
 */
class GatewayDeliveryRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param CorvGateway $corv CORV.
	 * @param GgkGateway $ggk GGK.
	 * @param WkpbGateway $wkpb WKPB.
	 * @param PublicationGateway $publication Official publication.
	 */
	public function __construct(
		private readonly CorvGateway $corv,
		private readonly GgkGateway $ggk,
		private readonly WkpbGateway $wkpb,
		private readonly PublicationGateway $publication,
	) {

	}//end __construct()

	/**
	 * Send through the named gateway, or refuse the request.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-sibling-app-sends-through-a-gateway-with-a-typed-event-req-sg-010
	 */
	public function handle(Event $event): void {
		if (($event instanceof GatewayDeliveryRequestedEvent) === false) {
			return;
		}

		$request = $event->getRequest();
		$config = $event->getConfig();
		$delivery = match ($event->getGatewayId()) {
			'corv' => $this->message(event: $event, gateway: $this->corv),
			'ggk' => $this->message(event: $event, gateway: $this->ggk),
			WkpbGateway::ID => $this->wkpb->register(
				propertyReference: (string)($request['propertyReference'] ?? ''),
				restriction: (array)($request['restriction'] ?? []),
				config: $config
			),
			PublicationGateway::ID => $this->publication->publish(
				reference: (array)($request['reference'] ?? []),
				instruction: (array)($request['instruction'] ?? []),
				config: $config
			),
			default => null,
		};

		if ($delivery === null) {
			if ($event->getRefusal() === null) {
				$event->refuse(
					reason: sprintf('No gateway "%s". Known: corv, ggk, wkpb, publicatie.', $event->getGatewayId()),
					code: 'unknown-gateway'
				);
			}

			return;
		}

		$event->setDelivery($delivery->toArray());

	}//end handle()

	/**
	 * Send a CORV or GGK message, refusing a request without a message type.
	 *
	 * @param GatewayDeliveryRequestedEvent $event The request.
	 * @param CorvGateway|GgkGateway $gateway The gateway.
	 *
	 * @return GatewayDelivery|null The delivery, or null when refused.
	 */
	private function message(GatewayDeliveryRequestedEvent $event, CorvGateway|GgkGateway $gateway): ?GatewayDelivery {
		$request = $event->getRequest();
		$type = (string)($request['messageType'] ?? '');
		if ($type === '') {
			$event->refuse(reason: 'The request names no messageType.', code: 'invalid-request');
			return null;
		}

		return $gateway->send(messageType: $type, message: (array)($request['message'] ?? []), config: $event->getConfig());
	}//end message()
}//end class
