---
kind: code
depends_on: []
---

# Proposal: messaging-payments-review

## Summary

Integriq can create a Mollie payment and verify its webhook, but nobody can
see a payment, and every payment source runs on the log stub until someone
hand-edits its configuration JSON. This change adds a page to review the
payments integriq created, a way to refresh one payment's status from the
provider, and a provider step on the payment source form that switches a
source from the stub to Mollie with a brokered key and checks the key before
it goes live.

## Why

Row `integriq:msg-payment`, "Charge for something and take the payment
through a payment provider", rated partial and built. Two competitors rate
it yes:

- n8n: "packages/nodes-base/nodes/Stripe/Stripe.node.ts:73 'charge' resource
  creates and reads charges; PayPal, Paddle, Chargebee and Wise nodes ship
  as well."
- mulesoft: "https://docs.mulesoft.com/stripe-connector/latest/index.md
  Stripe Connector 1.0 gives access to Stripe customers, 'charges, refunds
  and events' from a Mule flow."

No demand row. The matrix note: "Mollie payment creation and a verified
webhook are real, but the provider defaults to the log stub. No sibling
caller was found and integriq has no page to review a charge." The row's
sibling reference is `learniq:gov-charge-for-a-course`.

## What integriq already has

- Creation. `appinfo/routes.php:157` routes `POST /api/payments` to
  `PaymentsController::create()` (`lib/Controller/PaymentsController.php:92`)
  behind the `payments.create` action (:98).
  `PaymentIntentService::createPayment()`
  (`lib/Service/PaymentIntentService.php:145`) stores a `payment_intent`
  and reports `dormant: true` when the provider is `log` (:182).
- The webhook. `appinfo/routes.php:158` routes to
  `PaymentsController::webhook()` (:141), signature gated, and
  `handleWebhook()` (:206) re-derives the status from the provider with the
  `lastOutcome` guard.
- Two bindings behind `PaymentProviderInterface`
  (`lib/Service/Payment/PaymentProviderInterface.php:38`): `LogPaymentProvider`
  and `MolliePaymentProvider`, which dispatches through the credential broker.
- A seeded dormant source, `lib/Settings/register.d/ideal-ouderbijdrage-source.json`,
  whose comment says to go live you "flip configuration.provider to
  'mollie' with a broker-held credentialRef".
- The source form's provider picker pattern for digital post,
  `src/modals/v2/SourceFormFields.vue:425` and :612, fed by
  `GET /api/digital-post/providers`.

What is missing: no manifest page names `payment_intent` (grep of
`src/manifest.json` and `src/manifest.d/` finds none), the source form has
no payment provider step, and `resolveProvider()` (:368) returns the log stub
for any provider value that is not exactly `mollie`, so a mistyped value
charges nobody and says nothing.

## What this change builds

1. A "Payments" page over `payment_intent`: amount, description, provider,
   provider status, last outcome, source and dates, filterable by status and
   source, with a detail view.
2. "Refresh status" on a payment, which runs the same re-derive as the
   webhook, for an instance the provider's webhook cannot reach.
3. A payment provider step on the source form, shown for `type: payment`:
   a provider picker built from the registered bindings, the broker key
   picker that already exists, and a "Check provider" action that tells the
   administrator whether the key works and whether it is a test or a live
   key.
4. A refusal of an unknown provider value, instead of the silent fallback to
   the stub.
5. An `authorization` block on `payment_intent`, which today has none and so
   is readable by every signed-in account.

## Out of scope

- Refunds and chargebacks initiated from integriq. The status enum carries
  `refunded` and `chargeback`, and this change shows them. Starting a refund
  is a money decision for the app that owns the charge.
- A second provider binding (Stripe, Adyen, Wero). The interface allows it;
  this change does not add one.
- learniq's and shillinq's callers of `POST /api/payments`. Those are the
  siblings' halves.

## Sibling half

`learniq:gov-charge-for-a-course` needs learniq's payment client to call
`POST /api/payments` and to listen for `nl.conduction.payment.status`. The
`integriq-adapter-psp` proposal names that client as a stub. This change does
not build it.
