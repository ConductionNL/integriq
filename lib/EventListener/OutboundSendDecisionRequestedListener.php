<?php

/**
 * Answers a sibling app's question whether it may message these people.
 *
 * A thin wrapper around OptOutRegistry::decideMany(), the one decision
 * function. It answers every recipient or none: when the table cannot be read
 * it leaves the event unhandled, which the sender reads as "integriq is
 * absent" and answers closed for anything that is not exempt. The read is on
 * integriq's own table with no RBAC in front of it, so the answer is the same
 * from a request, a public page, a background job or a flow run.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one decision per recipient into the event's result slot.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */
class OutboundSendDecisionRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param OptOutRegistry $registry The one decision function.
	 * @param LoggerInterface $logger Records why an event was left unhandled.
	 */
	public function __construct(
		private readonly OptOutRegistry $registry,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Answer the question.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof OutboundSendDecisionRequestedEvent) === false) {
			return;
		}

		if ($this->registry->isAuthorityEnabled() === false) {
			// The rollback flag: unanswered, so the sender fails closed.
			return;
		}

		try {
			$decisions = $this->registry->decideMany(
				channel: $event->getChannel(),
				category: $event->getCategory(),
				requiresConsent: $event->requiresConsent(),
				recipients: array_values($event->getRecipients()),
				sourceApp: $event->getSourceApp(),
				correlationId: $event->getCorrelationId(),
				baseUrl: $event->getBaseUrl(),
				inReplyTo: $event->getInReplyTo()
			);
		} catch (Throwable $exception) {
			$event->setHandled(false);
			$this->logger->error(
				'[OutboundSendDecisionRequestedListener] no answer, the event stays unhandled: ' . $exception->getMessage(),
				['sourceApp' => $event->getSourceApp(), 'correlationId' => $event->getCorrelationId(), 'exception' => $exception]
			);
			return;
		}

		foreach ($event->getRecipients() as $recipient) {
			$address = (string)($recipient['address'] ?? '');
			if (isset($decisions[$address]) === false) {
				$event->setHandled(false);
				return;
			}

			$decision = $decisions[$address];
			$event->setDecision(
				$address,
				[
					'send' => $decision['send'],
					'overridden' => $decision['overridden'],
					'code' => $decision['code'],
					'reason' => $decision['reason'],
					'unsubscribe' => $decision['unsubscribe'],
				]
			);
		}

		$event->setHandled(true);

	}//end handle()

}//end class
