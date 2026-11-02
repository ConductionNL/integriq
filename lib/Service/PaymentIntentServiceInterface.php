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
	 * @return array{result: string, outcome: ?string} `result` is one of
	 *                                                 `applied`|`noop`|`not-found`; `outcome` is the mapped outcome when applied.
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
}
