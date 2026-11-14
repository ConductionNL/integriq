<?php

/**
 * Integriq ConnectionCallRequestedListener.
 *
 * Answers a sibling app's ConnectionCallRequestedEvent: calls the source an
 * administrator linked to the app's declared connection and writes the
 * answer, or a structured refusal, into the event's result slot.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\ConnectionCallRequestedEvent;
use OCA\Integriq\Service\ConnectionCallService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The listener never throws to the caller and never leaves the event unanswered.
 *
 * @template-implements IEventListener<ConnectionCallRequestedEvent>
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
 */
class ConnectionCallRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ConnectionCallService $service Makes the call.
	 * @param LoggerInterface       $logger  Records a failure.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function __construct(
		private readonly ConnectionCallService $service,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one call request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ConnectionCallRequestedEvent) === false) {
			return;
		}

		try {
			$answer = $this->service->call(
				app: $event->getSourceApp(),
				connectionKey: $event->getConnectionKey(),
				method: $event->getMethod(),
				path: $event->getPath(),
				query: $event->getQuery(),
				body: $event->getBody()
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Integriq: a connection call request failed',
				['app' => $event->getSourceApp(), 'connection' => $event->getConnectionKey(), 'error' => $e->getMessage()]
			);
			$event->refuse(code: ConnectionCallService::CALL_FAILED, reason: $e->getMessage());
			return;
		}

		if (isset($answer['refusal']) === true) {
			$event->refuse(code: $answer['refusal']['code'], reason: $answer['refusal']['reason']);
			return;
		}

		$event->setResult(status: $answer['status'], body: $answer['body'], sourceSlug: $answer['sourceSlug']);
	}//end handle()
}//end class
