---
kind: code
depends_on: []
---

# Proposal: live-payment-providers

## Summary

Add a live payment-provider connector to OpenConnector so pay-by-link flows in
Conduction apps can actually collect money. The connector follows the
openconnector idiom established by `peppol-access-point-connector` and
`psd2-ais-bank-feed-connector`: a provider abstraction (interface + sandbox
`log` provider + a generic REST provider), credentials brokered through
`BrokeredCallService` (never cleartext), CloudEvents via `EventService`, and a
signature-gated inbound webhook via the same HMAC pattern used by the two
prior connectors. Mollie is the first real binding — it is the dominant NL PSP
and iDEAL the dominant NL payment method. The connector creates a payment
(amount, currency, description, redirect + webhook URLs, method incl. iDEAL),
returns a checkout URL, and on the provider's inbound webhook re-derives the
authoritative payment status (never trusting an unauthenticated status claim),
maps it onto the status vocabulary shillinq's `PaymentReconciliationService`
already expects, and emits one CloudEvent per state change.

## Motivation

shillinq's AR pay-by-link flow (`ar-invoice-payment-links`) and its
`PaymentRequestWebhookController` + `PaymentReconciliationService` are real,
tested, and wired end to end — but they sit on top of
`shillinq/lib/Service/External/Mollie/MolliePaymentAdapterInterface`, whose
only implementation today is `LogMolliePaymentAdapter`: a dormant stub that
logs the intent and returns a synthetic `PAYMENT_DEFERRED` result. No live
payment provider exists anywhere in the fleet. A payment link therefore looks
fully wired (schema, webhook, reconciliation, GL settlement) but can never
actually collect a euro — the money never moves. Per ADR-022 the production
binding for an external PSP belongs in the app that owns integrations
(openconnector), never re-implemented inside a leaf app. This change delivers
that binding.

## Affected Projects

- [ ] Project: `openconnector` — new `PaymentsController` (create-payment +
  signed inbound webhook), a `PaymentProviderInterface` abstraction with `Log`
  + `Mollie` (generic-REST-shaped) providers, a `payment_intent` OR schema,
  and status CloudEvent emission.
- [ ] Project: `shillinq` — no code change here; shillinq's existing
  `MolliePaymentAdapterInterface` production binding (not built in this
  change) will target the endpoints/events this change introduces. See
  "Cross-Project Dependencies" for the exact contract and the follow-up this
  unblocks.

## Scope

### In Scope

- `PaymentProviderInterface` (`createPayment`, `fetchPaymentStatus`,
  `isDormant`) with two bindings: `LogPaymentProvider` (sandbox, deterministic,
  no network — used by all tests) and `MolliePaymentProvider` (Mollie Payments
  API v2, dispatched through `BrokeredCallService`, credential resolved via
  `credentialRef`, never cleartext).
- `POST /api/payments` — create a payment: amount `{value, currency}`,
  description, `redirectUrl`, `webhookUrl`, optional `method` (`ideal` is the
  default/primary method), and passthrough `metadata`. Returns a
  provider-assigned payment id, native provider status, and a checkout URL.
- `POST /api/payments/webhook` — signature-gated inbound receive path (same
  HMAC scheme/verification code as `peppol-access-point-connector`'s
  `webhook_signature` pattern). On a verified call the connector re-derives
  the authoritative status from the provider (never trusts an unauthenticated
  status claim in the body — the real Mollie webhook body carries only an
  `id`), maps the provider-native status onto shillinq's `OUTCOME_*`
  vocabulary (`authorized|captured|failed|voided`), and emits exactly one
  `nl.conduction.payment.status` CloudEvent per actual state change.
- Idempotency: a replayed webhook for a payment id whose mapped outcome has
  already been applied is a no-op — no second CloudEvent is emitted.
- Credential brokering (ADR-007): the Mollie API key is resolved via
  `authentication.credentialRef`, never stored/logged in plaintext.
- Provider abstraction designed so a second method/provider (see "Open
  Questions" — Wero) can be added without reshaping the interface.

### Out of Scope

- Building shillinq's `RestMolliePaymentAdapter` production binding itself —
  that is a follow-up change in shillinq that consumes the contract this
  change publishes (per ADR-022, leaf apps are not touched by the app that
  owns the integration).
- Refunds, recurring/mandate payments, and non-iDEAL methods beyond what
  Mollie's `method` parameter already accepts passthrough (`creditcard`,
  `bancontact`, `sepadirectdebit`) — only creation + status lifecycle for a
  one-off payment is in scope.
- Wero as an actual second provider — iDEAL retires end-2027 and Wero is its
  successor; this change only ensures the abstraction does not need to
  reshape when that binding is added later (tracked as a follow-up).

## Approach

Model the PSP connection as an openconnector `source` (`type: payment`) whose
`configuration` selects a provider (`log` | `mollie`) and carries
`authentication.credentialRef` + a `webhookSignature` block (mirrors
`peppol-access-point-connector`'s source-level webhook config). A narrow
`PaymentProviderInterface` is implemented by `LogPaymentProvider` (sandbox)
and `MolliePaymentProvider` (real Mollie Payments API v2, brokered). Creating a
payment persists a `payment_intent` OR object (the idempotency + audit-trail
record) and returns the checkout URL. The inbound webhook is signature-gated
exactly like `PeppolController::inbound()`, re-fetches the authoritative status
from the provider (defence against a spoofed/replayed status claim), updates
the `payment_intent`, and — only on an actual mapped-outcome change — emits
`nl.conduction.payment.status` with a payload shaped exactly like
`PaymentReconciliationService::reconcile()`'s `$event` parameter
(`paymentIntentId`, `outcome`, `errorCode`, `errorMessage`,
`settlementReference`, `gatewayFeeAmount`) so a future shillinq event listener
can pass the CloudEvent's `data` straight into `reconcile()` with zero
transformation. Details in design.md.

## New Dependencies

None. Reuses `BrokeredCallService`/`CredentialBrokerService`, the
`EventService` CloudEvent fan-out, the openconnector webhook-signature HMAC
pattern (`WebhookSignatureService`), and the existing source-management
conventions.

## Impact

- New: `lib/Controller/PaymentsController.php`,
  `lib/Service/Payment/PaymentProviderInterface.php`,
  `LogPaymentProvider.php`, `MolliePaymentProvider.php`,
  `PaymentIntentService.php`, `lib/Exception/PaymentProviderException.php`,
  `appinfo/routes.php` entries, a `payment_intent` schema in
  `lib/Settings/openconnector_register.json` + seed data.
- Reused: `BrokeredCallService`, `EventService`, `WebhookSignatureService`.

## Cross-Project Dependencies

- shillinq's `MolliePaymentAdapterInterface` production binding (a follow-up
  change, not built here) targets `POST /api/payments` for `createPayment()`
  and can either poll `fetchPaymentStatus` or wire an event listener for
  `nl.conduction.payment.status` to feed `PaymentReconciliationService`
  directly. Contract owned here; shillinq is not modified by this change.

## Risks

### Risk 1: Real Mollie webhooks carry no signature or status — only an `id`

**Severity:** Medium — **Mitigation:** the connector never trusts a status
claim in the inbound body. It treats the inbound call as a mere trigger and
always re-fetches the authoritative status from the provider via the brokered
credential before mapping/emitting anything. The connector's own
`webhookSignature` gate (mirroring `peppol-access-point-connector`) additionally
rejects unsigned/tampered calls at the transport layer before any provider
call is made, so an attacker cannot even trigger a spurious re-fetch without
the shared secret.

### Risk 2: A certified/real Mollie account is required for the live path

**Severity:** Medium — **Mitigation:** ship the `log`/sandbox provider so the
whole path (create → checkout URL → webhook → status mapping → event →
idempotency) is demonstrable end-to-end in dev/CI with no real Mollie account
or credential; production swaps in the `mollie` provider + a real
`credentialRef`.

### Risk 3: Duplicate/replayed webhook double-reconciles a payment

**Severity:** Low — **Mitigation:** the `payment_intent`'s last-applied
outcome is checked before emitting; a replay that resolves to the same
already-applied outcome is a no-op (no second CloudEvent, no double-reconcile
downstream).

## Rollback Strategy

The connector is additive. Revert by removing the new controller/service/
provider classes and routes, and the `payment_intent` schema entry; no
existing source, event, or webhook-signature behaviour changes, so removal
cannot regress current integrations. shillinq is untouched by this change and
needs no rollback of its own.

## Open Questions

- iDEAL is scheduled to retire end-2027 in favour of Wero (EPI's
  pan-European wallet/account-to-account scheme). This change does not
  implement Wero — it only ensures `PaymentProviderInterface` does not need to
  be reshaped when a Wero (or Wero-via-Mollie) binding is added. Tracked as a
  follow-up, not blocking.
