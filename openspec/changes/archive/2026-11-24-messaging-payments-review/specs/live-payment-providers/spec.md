# live-payment-providers Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- live-payment-providers
- messaging-payments-review

## Purpose

An administrator reviews the payments integriq created, refreshes a
payment's status when the provider's webhook cannot reach the instance, and
moves a payment source from the log stub to a live provider with a checked,
brokered key. Row `integriq:msg-payment`.

## ADDED Requirements

### Requirement: An administrator reviews payments on the payments page (REQ-PRV-001)

Integriq MUST offer a "Payments" page listing every `payment_intent` with
its created date, description, amount and currency, provider, provider
status, last outcome and source. The page MUST filter by provider status and
by source, and MUST show every property of one payment in its detail view.
The page MUST NOT offer create or edit.

#### Scenario: a paid school fee shows as captured
- GIVEN a payment created through `POST /api/payments` that the provider reports as paid
- WHEN an administrator opens "Payments"
- THEN the payment is listed with status `paid`, last outcome `captured` and its source
- e2e: `tests/e2e/payments-review.spec.ts`

#### Scenario: an administrator narrows the list to open payments
- GIVEN payments in the states `open`, `paid` and `expired`
- WHEN the administrator filters on `open`
- THEN only the open payment is listed
- e2e: `tests/e2e/payments-review.spec.ts`

### Requirement: A payment's status is refreshed from the provider on request (REQ-PRV-002)

A principal holding the `payments.review` action MUST be able to refresh one
payment's status. The refresh MUST re-derive the status from the provider
through the same path as the signed webhook, MUST apply the `lastOutcome`
guard, and MUST emit `nl.conduction.payment.status` only when the outcome
changed.

#### Scenario: a payment behind a firewall is settled by hand
- GIVEN a payment the provider marked paid whose webhook never reached the instance
- WHEN an administrator chooses "Refresh status" on it
- THEN its status becomes `paid`, its last outcome `captured`, and one status event is emitted
- e2e: `tests/e2e/payments-review.spec.ts`

#### Scenario: a second refresh changes nothing
- GIVEN a payment whose last outcome is already `captured`
- WHEN it is refreshed again
- THEN no second status event is emitted
- @e2e exclude event emission is not visible in the browser; covered by PHPUnit on PaymentsController::refresh

#### Scenario: a user without the review action cannot refresh
- GIVEN a signed-in user who does not hold `payments.review`
- WHEN they post to `/api/payments/{id}/refresh`
- THEN the request is refused and no provider call is made
- @e2e exclude an authorization refusal on the endpoint; covered by PHPUnit

### Requirement: A payment source moves to a live provider with a checked key (REQ-PRV-003)

The source form MUST show a payment provider step for a source of type
`payment`. The provider MUST be picked from the bindings
`GET /api/payments/providers` returns, and the key MUST be picked from the
credential broker. "Check provider" MUST call the binding's read-only probe
and report whether the key is accepted and whether it is a `test` or a
`live` key. The key MUST NOT appear in the response, the form or the logs.

#### Scenario: an administrator activates Mollie in test mode
- GIVEN the seeded "iDEAL ouderbijdrage" source on the log provider
- WHEN an administrator picks Mollie, picks a brokered key and chooses "Check provider"
- THEN the form reports the key accepted in `test` mode, and after saving, a new payment returns a Mollie checkout URL
- e2e: `tests/e2e/payments-review.spec.ts`

#### Scenario: a refused key is reported before going live
- GIVEN a brokered key Mollie does not accept
- WHEN the administrator chooses "Check provider"
- THEN the form reports the refusal, and the key value is not shown
- @e2e exclude needs a real refused Mollie key; covered by PHPUnit on MolliePaymentProvider::probe with a stubbed response

### Requirement: An unknown provider value is refused, not run as the stub (REQ-PRV-004)

When a payment source names a provider integriq has no binding for, payment
creation and status refresh MUST fail with an error naming the value. A
source with no provider value MUST keep running on `log`.

#### Scenario: a mistyped provider stops a payment instead of faking it
- GIVEN a payment source whose provider is `Mollie` with a capital letter
- WHEN a sibling app posts to `/api/payments`
- THEN the response is a provider error naming `Mollie`, and no `payment_intent` is stored
- @e2e exclude a server-to-server call with no screen; covered by PHPUnit on PaymentIntentService

### Requirement: A payment is readable only by administrators and its owner (REQ-PRV-005)

The `payment_intent` schema MUST carry an `authorization` block that denies
read, create, update, delete and destroy to every group, leaving the
administrator and object owner bypasses in place.

#### Scenario: a colleague cannot read a payment
- GIVEN a signed-in user who is not an administrator and did not create the payment
- WHEN they list `payment_intent` objects through OpenRegister
- THEN no payment is returned
- @e2e exclude an OpenRegister RBAC filter; covered by an integration test on the schema import
