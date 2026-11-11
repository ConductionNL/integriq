<?php

/**
 * Integriq Roster Import Requested Listener.
 *
 * Answers {@see \OCA\Integriq\Event\RosterImportRequestedEvent} by running
 * {@see RosterDeliveryServiceInterface} for the event's
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
use OCA\Integriq\Sources\Roster\RosterDeliveryServiceInterface;
use OCA\Integriq\Sources\Roster\RosterTargetConfigurationInterface;
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
	 * @param RosterDeliveryServiceInterface $delivery The delivery service.
	 * @param LoggerInterface       $logger   Structured logger.
	 */
	public function __construct(
		private readonly RosterDeliveryServiceInterface $delivery,
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
			$event->setResult($this->deliver(event: $event));
		} catch (Throwable $e) {
			$this->answerFailure(event: $event, exception: $e);
		}
	}//end handle()

	/**
	 * Deliver the asked source's roster.
	 *
	 * @param RosterImportRequestedEvent $event The request.
	 *
	 * @return array<string,mixed> The delivery service's result.
	 */
	private function deliver(RosterImportRequestedEvent $event): array {
		return $this->delivery->deliver(
			systemId: $event->getSystemId(),
			options: $event->getOptions(),
			correlationId: $event->getCorrelationId()
		);
	}//end deliver()

	/**
	 * Log a failed delivery and answer it as failed.
	 *
	 * @param RosterImportRequestedEvent $event     The request.
	 * @param Throwable                  $exception Why the delivery failed.
	 *
	 * @return void
	 */
	private function answerFailure(RosterImportRequestedEvent $event, Throwable $exception): void {
		$failure = ['errorCode' => $this->errorCodeOf(exception: $exception), 'error' => $exception->getMessage()];
		$this->logger->warning(
			'roster-delivery.failed',
			['app' => $event->getSourceApp(), 'source' => $event->getSystemId()] + $failure
		);

		$event->setResult(
			[
				'contractVersion' => RosterImportRequestedEvent::CONTRACT_VERSION,
				'status' => 'failed',
				'systemId' => $event->getSystemId(),
				'target' => RosterTargetConfigurationInterface::TARGET,
			] + $failure
		);
	}//end answerFailure()

	/**
	 * The error code of a failed delivery: the refusal's own, else `fetch-failed`.
	 *
	 * @param Throwable $exception Why the delivery failed.
	 *
	 * @return string The error code.
	 */
	private function errorCodeOf(Throwable $exception): string {
		if ($exception instanceof RosterDeliveryException) {
			return $exception->getErrorCode();
		}

		return 'fetch-failed';
	}//end errorCodeOf()
}//end class
