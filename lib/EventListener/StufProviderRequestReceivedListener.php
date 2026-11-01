<?php

/**
 * Integriq StUF Provider Request Received Listener.
 *
 * Answers a StufProviderRequestReceivedEvent through the provider receiver.
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\StufProviderRequestReceivedEvent;
use OCA\Integriq\Service\StufZkn\StufProviderReceiverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Routes a forwarded provider request to the receiver.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
 */
class StufProviderRequestReceivedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param StufProviderReceiverInterface $receiver The receiver.
	 */
	public function __construct(
		private readonly StufProviderReceiverInterface $receiver,
	) {

	}//end __construct()

	/**
	 * Answer the event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-provider-receiver/spec.md#requirement-a-sibling-app-forwards-its-wire-contract-urls-for-one-release
	 */
	public function handle(Event $event): void {
		if (($event instanceof StufProviderRequestReceivedEvent) === false) {
			return;
		}

		$answer = $this->receiver->receiveForwarded(rawXml: $event->getRawXml(), sourceId: $event->getSourceId(), service: $event->getService());
		$event->answer(statusCode: $answer['statusCode'], body: $answer['body']);
	}//end handle()
}//end class
