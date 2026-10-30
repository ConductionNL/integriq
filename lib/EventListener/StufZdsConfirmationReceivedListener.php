<?php

/**
 * Integriq StUF-ZDS Confirmation Received Listener.
 *
 * Answers a StufZdsConfirmationReceivedEvent through the confirmation receiver.
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
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\StufZdsConfirmationReceivedEvent;
use OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Routes a forwarded confirmation envelope to the receiver.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */
class StufZdsConfirmationReceivedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param StufZdsConfirmationReceiverInterface $receiver The receiver.
	 */
	public function __construct(
		private readonly StufZdsConfirmationReceiverInterface $receiver,
	) {

	}//end __construct()

	/**
	 * Answer the event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function handle(Event $event): void {
		if (($event instanceof StufZdsConfirmationReceivedEvent) === false) {
			return;
		}

		$answer = $this->receiver->receive(rawXml: $event->getRawXml());
		$event->answer(statusCode: $answer['statusCode'], body: $answer['body']);
	}//end handle()
}//end class
