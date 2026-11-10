<?php

/**
 * Integriq PSD2 aggregator call.
 *
 * One brokered call to the PSD2 aggregator: the HTTP method, the composed
 * URL, the optional JSON body, and whether a 401/403 means a dead consent.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Psd2
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
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Psd2;

/**
 * The values of one brokered aggregator call.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
 */
final class AggregatorCall {

	/**
	 * Constructor.
	 *
	 * @param string     $method        The HTTP method.
	 * @param string     $url           The composed URL (only its path+query is forwarded by the broker).
	 * @param array|null $json          The JSON request body, or null for none.
	 * @param bool       $consentScoped Whether a 401/403 means a dead consent (vs a bad API key).
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $url,
		public readonly ?array $json=null,
		public readonly bool $consentScoped=false,
	) {

	}//end __construct()
}//end class
