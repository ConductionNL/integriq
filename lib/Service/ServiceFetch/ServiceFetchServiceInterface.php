<?php

/**
 * Contract of the ServiceFetchService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\ServiceFetch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ServiceFetch;

use OCA\Integriq\Event\ServiceFetchRequestedEvent;

/**
 * Public operations of {@see ServiceFetchService} that other components call.
 */
interface ServiceFetchServiceInterface {
	/**
	 * Answer one ServiceFetchRequestedEvent: outputs or a named refusal.
	 *
	 * @param ServiceFetchRequestedEvent $event The request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
	 */
	public function handle(ServiceFetchRequestedEvent $event): void;
}
