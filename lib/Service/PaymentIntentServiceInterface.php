<?php

/**
 * Contract of the PaymentIntentService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use InvalidArgumentException;
use OCA\Integriq\Exception\PaymentProviderException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see PaymentIntentService} that other components call.
 */
interface PaymentIntentServiceInterface {
	/**
	 * Create a payment: validate the request, resolve the provider, dispatch,
	 * and persist the resulting `payment_intent`.
	 *
	 * @param array $payload The create-payment envelope — see
	 *                       {@see PaymentProviderInterface::createPayment()}, plus an
	 *                       optional `sourceSlug` selecting a specific payment source.
	 *
	 * @return array{paymentIntentId: string, providerPaymentId: string, paymentStatus: string,
	 *               checkoutUrl: string, dormant: bool, extras: array} The response envelope.
	 *
	 * @throws InvalidArgumentException When required fields are missing (caller maps to HTTP 400).
	 * @throws PaymentProviderException When no source is configured or the provider errors
	 *                                  (caller maps to HTTP 502).
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md
	 */
	public function createPayment(array $payload): array;

	/**
	 * Process a verified inbound webhook call: re-derive the authoritative
	 * status (ignoring anything claimed in the body), map it to shillinq's
	 * outcome vocabulary, apply the idempotency guard, and emit a status
	 * CloudEvent on an actual outcome change.
	 *
	 * A callback for an unknown `providerPaymentId` is recorded (logged) and
	 * MUST NOT throw — it returns a `not-found` result rather than a 500
	 * (mirrors `PeppolTransmissionService::handleDeliveryCallback()`).
	 *
	 * @param string $providerPaymentId The provider payment id from the webhook body (`id`).
	 *                                  Any other field in the body is deliberately ignored.
	 *
	 * @return array{result: string, outcome: ?string, paymentStatus: ?string} `result` is one of
	 *                                                 `applied`|`noop`|`not-found`; `outcome` is the mapped outcome when applied;
	 *                                                 `paymentStatus` is the provider-native status read, null when not found.
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md#requirement-signature-gated-webhook-that-never-trusts-an-inbound-status-claim-req-lpp-003
	 */
	public function handleWebhook(string $providerPaymentId): array;

	/**
	 * Resolve the payment source's `configuration.webhookSignature` block for
	 * signature verification. Public so `PaymentsController::webhook()` can
	 * verify BEFORE calling `handleWebhook()` (mirrors
	 * `PeppolController::inbound()` / `PeppolTransmissionService::resolveActiveSource()`).
	 *
	 * @return ObjectEntity The resolved active payment source.
	 *
	 * @throws PaymentProviderException When no active payment source is configured.
	 *
	 * @spec openspec/specs/live-payment-providers/spec.md#requirement-signature-gated-webhook-that-never-trusts-an-inbound-status-claim-req-lpp-003
	 */
	public function resolveActiveSource(): ObjectEntity;

	/**
	 * Refresh one payment's status from its provider through the webhook's path.
	 *
	 * @param string $paymentIntentId The `payment_intent` object id.
	 *
	 * @return array{result: string, outcome: ?string, paymentStatus: ?string} The refresh result.
	 *
	 * @throws PaymentProviderException When the source names an unknown provider or the provider errors.
	 *
	 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payments-status-is-refreshed-from-the-provider-on-request-req-prv-002
	 */
	public function refreshPayment(string $paymentIntentId): array;

	/**
	 * The provider bindings a payment source can pick.
	 *
	 * @return array<int, array{id: string, label: string, needsKey: bool}> The bindings.
	 *
	 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
	 */
	public function listProviders(): array;

	/**
	 * Check a draft payment source configuration with its binding's read-only probe.
	 *
	 * @param array $configuration The draft `configuration` of a payment source.
	 *
	 * @return array{ok: bool, provider: string, mode: ?string, profile: ?string, message: ?string} The check result.
	 *
	 * @spec openspec/changes/messaging-payments-review/specs/live-payment-providers/spec.md#requirement-a-payment-source-moves-to-a-live-provider-with-a-checked-key-req-prv-003
	 */
	public function checkProvider(array $configuration): array;
}
