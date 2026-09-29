<?php

/**
 * Integriq Roster Import Requested Listener.
 *
 * Answers {@see \OCA\Integriq\Event\RosterImportRequestedEvent} by running
 * {@see \OCA\Integriq\Sources\Roster\RosterDeliveryService} for the event's
 * source, inside integriq's own DI context (ADR-041). Always answers: a failed
 * delivery comes back handled with `status: failed` and the contract's error
 * code, so the asking app can tell "integriq could not deliver" from
 * "integriq is not installed".
 *
 * @category Listener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\RosterImportRequestedEvent;
use OCA\Integriq\Sources\Roster\RosterDeliveryException;
use OCA\Integriq\Sources\Roster\RosterDeliveryService;
use OCA\Integriq\Sources\Roster\RosterTargetConfiguration;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs a requested rostering delivery and answers the asking app.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
 */
class RosterImportRequestedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param RosterDeliveryService $delivery The delivery service.
	 * @param LoggerInterface       $logger   Structured logger.
	 */
	public function __construct(
		private readonly RosterDeliveryService $delivery,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-learniq-asks-for-a-delivery-through-integriqs-typed-event-req-005
	 */
	public function handle(Event $event): void {
		if (($event instanceof RosterImportRequestedEvent) === false) {
			return;
		}

		try {
			$event->setResult(
				$this->delivery->deliver(
					systemId: $event->getSystemId(),
					options: $event->getOptions(),
					correlationId: $event->getCorrelationId()
				)
			);
			return;
		} catch (RosterDeliveryException $e) {
			$code = $e->getErrorCode();
			$message = $e->getMessage();
		} catch (Throwable $e) {
			$code = 'fetch-failed';
			$message = $e->getMessage();
		}

		$this->logger->warning(
			'roster-delivery.failed',
			['app' => $event->getSourceApp(), 'source' => $event->getSystemId(), 'errorCode' => $code, 'error' => $message]
		);

		$event->setResult(
			[
				'contractVersion' => RosterImportRequestedEvent::CONTRACT_VERSION,
				'status' => 'failed',
				'systemId' => $event->getSystemId(),
				'target' => RosterTargetConfiguration::TARGET,
				'errorCode' => $code,
				'error' => $message,
			]
		);
	}//end handle()
}//end class
