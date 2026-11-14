<?php

/**
 * Integriq Payment Provider Interface.
 *
 * Narrow domain seam through which every payment creation and status lookup
 * occurs. A new PSP (or a second method/provider such as a future Wero
 * binding once iDEAL retires end-2027) is added by implementing this
 * interface, never by editing PaymentIntentService or PaymentsController —
 * see design.md "Provider-neutral wire contract" and "Open Questions".
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
 * @spec openspec/specs/live-payment-providers/spec.md#requirement-payment-provider-abstraction-with-log-and-mollie-bindings-req-lpp-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Payment;

use OCA\Integriq\Exception\PaymentProviderException;

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
	 *                       optional `metadata` (opaque passthrough object), and for
	 *                       `method` = `pointofsale` a `terminalId`.
	 *
	 * @return array{providerPaymentId: string, paymentStatus: string, checkoutUrl: string, extras: array}
	 *                                                                                                     The dispatch outcome.
	 *
	 * @throws PaymentProviderException When the provider is unreachable, errors, or cannot be
	 *                                  configured (e.g. missing credential); a
	 *                                  PaymentMethodUnsupportedException when the binding has
	 *                                  no such method (point of sale on `log`).
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-payment-can-be-taken-on-a-registered-point-of-sale-device-req-lpp-009
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

	/**
	 * The provider's settlements (payouts) settled on or after a moment.
	 *
	 * Each settlement lists the payments, refunds and chargebacks it holds as
	 * transaction lines, with amounts as decimal strings: a refund or
	 * chargeback line is negative, so the lines minus `amountFees` add up to
	 * `amountNet`. A binding without a settlement API returns an empty list.
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object.
	 * @param string $since ISO 8601 moment; older settlements are not returned.
	 *
	 * @return list<array<string, mixed>> Each `{settlementReference, settledAt, currency, amountGross,
	 *                                    amountFees, amountRefunds, amountChargebacks, amountNet,
	 *                                    transactions: [{providerPaymentId, type, amount, fee}]}`.
	 *
	 * @throws PaymentProviderException When the provider is unreachable or errors.
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-every-provider-settlement-is-reported-once-with-its-transactions-req-lpp-008
	 */
	public function fetchSettlements(array $sourceConfiguration, string $since): array;

	/**
	 * The point-of-sale terminals registered on the provider account.
	 *
	 * A binding without a point-of-sale method returns an empty list.
	 *
	 * @param array $sourceConfiguration The payment source's `configuration` object.
	 *
	 * @return list<array{terminalId: string, label: string, status: string}>
	 *
	 * @throws PaymentProviderException When the provider is unreachable or errors.
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-payment-can-be-taken-on-a-registered-point-of-sale-device-req-lpp-009
	 */
	public function listTerminals(array $sourceConfiguration): array;
}//end interface
