<?php

/**
 * OpenConnector Payment Provider Interface.
 *
 * Narrow domain seam through which every payment creation and status lookup
 * occurs. A new PSP (or a second method/provider such as a future Wero
 * binding once iDEAL retires end-2027) is added by implementing this
 * interface, never by editing PaymentIntentService or PaymentsController —
 * see design.md "Provider-neutral wire contract" and "Open Questions".
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Payment;

use OCA\OpenConnector\Exception\PaymentProviderException;

/**
 * A payment-provider binding: create a payment, and look up its current status.
 *
 * @spec openspec/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002
 */
interface PaymentProviderInterface {
	/**
	 * Create a payment against the configured provider.
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object
	 *                                   (`provider`, `baseUrl`, `authentication.credentialRef`).
	 * @param array $payload The create-payment envelope — `amount{value,currency}`,
	 *                       `description`, `redirectUrl`, `webhookUrl`, optional
	 *                       `method` (`ideal`|`creditcard`|`bancontact`|`sepadirectdebit`),
	 *                       optional `metadata` (opaque passthrough object).
	 *
	 * @return array{providerPaymentId: string, paymentStatus: string, checkoutUrl: string, extras: array}
	 *                                                                                                     The dispatch outcome.
	 *
	 * @throws PaymentProviderException When the provider is unreachable, errors, or cannot be
	 *                                  configured (e.g. missing credential).
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md
	 */
	public function createPayment(array $sourceConfiguration, array $payload): array;

	/**
	 * Fetch the current authoritative status of a previously created payment.
	 *
	 * Called on every verified inbound webhook (REQ-LPP-003) — the connector
	 * never trusts a status claimed in the webhook body; it always re-derives
	 * status via this method.
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object.
	 * @param string $providerPaymentId The provider-assigned payment id returned by `createPayment()`.
	 *
	 * @return array{providerPaymentId: string, paymentStatus: string} The current provider-native status.
	 *
	 * @throws PaymentProviderException When the provider is unreachable, errors, or the payment
	 *                                  id is unknown to the provider.
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md#requirement-signature-gated-webhook-that-never-trusts-an-inbound-status-claim-req-lpp-003
	 */
	public function fetchPaymentStatus(array $sourceConfiguration, string $providerPaymentId): array;
}//end interface
