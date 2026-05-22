---
status: draft
---
# Mollie + Stripe Payment Adapter

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Adapters > Adapter-catalogus (E-facturatie) / Adapters

**Rationale:** Adapter type  
_Source: /tmp/ia-doc-dec-cat-conn.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Provide a unified OpenConnector adapter for online payments via the two payment-service providers that cover virtually 100% of the Conduction fleet's realistic use cases: Mollie (Dutch-native, with first-class iDEAL, Bancontact, SEPA Direct Debit, Apple Pay, credit card, KBC, Belfius, EPS, Klarna, PayPal) and Stripe (global, with strong subscription, marketplace and SCA tooling). One adapter, two gateways behind a single `IPaymentGateway` interface, all consumed via the canonical OpenConnector `Payment` schema in OpenRegister.

Today multiple Conduction apps need to take or send payments. shillinq must collect invoice payments (link in invoice → iDEAL → marked paid). pipelinq POS needs a card-terminal/Tap-to-Pay path and a quick-pay link. hrmq pays vakantiegeld and bonus run-offs. larpingapp sells event tickets. scholiq collects ouderbijdragen. Without a shared adapter, every app re-implements OAuth onboarding, webhook handling, idempotency, refund flow, SCA, vault-tokens, and reconciliation. Worse, each app would end up storing card-data or payment-secrets locally — a PCI nightmare.

This adapter does it once, end-to-end, and consciously stays out of the card-data path: no PAN, no CVC, no expiry ever touches a Conduction server. Everything is either tokenised by Mollie/Stripe (via their hosted checkout or Elements/Components) or vaulted on their side, with us holding only the customer/token reference. Subscriptions, recurring direct debits, refunds, disputes, and payouts are all first-class.

The adapter also normalises cost reporting (per-transaction fees vary wildly between PSPs and methods) so finance teams can see real margins across providers and methods, and pick the cheapest route per transaction.

## Data Model

All schemas live in the shared `openconnector` register:

- `Payment` — the canonical payment envelope. Fields: `id` (UUID), `provider` (enum: `mollie`, `stripe`), `providerId` (e.g. Mollie `tr_xxx` or Stripe `pi_xxx`), `bedrag` (decimal), `valuta` (ISO 4217), `omschrijving`, `referentie` (consumer-app correlation id, e.g. shillinq invoice UUID), `klantId` (FK to `PaymentCustomer`), `methode` (enum from canonical list: `ideal`, `bancontact`, `sepa_dd`, `creditcard`, `apple_pay`, `google_pay`, `paypal`, `klarna`, `eps`, `przelewy24`, `sofort`, `giropay`, `multibanco`, `mb_way`), `status` (enum: `created`, `pending`, `authorized`, `paid`, `failed`, `expired`, `canceled`, `refunded`, `partially_refunded`, `disputed`, `chargeback`), `redirectUrl`, `webhookUrl`, `checkoutUrl` (hosted checkout link), `metadata` (free JSON for the consumer app), `vergoeding` (provider fee in cents), `vergoedingValuta`, `betaalDatum`, `aangemaaktOp`, `verlooptOp`.
- `PaymentCustomer` — vaulted customer reference per provider. Fields: `id`, `provider`, `providerCustomerId` (Mollie `cst_xxx`, Stripe `cus_xxx`), `email`, `naam`, `defaultMandateId` (for SEPA), `vaultedMethods` (array of `{type, last4, expMonth, expYear, providerToken}` — NEVER PAN), `metadata`. Mapped 1:1 to a Nextcloud user OR an OpenRegister `Klant` object.
- `Subscription` — recurring billing. Fields: `id`, `provider`, `providerSubscriptionId`, `klantId`, `bedrag`, `valuta`, `interval` (e.g. `1 month`, `1 year`), `startDatum`, `eindDatum`, `status` (`active`, `paused`, `canceled`, `expired`), `nextPaymentDate`, `methode`, `mandateId`.
- `Refund` — `id`, `paymentId`, `providerId`, `bedrag`, `valuta`, `reden`, `status` (`pending`, `processed`, `failed`), `aangemaaktOp`, `verwerktOp`.
- `Dispute` — `id`, `paymentId`, `providerId`, `bedrag`, `reden` (`fraudulent`, `product_not_received`, `duplicate`, `subscription_canceled`, `other`), `status` (`needs_response`, `under_review`, `won`, `lost`), `evidenceDueBy`, `evidence` (JSON), `outcome`.
- `PaymentEvent` — append-only event log per payment for full audit: `paymentId`, `eventType` (mirrors status enum + provider-specific), `tijdstip`, `rawPayload` (the verified webhook body).

## Requirements

### REQ-001: Unified Create-Payment API
The adapter MUST expose a single `openconnector.payment.create($paymentRequest): Payment` API that picks the configured provider and method without the consumer app caring.

- GIVEN a consumer app calls `payment.create({bedrag: 12.50, valuta: "EUR", methode: "ideal", referentie: "shillinq:invoice:abc"})` and a `Source` of type `mollie` is configured with priority 10, WHEN executed, THEN the adapter creates a Mollie payment via `POST https://api.mollie.com/v2/payments`, returns a `Payment` row with `checkoutUrl` populated, and the consumer app simply redirects the browser.
- GIVEN a method (`klarna`) is supported by both Mollie and Stripe but the request has `provider: "stripe"`, WHEN executed, THEN the adapter routes to Stripe regardless of priority.
- GIVEN a method (`przelewy24`) only supported by Stripe, WHEN a Mollie-only deployment calls create, THEN the adapter returns a clear `PaymentError.METHOD_NOT_AVAILABLE` with the list of supported methods for the configured providers.

### REQ-002: Mollie gateway
The adapter MUST integrate with Mollie via its REST API v2 using OAuth2 (Mollie Connect) for multi-tenant scenarios, or API key for single-org deployments.

- GIVEN a `Source` of type `mollie-connect` and the org has completed OAuth2 authorisation, WHEN the OAuth refresh-token is older than 50 minutes, THEN the adapter transparently refreshes via `POST https://api.mollie.com/oauth2/tokens` before the next API call.
- GIVEN a Mollie webhook fires `POST /apps/openconnector/api/incoming/mollie/{sourceId}` with `id=tr_xxx`, WHEN received, THEN the adapter fetches the live payment via Mollie API (never trusts webhook body alone), updates the `Payment.status`, and persists a `PaymentEvent`.
- GIVEN a Mollie payment expires after 15 minutes unpaid, WHEN the webhook fires with `status=expired`, THEN the adapter transitions `Payment.status=expired` and emits `openconnector.payment.expired` so shillinq can re-issue the link.

### REQ-003: Stripe gateway
The adapter MUST integrate with Stripe via its REST API using OAuth2 (Stripe Connect) for multi-tenant or restricted API keys for single-org.

- GIVEN a `Source` of type `stripe-connect`, WHEN creating a payment, THEN the adapter creates a `PaymentIntent` with `automatic_payment_methods.enabled=true` and `setup_future_usage=off_session` when the consumer requests vaulting.
- GIVEN a Stripe webhook for `payment_intent.succeeded`, WHEN received, THEN the adapter verifies the Stripe signature using the per-Source endpoint secret, fetches the live intent for double-verification, updates the `Payment.status=paid`, persists `vergoeding` from the `balance_transaction`, and emits `openconnector.payment.paid`.
- GIVEN SCA is required (3DS2 step-up), WHEN Stripe returns `requires_action`, THEN the adapter sets `Payment.status=pending` and returns `next_action.redirect_to_url` so the consumer redirects accordingly.

### REQ-004: Webhook handling with signature verification and replay protection
Every incoming webhook MUST be verified for signature, freshness (timestamp ≤ 5 min) and idempotency.

- GIVEN a Mollie webhook without the configured `webhookSigningSecret` matching, WHEN received, THEN the request is rejected with HTTP 401 and logged.
- GIVEN a Stripe webhook with a valid signature but `timestamp` > 5 minutes old, WHEN received, THEN rejected with HTTP 400 (replay protection).
- GIVEN the same provider event `evt_xxx` arrives twice (provider retry), WHEN received, THEN the adapter detects the duplicate via the `PaymentEvent.providerEventId` unique index and returns HTTP 200 without double-processing.

### REQ-005: Refund and partial refund
The adapter MUST support full and partial refunds via a unified `payment.refund` API.

- GIVEN a `Payment` with `status=paid` and `bedrag=100.00`, WHEN `payment.refund({paymentId, bedrag: 30.00, reden: "Customer return"})` is called, THEN the adapter calls Mollie `POST /payments/{id}/refunds` or Stripe `POST /refunds`, persists a `Refund(status=pending)`, and the consumer app receives a `Refund` object with the providerId.
- GIVEN a refund webhook confirms processed, WHEN received, THEN `Refund.status=processed`, `Payment.status=partially_refunded` (or `refunded` if cumulative refunds == original), and `openconnector.payment.refunded` is emitted.
- GIVEN a refund that exceeds the remaining refundable amount, WHEN attempted, THEN the adapter rejects locally with `PaymentError.REFUND_AMOUNT_EXCEEDS_REMAINING` BEFORE calling the provider.

### REQ-006: Subscriptions (Mollie + Stripe)
The adapter MUST manage recurring billing subscriptions on both providers via a single API.

- GIVEN a consumer app calls `subscription.create({klantId, bedrag: 9.99, valuta: "EUR", interval: "1 month", methode: "ideal"})` against a Mollie source, WHEN executed, THEN the adapter ensures the customer has a valid first-payment + mandate, then creates `POST /customers/{cstId}/subscriptions`.
- GIVEN a Stripe-routed subscription, WHEN created, THEN the adapter creates a `Customer`, attaches a `PaymentMethod`, creates a `Subscription` with the price and interval, and stores the `providerSubscriptionId`.
- GIVEN a subscription is canceled by the customer in a self-service portal exposed by the consumer app, WHEN `subscription.cancel({subscriptionId, atPeriodEnd: true})` is called, THEN the adapter translates `atPeriodEnd` per provider (`cancel_at_period_end=true` for Stripe; immediate-at-cycle-end for Mollie).

### REQ-007: Vaulted payment methods (no card-data stored on Conduction servers)
The adapter MUST NEVER store PAN, CVC, or expiry on Conduction infrastructure. Only opaque provider tokens may be persisted in `PaymentCustomer.vaultedMethods`.

- GIVEN a Stripe Elements card form returns a `PaymentMethod.id=pm_xxx` to the browser, WHEN attached via `customer.attachPaymentMethod({klantId, providerToken: "pm_xxx"})`, THEN the adapter calls Stripe to attach the PaymentMethod to the Customer and persists ONLY `{type: "card", last4, expMonth, expYear, providerToken}` to `vaultedMethods`.
- GIVEN any field looking like a PAN (16 digits matching Luhn) is ever sent to any adapter API endpoint, WHEN received, THEN the request is rejected with HTTP 422 and logged as a security incident.
- GIVEN PCI scope review, WHEN auditors inspect the database, THEN no column in `Payment*` or `Subscription*` tables contains card primary account numbers in any form.

### REQ-008: SCA (Strong Customer Authentication) compliance
The adapter MUST handle 3DS2 step-up flows for both providers and surface them to the consumer app.

- GIVEN a card payment that triggers 3DS2 challenge on Stripe, WHEN the PaymentIntent returns `requires_action`, THEN the adapter returns `Payment.status=pending` with `nextAction.type=redirect` and `nextAction.url` for the consumer app to redirect the browser.
- GIVEN Mollie returns a `checkoutUrl` that itself handles 3DS internally, WHEN the user completes the flow, THEN the standard webhook flow updates `Payment.status` without the consumer app doing anything special.
- GIVEN a subscription's first off-session charge fails with `authentication_required`, WHEN detected, THEN the adapter pauses the subscription, emits `openconnector.subscription.action-required`, and surfaces a user-facing link for the customer to re-authenticate.

### REQ-009: Cost reporting and provider arbitrage
The adapter MUST persist per-transaction provider fees and expose a query API for cost reporting.

- GIVEN a paid `Payment` with provider fee `0.29 EUR`, WHEN the webhook with `balance_transaction.fee` (Stripe) or `settlementAmount` delta (Mollie) is processed, THEN `Payment.vergoeding=29` (cents) is persisted with `vergoedingValuta=EUR`.
- GIVEN mydash queries `GET /apps/openconnector/api/payments/cost-report?from=2026-01-01&to=2026-03-31&groupBy=provider,methode`, WHEN executed, THEN the response returns totals, fees, effective fee percentage, and average per-transaction cost per provider/method combination.
- GIVEN a consumer app wants to pick the cheaper provider for a given method (e.g. iDEAL costs `€0.29` on Mollie and `0.8% + €0.25` on Stripe), WHEN `payment.create` is called with `provider: "auto"`, THEN the adapter selects the provider with the lower effective cost for the request `bedrag` and `methode`, recording the decision in `Payment.metadata`.

### REQ-010: Reconciliation with bank settlement
The adapter MUST link payouts/settlements back to individual `Payment` rows so finance teams can reconcile bank statements.

- GIVEN a Mollie settlement (`stl_xxx`) is created weekly, WHEN the `openconnector:payments:reconcile` cron runs, THEN the adapter fetches the settlement, maps every line to a `Payment.id`, and writes a `Settlement` row with `bedragNetto`, `bedragFees`, `iban`, `datum`, and `paymentIds[]`.
- GIVEN a Stripe payout (`po_xxx`), WHEN reconciled, THEN the same `Settlement` schema is populated with `balance_transaction` items.
- GIVEN a bank statement (CAMT.053) imported into shillinq with a credit matching a `Settlement.bedragNetto`, WHEN the shillinq reconciliation engine queries the adapter for `Settlement` by date/amount, THEN the matched `paymentIds[]` are returned so the bank-line can be split into the constituent payments automatically.

## Standards & Sources

- Mollie API v2 (`https://docs.mollie.com/reference/v2`) — Payments, Customers, Mandates, Subscriptions, Refunds, Settlements, OAuth (Mollie Connect).
- Stripe API (`https://stripe.com/docs/api`) — PaymentIntents, SetupIntents, Customers, PaymentMethods, Subscriptions, Refunds, Disputes, Connect, Webhooks.
- PCI DSS v4.0 — Self-Assessment Questionnaire A applicability (hosted checkout / Elements only).
- PSD2 / EBA RTS on Strong Customer Authentication.
- ISO 11649 — Structured Creditor Reference (linked to Peppol Factuur `betaalReferentie`).
- ISO 20022 CAMT.053 — bank statement reconciliation input format.
- iDEAL 2.0 specification (relevant for direct iDEAL integrations if a deployment ever needs to bypass Mollie).
- Forum Standaardisatie — iDEAL listed; SEPA SCT/DD ISO 20022 listed.

## Cross-app integration

- **shillinq** is the headline consumer: every outbound invoice gets a `Payment` link via this adapter; the `payment.paid` event marks the invoice paid; refunds tie back to credit notes; reconciliation imports settlements against bank statements.
- **pipelinq** POS uses the adapter for in-store payments (Tap-to-Pay via Stripe Terminal, or quick-pay link via Mollie); subscriptions for recurring service contracts.
- **hrmq** uses the adapter to push vakantiegeld and bonus payouts via Stripe Connect transfers (employees as Stripe-Connected accounts is out of scope; for now hrmq pushes payment instructions to the payroll system, but ad-hoc bonus payouts to non-employees go through this adapter).
- **larpingapp** sells event tickets — `Payment.metadata.eventId` links the payment to the ticket; refunds drive refund-to-original-method automatically.
- **scholiq** collects ouderbijdragen via SEPA Direct Debit mandates (Mollie); the adapter manages the mandate lifecycle.
- **decidesk / procest** rarely take payments themselves but use the adapter for occasional leges (e.g. omgevingsvergunning leges via iDEAL link in the besluit notification).
- **mydash** consumes the `cost-report` API to show provider-fee dashboards and per-method conversion rates.
- **openregister** hosts the canonical `Payment` / `Subscription` schemas; every consumer queries via OpenRegister rather than poking the adapter directly.

## Target users

- **Conduction app developers** building any app that needs to take money — they get one consistent payment surface instead of a per-app integration.
- **Finance teams in MKB and corporates** running shillinq/pipelinq — they get unified reconciliation across providers and methods.
- **Public-sector treasurers** (gemeenten with leges, water boards with belastingen) who currently use a single PSP per organisation; this adapter makes provider switching a one-click `Source` swap.
- **End customers** of any Conduction-app deployment — they get the right local payment method (iDEAL for NL, Bancontact for BE, Przelewy24 for PL, SEPA DD for recurring, etc.) without the app developer thinking about it.
- **PCI compliance officers** — they get one auditable code path that demonstrably never touches card data, drastically reducing SAQ scope.
