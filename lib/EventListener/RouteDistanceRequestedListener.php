<?php

/**
 * Integriq RouteDistanceRequestedListener.
 *
 * Answers a sibling app's RouteDistanceRequestedEvent with the one-way road
 * distance between two postcodes, or a structured refusal.
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
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\RouteDistanceRequestedEvent;
use OCA\Integriq\Service\RouteDistanceService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The listener never throws to the caller and never leaves the event unanswered.
 *
 * @template-implements IEventListener<RouteDistanceRequestedEvent>
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
 */
class RouteDistanceRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param RouteDistanceService $service Works out the distance.
	 * @param LoggerInterface      $logger  Records a failure.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function __construct(
		private readonly RouteDistanceService $service,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one distance request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof RouteDistanceRequestedEvent) === false) {
			return;
		}

		try {
			$answer = $this->service->distance(
				app: $event->getSourceApp(),
				fromPostcode: $event->getFromPostcode(),
				toPostcode: $event->getToPostcode(),
				mode: $event->getMode(),
				connectionKey: $event->getConnectionKey()
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Integriq: a route distance request failed',
				['app' => $event->getSourceApp(), 'error' => $e->getMessage()]
			);
			$event->refuse(code: 'call-failed', reason: $e->getMessage());
			return;
		}

		if (isset($answer['refusal']) === true) {
			$event->refuse(code: (string)$answer['refusal']['code'], reason: (string)$answer['refusal']['reason']);
			return;
		}

		$event->setResult(result: $answer);
	}//end handle()
}//end class
