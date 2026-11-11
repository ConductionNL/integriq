<?php

/**
 * Integriq payment provider call.
 *
 * One brokered call to a payment provider's API: the HTTP method, the
 * absolute URL, and the JSON body when the call sends one.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Payment
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
 * @spec openspec/specs/live-payment-providers/spec.md#scenario-the-mollie-provider-brokers-its-api-key
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Payment;

/**
 * Call a payment provider's API once.
 *
 * @spec openspec/specs/live-payment-providers/spec.md#scenario-the-mollie-provider-brokers-its-api-key
 */
final class PaymentCall {

	/**
	 * Constructor.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    The absolute URL.
	 * @param array|null $json   The JSON body, or null for none.
	 */
	public function __construct(
		public readonly string $method,
		public readonly string $url,
		public readonly ?array $json=null,
	) {

	}//end __construct()
}//end class
