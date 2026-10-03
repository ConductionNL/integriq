# live-payment-providers Specification

## ADDED Requirements

### Requirement: A sibling app creates a payment through a typed command (REQ-LPP-007)

Integriq MUST offer `OCA\Integriq\Event\PaymentRequestedEvent`, a typed ADR-041 command carrying the source app, a subject reference, the amount, the currency, a description, a redirect URL and optionally a method, a terminal id, a source slug and metadata. Its listener MUST create the payment through the same path as `POST /api/payments`, MUST set integriq's own absolute webhook URL on the payment, and MUST write the payment intent id, the provider payment id, the checkout URL, the status and whether the source is dormant into the result slot. A request that cannot be served MUST be marked handled with a refusal code (`invalid`, `no-source`, `provider-error` or `unsupported`) and a reason, and MUST NOT throw to the caller. The command MUST work without a Nextcloud session.

#### Scenario: shillinq gets a payment link for an invoice from a background job
- GIVEN a Mollie payment source and a shillinq job without a user session
- WHEN shillinq dispatches `PaymentRequestedEvent` for invoice 2026-0142, EUR 125.00, description "Factuur 2026-0142"
- THEN the result slot holds a payment intent id, the Mollie payment id and a checkout URL, the stored `payment_intent` names shillinq as source app, and Mollie was given integriq's webhook URL
- @e2e exclude backend command event; covered by PHPUnit with the real event class

#### Scenario: no payment source is configured
- GIVEN an instance with no payment source
- WHEN shillinq dispatches the command
- THEN the event is handled with refusal code `no-source` and a reason, and shillinq receives no exception
- @e2e exclude backend command event; covered by PHPUnit

### Requirement: Every provider settlement is reported once with its transactions (REQ-LPP-008)

For every payment source whose provider is not `log`, integriq MUST fetch the provider's settlements once a day, MUST store each new settlement as a `payment_settlement` object unique per source and settlement reference, and MUST emit one `nl.conduction.payment.settlement` CloudEvent per new settlement with `settlementReference`, `settledAt`, `currency`, `amountGross`, `amountFees`, `amountRefunds`, `amountChargebacks`, `amountNet` and `transactions` (each `providerPaymentId`, `type`, `amount`, `fee`). A settlement already stored MUST NOT be emitted again.

#### Scenario: a Mollie payout is reported to shillinq
- GIVEN a live Mollie source and a settlement `st_example0001` of 14 payments, one refund and EUR 4.06 fees
- WHEN the daily settlement pull runs
- THEN one `nl.conduction.payment.settlement` event is emitted whose 15 transaction lines and fees add up to the net EUR 585.49
- @e2e exclude scheduled pull; covered by PHPUnit against recorded Mollie responses

#### Scenario: the pull runs twice
- GIVEN the settlement `st_example0001` was reported yesterday
- WHEN the pull runs again
- THEN no event is emitted for it
- @e2e exclude scheduled pull; covered by PHPUnit

### Requirement: A payment can be taken on a registered point-of-sale device (REQ-LPP-009)

Integriq MUST list the point-of-sale terminals of a payment source to an administrator and MUST create a payment with method `pointofsale` on a given terminal id when the provider binding supports it, without requiring a checkout URL for that method. A binding without a point-of-sale method MUST answer the command with refusal code `unsupported`.

#### Scenario: a shop owner takes a payment on her phone
- GIVEN a Mollie source with the terminal `term_example0001` registered by user anna
- WHEN shillinq dispatches the command with method `pointofsale` and that terminal for EUR 23.50
- THEN a payment is created on the terminal, the result slot holds its provider payment id and no checkout URL, and its capture arrives later as an `nl.conduction.payment.status` event
- @e2e exclude backend command event; covered by PHPUnit against recorded Mollie responses

#### Scenario: the provider has no point-of-sale method
- GIVEN a source on the `log` provider
- WHEN shillinq asks for a point-of-sale payment
- THEN the refusal code is `unsupported`, so shillinq can show the checkout as a QR code instead
- @e2e exclude backend command event; covered by PHPUnit

#### Scenario: an administrator looks up the terminals of a source
- GIVEN an administrator with the `payments.create` action
- WHEN they request `GET /api/payments/sources/mollie-live/terminals`
- THEN the terminals of that Mollie account are listed with id, label and status, and a user without the action receives HTTP 403
- @e2e exclude API route without a screen; covered by Newman
