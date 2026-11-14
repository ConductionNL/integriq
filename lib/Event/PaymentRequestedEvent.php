<?php

/**
 * Integriq PaymentRequestedEvent
 *
 * The typed ADR-041 command a sibling app dispatches to have integriq create
 * a payment, without a Nextcloud session: a portal forward, a background job
 * or an intake raise. It carries provenance (source app, subject reference,
 * correlation id), the amount, a description, a redirect URL, and optionally
 * a method, a point-of-sale terminal id, a payment source slug and metadata.
 *
 * Integriq's PaymentRequestedListener writes the result into the event's
 * result slot: the payment intent id, the provider payment id, the checkout
 * URL (empty for a point-of-sale payment), the status and whether the source
 * is dormant. A request it cannot serve is marked handled with a refusal code
 * (`invalid`, `no-source`, `provider-error`, `unsupported`) and a reason;
 * the caller never sees an exception. A caller guards the dispatch with
 * `class_exists()` so it loads without integriq.
 *
 * @category Event
 * @package  OCA\Integriq\Event
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
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Command: create a payment for a sibling app, answered in the result slot.
 *
 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The command's fields are its
 *  published contract (shillinq receivables-payment-links D1); a parameter object
 *  would make every caller build a second class to dispatch one event.
 */
class PaymentRequestedEvent extends Event {

	/**
	 * The refusal codes the listener answers with.
	 *
	 * @var array<int, string>
	 */
	public const REFUSAL_CODES = ['invalid', 'no-source', 'provider-error', 'unsupported'];

	/**
	 * Whether a listener answered the command.
	 *
	 * @var boolean
	 */
	private bool $handled = false;

	/**
	 * The result, once a payment was created.
	 *
	 * @var array{paymentIntentId: string, providerPaymentId: string, checkoutUrl: string, paymentStatus: string, dormant: bool}|null
	 */
	private ?array $result = null;

	/**
	 * The refusal, when the command could not be served.
	 *
	 * @var array{code: string, reason: string}|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string      $sourceApp      The requesting app id, such as `shillinq`.
	 * @param string      $subjectRef     What is paid, such as an invoice reference.
	 * @param string      $amountValue    The amount as a decimal string, such as `125.00`.
	 * @param string      $amountCurrency The ISO 4217 currency, such as `EUR`.
	 * @param string      $description    The payment description the payer sees.
	 * @param string      $redirectUrl    Where the payer returns after paying.
	 * @param string|null $method         The payment method, such as `ideal` or `pointofsale`.
	 * @param string|null $terminalId     The point-of-sale terminal, for `pointofsale`.
	 * @param string|null $sourceSlug     The payment source; null for the enabled one.
	 * @param array       $metadata       Passed through to the provider and stored.
	 * @param string      $correlationId  The caller's correlation id.
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $subjectRef,
		private readonly string $amountValue,
		private readonly string $amountCurrency,
		private readonly string $description,
		private readonly string $redirectUrl,
		private readonly ?string $method=null,
		private readonly ?string $terminalId=null,
		private readonly ?string $sourceSlug=null,
		private readonly array $metadata=[],
		private readonly string $correlationId='',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The requesting app id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * What is paid.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getSubjectRef(): string {
		return $this->subjectRef;
	}//end getSubjectRef()

	/**
	 * The amount as a decimal string.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getAmountValue(): string {
		return $this->amountValue;
	}//end getAmountValue()

	/**
	 * The currency.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getAmountCurrency(): string {
		return $this->amountCurrency;
	}//end getAmountCurrency()

	/**
	 * The payment description.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getDescription(): string {
		return $this->description;
	}//end getDescription()

	/**
	 * Where the payer returns.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getRedirectUrl(): string {
		return $this->redirectUrl;
	}//end getRedirectUrl()

	/**
	 * The payment method, or null for the provider's choice.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getMethod(): ?string {
		return $this->method;
	}//end getMethod()

	/**
	 * The point-of-sale terminal id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-payment-can-be-taken-on-a-registered-point-of-sale-device-req-lpp-009
	 */
	public function getTerminalId(): ?string {
		return $this->terminalId;
	}//end getTerminalId()

	/**
	 * The payment source slug, or null for the enabled one.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getSourceSlug(): ?string {
		return $this->sourceSlug;
	}//end getSourceSlug()

	/**
	 * The caller's metadata.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getMetadata(): array {
		return $this->metadata;
	}//end getMetadata()

	/**
	 * The caller's correlation id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Mark the command answered.
	 *
	 * @param bool $handled Whether it is answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()

	/**
	 * Whether a listener answered the command.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * Write the created payment into the result slot.
	 *
	 * @param string $paymentIntentId   The stored payment intent id.
	 * @param string $providerPaymentId The provider's payment id.
	 * @param string $checkoutUrl       The checkout URL; empty for point of sale.
	 * @param string $paymentStatus     The provider-native status.
	 * @param bool   $dormant           Whether the source is the `log` sandbox.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function setResult(string $paymentIntentId, string $providerPaymentId, string $checkoutUrl, string $paymentStatus, bool $dormant): void {
		$this->result = [
			'paymentIntentId' => $paymentIntentId,
			'providerPaymentId' => $providerPaymentId,
			'checkoutUrl' => $checkoutUrl,
			'paymentStatus' => $paymentStatus,
			'dormant' => $dormant,
		];
	}//end setResult()

	/**
	 * The result, or null when no payment was created.
	 *
	 * @return array{paymentIntentId: string, providerPaymentId: string, checkoutUrl: string, paymentStatus: string, dormant: bool}|null
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Refuse the command.
	 *
	 * @param string $reason Why, for a person reading the caller's log.
	 * @param string $code   One of {@see REFUSAL_CODES}.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function setRefusal(string $reason, string $code): void {
		$this->refusal = ['code' => $code, 'reason' => $reason];
	}//end setRefusal()

	/**
	 * The refusal, or null when not refused.
	 *
	 * @return array{code: string, reason: string}|null
	 *
	 * @spec openspec/changes/messaging-payment-command-settlements-and-point-of-sale/specs/live-payment-providers/spec.md#requirement-a-sibling-app-creates-a-payment-through-a-typed-command-req-lpp-007
	 */
	public function getRefusal(): ?array {
		return $this->refusal;
	}//end getRefusal()
}//end class
