<?php

/**
 * Integriq Service Fetch Refusal.
 *
 * A named refusal of a service fetch, carrying the refusal code as its
 * message and, when it has one, what it is about.
 *
 * @category Service
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

use RuntimeException;

/**
 * A refusal code of ServiceFetchRequestedEvent, thrown inside ServiceFetchService only.
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */
class ServiceFetchRefusal extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string      $refusal The refusal code.
	 * @param string|null $detail  What it is about, such as an input name.
	 */
	public function __construct(string $refusal, public readonly ?string $detail = null) {
		parent::__construct(message: $refusal);
	}//end __construct()
}//end class
