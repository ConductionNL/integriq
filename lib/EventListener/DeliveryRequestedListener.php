<?php

/**
 * Integriq DeliveryRequested EventListener.
 *
 * The in-process half of the ADR-041 delivery seam: receives a sibling app's
 * typed {@see DeliveryRequestedEvent}, hands it to
 * {@see EventService::ingestDeliveryRequest} so the CloudEvents pipeline owns
 * the transport (subscription routing, retry, dead-letter, replay), and
 * writes the synchronous result slot back onto the event.
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
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absorb-dossiq-deliveries/specs/delivery-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\DeliveryRequestedEvent;
use OCA\Integriq\Outbound\RecipientResolver;
use OCA\Integriq\Outbound\RecipientSelectionRefusedException;
use OCA\Integriq\Service\EventServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener that ingests cross-app delivery requests into the CloudEvents
 * pipeline.
 *
 * On success it marks the event handled, records the persisted CloudEvent
 * uuid as the result id, and reports the matched-subscription count so a
 * fail-closed consumer can distinguish "accepted and routed" from "accepted
 * but no delivery route is configured". On ingest failure the event stays
 * unhandled — the consumer's fail-closed guard then records a refusal.
 *
 * @spec openspec/changes/absorb-dossiq-deliveries/specs/delivery-intake/spec.md
 */
class DeliveryRequestedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param EventServiceInterface $eventService The CloudEvents pipeline entry point.
	 * @param LoggerInterface $logger Logger for ingest failures.
	 * @param RecipientResolver $recipientResolver Resolves the per-message recipient decision.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EventServiceInterface $eventService,
		private readonly LoggerInterface $logger,
		private readonly RecipientResolver $recipientResolver,
	) {
	}//end __construct()

	/**
	 * Handle a cross-app delivery request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/absorb-dossiq-deliveries/specs/delivery-intake/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof DeliveryRequestedEvent) === false) {
			return;
		}

		if ($this->resolveRecipients(event: $event) === false) {
			return;
		}

		try {
			$result = $this->eventService->ingestDeliveryRequest(request: $event);
		} catch (\Throwable $e) {
			// Leave the event unhandled: the consumer's fail-closed guard
			// records the refusal on its own domain record.
			$this->logger->error(
				'Delivery request ingest failed: ' . $e->getMessage(),
				[
					'exception' => $e,
					'sourceApp' => $event->getSourceApp(),
					'correlationId' => $event->getCorrelationId(),
				]
			);
			return;
		}//end try

		$event->setResultId(resultId: (string)$result['event']->getUuid());
		$event->setMatchedSubscriptions(matchedSubscriptions: count($result['messages']));
		if (($result['refusal'] ?? null) !== null) {
			$event->setRefusal(reason: (string)$result['refusal']['reason'], code: (string)$result['refusal']['code']);
		}

		$event->setHandled(handled: true);
	}//end handle()

	/**
	 * Resolve the request's recipient decision in integriq, before anything is
	 * recorded or sent. A refused decision answers the request as refused and
	 * handled: nothing is sent and no record of a send is written.
	 *
	 * @param DeliveryRequestedEvent $event The request.
	 *
	 * @return bool True when the ingest may proceed.
	 *
	 * @spec openspec/changes/one-off-and-suppressed-recipients/specs/message-recipient-selection/spec.md#requirement-the-recipient-decision-travels-on-the-delivery-request-req-mrs-006
	 */
	private function resolveRecipients(DeliveryRequestedEvent $event): bool {
		$decision = $event->getRecipientDecision();
		if ($this->recipientResolver->isDecision(decision: $decision) === false) {
			return true;
		}

		try {
			$event->setRecipientSelection(
				selection: $this->recipientResolver->resolve(decision: $decision, userId: (string)$event->getUserId())
			);
		} catch (RecipientSelectionRefusedException $e) {
			$this->logger->info(
				'Delivery request refused: its recipient decision cannot be honoured',
				['sourceApp' => $event->getSourceApp(), 'correlationId' => $event->getCorrelationId(), 'code' => $e->getRefusalCode()]
			);
			$event->setRefusal(reason: $e->getMessage(), code: $e->getRefusalCode());
			$event->setHandled(handled: true);
			return false;
		}

		return true;

	}//end resolveRecipients()
}//end class
