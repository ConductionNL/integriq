<?php

/**
 * Records a sibling app's request to change a person's wish.
 *
 * A thin wrapper around OptOutRegistry::record(). An incomplete request is
 * handled with a refusal, so the sender sees why. A failure to write leaves
 * the event unhandled, so the sender knows nothing was recorded.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use InvalidArgumentException;
use OCA\Integriq\Event\OptOutChangeRequestedEvent;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the wish and reports the record id.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
 */
class OptOutChangeRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param OptOutRegistry $registry Records the wish.
	 * @param LoggerInterface $logger Records why an event was left unhandled.
	 */
	public function __construct(
		private readonly OptOutRegistry $registry,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Record the wish.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-contact-erasure-keeps-the-opt-out-req-ooa-010
	 */
	public function handle(Event $event): void {
		if (($event instanceof OptOutChangeRequestedEvent) === false) {
			return;
		}

		if ($this->registry->isAuthorityEnabled() === false) {
			// The rollback flag: unanswered, so the sender keeps its own record.
			return;
		}

		try {
			$event->setRecordId($this->registry->record($event->toRequest()));
			$event->setHandled(true);
		} catch (InvalidArgumentException $exception) {
			$event->setHandled(true);
			$event->setRefusal($exception->getMessage(), 'invalid-request');
		} catch (Throwable $exception) {
			$this->logger->error(
				'[OptOutChangeRequestedListener] not recorded, the event stays unhandled: ' . $exception->getMessage(),
				['sourceApp' => $event->getSourceApp(), 'correlationId' => $event->getCorrelationId(), 'exception' => $exception]
			);
		}

	}//end handle()

}//end class
