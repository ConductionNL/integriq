<?php

/**
 * Integriq brokered call.
 *
 * One call a REST provider sends through the OpenRegister credential broker:
 * the HTTP method, the composed URL and the optional JSON body.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
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
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

/**
 * The values of one brokered call.
 *
 * @spec openspec/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
 * @spec openspec/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */
final class BrokeredCall {

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
