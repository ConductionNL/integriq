<?php

/**
 * Binds OpenRegister's subscription request to the handler that fulfils it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.app
 *
 * @spec openspec/changes/registry-subscription-connector/specs/registry-subscription-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\Registry\SubscriptionRequestHandler;
use OCA\OpenRegister\Event\RegistrySubscriptionRequestedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The binding `registry-subscription-connector` deliberately did not guess.
 *
 * 🔑 THE HANDLER WAS BUILT FIRST AND WAITED, WHICH WAS THE RIGHT ORDER.
 * `SubscriptionRequestHandler` takes the request as a payload, whatever
 * carried it, so none of its logic depended on the wire shape. This listener is
 * the one line that was blocked: OpenRegister has since shipped
 * `RegistrySubscriptionRequestedEvent` and dispatches it from
 * `RegistrySubscriptionNotifier`, so the shape is read rather than invented.
 *
 * 🔴 IT SWALLOWS ITS OWN FAILURES ON PURPOSE. The event is dispatched inside
 * OpenRegister's own work: a subscription integriq cannot fulfil must not take
 * down the save that asked for it. The handler already logs and returns null
 * for a request it cannot honour; this catches what escapes that, so the worst
 * case is a subscription that did not happen and said so, rather than a write
 * that failed for a reason the user cannot act on.
 *
 * @template-implements IEventListener<Event>
 */
class RegistrySubscriptionRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SubscriptionRequestHandler $handler The half that was already built.
	 * @param LoggerInterface            $logger  Structured logger.
	 */
	public function __construct(
		private readonly SubscriptionRequestHandler $handler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fulfil a subscription OpenRegister asked for.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegistrySubscriptionRequestedEvent) === false) {
			return;
		}

		try {
			// `getPayload()` carries `registry` and `identityValue`, which are
			// two of the spellings the handler already accepts. Reading the
			// event's own payload rather than assembling one from its getters
			// means the wire shape has ONE definition, on the side that emits
			// it.
			$result = $this->handler->handle($event->getPayload());

			if ($result === null) {
				// The handler has already said why, with the registry and the
				// identity. Repeating it here would double every line.
				return;
			}

			$this->logger->info(
				'registry-subscription.request.fulfilled',
				[
					'registry' => $event->getRegistry(),
					'objectUuid' => $event->getObjectUuid(),
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'registry-subscription.request.failed',
				[
					'registry' => $event->getRegistry(),
					'objectUuid' => $event->getObjectUuid(),
					'exception' => $e->getMessage(),
				]
			);
		}
	}//end handle()
}//end class
