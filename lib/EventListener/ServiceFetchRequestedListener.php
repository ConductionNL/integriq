<?php

/**
 * Runs a service fetch another app asked for.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\ServiceFetchRequestedEvent;
use OCA\Integriq\Service\ServiceFetch\ServiceFetchService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Hands a ServiceFetchRequestedEvent to ServiceFetchService, which answers it
 * with outputs or a named refusal and never throws.
 *
 * @template-implements IEventListener<ServiceFetchRequestedEvent>
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */
class ServiceFetchRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ServiceFetchService $service Runs the fetch.
	 */
	public function __construct(
		private readonly ServiceFetchService $service,
	) {
	}//end __construct()

	/**
	 * Answer the request.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ServiceFetchRequestedEvent === false) {
			return;
		}

		$this->service->handle(event: $event);
	}//end handle()
}//end class
