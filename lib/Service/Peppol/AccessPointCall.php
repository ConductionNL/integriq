<?php

/**
 * Integriq Peppol access point call.
 *
 * One brokered call to the Peppol Access Point: the HTTP method, the composed
 * URL and the optional JSON body.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Peppol
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Peppol;

/**
 * The values of one brokered access point call.
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */
final class AccessPointCall {

	/**
	 * Constructor.
	 *
	 * @param string     $method The HTTP method.
	 * @param string     $url    The composed URL (only its path+query is forwarded by the broker).
	 * @param array|null $json   The JSON request body, or null for none.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $url,
		public readonly ?array $json=null,
	) {

	}//end __construct()
}//end class
