# Mollie + Stripe Payment Adapter Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- mollie-stripe-payment-adapter

## Purpose

The adapter provides a unified payment integration for Conduction applications across two major payment-service providers: Mollie (Dutch-native, strong on local methods and direct debit) and Stripe (global, strong on subscriptions and SCA tooling). It abstracts provider-specific logic behind a canonical OpenRegister `Payment` schema and a single `IPaymentGateway` interface, eliminating duplicate payment infrastructure across the fleet. All payment flows (create, refund, subscribe, reconcile) and webhook handling are managed once, end-to-end, with zero card-data storage on Conduction infrastructure, reducing PCI DSS scope to a curated code path.

This specification captures REQ-001 through REQ-010, each with acceptance scenarios (GIVEN/WHEN/THEN format). The specification is live; any changes to requirements due to provider API updates, compliance shifts, or scope clarifications are tracked via MODIFIED/REMOVED/RENAMED sections.

## ADDED Requirements

### REQ-001: Unified Create-Payment API
The adapter MUST expose a single `openconnector.payment.create($paymentRequest): Payment` API that picks the configured provider and method without the consumer app caring.

#### Scenario 1: Basic payment creation with Mollie
- GIVEN a consumer app calls `payment.create({bedrag: 1250, valuta: "EUR", methode: "ideal", referentie: "shillinq:invoice:abc"})` and a `Source` of type `mollie` is configured with priority 10
- WHEN executed
- THEN the adapter creates a Mollie payment via `POST https://api.mollie.com/v2/payments`, returns a `Payment` row with `checkoutUrl` populated, and the consumer app simply redirects the browser

#### Scenario 2: Explicit provider override
- GIVEN a method (`klarna`) is supported by both Mollie and Stripe but the request has `provider: "stripe"`
- WHEN executed
- THEN the adapter routes to Stripe regardless of priority

#### Scenario 3: Provider method support mismatch
- GIVEN a method (`przelewy24`) only supported by Stripe, and a Mollie-only deployment calls create
- WHEN the request is executed
- THEN the adapter returns a clear `PaymentError.METHOD_NOT_AVAILABLE` with the list of supported methods for the configured providers

#### Scenario 4: Automatic provider selection by cost
- GIVEN a request with `provider: "auto"` for bedrag 50.00 EUR via iDEAL, where Mollie costs €0.29 and Stripe costs 0.8% + €0.25 (total €0.65)
- WHEN executed
- THEN the adapter selects Mollie, records the decision in `Payment.metadata.providerSelected="mollie"`, and creates the payment via Mollie

### REQ-002: Mollie gateway
The adapter MUST integrate with Mollie via its REST API v2 using OAuth2 (Mollie Connect) for multi-tenant scenarios, or API key for single-org deployments.

#### Scenario 1: OAuth2 token refresh before API call
- GIVEN a `Source` of type `mollie-connect` and the org has completed OAuth2 authorisation
- WHEN the OAuth refresh-token is older than 50 minutes
- THEN the adapter transparently refreshes via `POST https://api.mollie.com/oauth2/tokens` before the next API call, updates `Source.credentials`, and succeeds

#### Scenario 2: Mollie webhook with live fetch
- GIVEN a Mollie webhook fires `POST /apps/openconnector/api/incoming/mollie/{sourceId}` with `id=tr_xxx`
- WHEN received and signature is verified
- THEN the adapter fetches the live payment via Mollie API (never trusts webhook body alone), updates the `Payment.status`, and persists a `PaymentEvent`

#### Scenario 3: Mollie payment expiry
- GIVEN a Mollie payment expires after 15 minutes unpaid
- WHEN the webhook fires with `status=expired`
- THEN the adapter transitions `Payment.status=expired`, emits `openconnector.payment.expired` event, and the consumer app (shillinq) receives the event to re-issue the payment link

#### Scenario 4: Mollie SEPA direct debit mandate
- GIVEN a Mollie customer creates a subscription with method `sepa_dd`, and the customer has a valid `defaultMandateId`
- WHEN the subscription is created
- THEN Mollie's first payment is scheduled; on webhook receipt, the adapter updates `Subscription.mandateId` and `nextPaymentDate`

### REQ-003: Stripe gateway
The adapter MUST integrate with Stripe via its REST API using OAuth2 (Stripe Connect) for multi-tenant or restricted API keys for single-org.

#### Scenario 1: Payment Intent with automatic payment methods
- GIVEN a `Source` of type `stripe-connect`
- WHEN creating a payment
- THEN the adapter creates a `PaymentIntent` with `automatic_payment_methods.enabled=true` and `setup_future_usage=off_session` when the consumer requests vaulting

#### Scenario 2: Stripe webhook with fee extraction
- GIVEN a Stripe webhook for `payment_intent.succeeded`
- WHEN received and signature is verified
- THEN the adapter verifies the Stripe signature using the per-Source endpoint secret, fetches the live intent for double-verification, updates the `Payment.status=paid`, persists `vergoeding` from the `balance_transaction` fee, and emits `openconnector.payment.paid`

#### Scenario 3: Stripe 3DS2 challenge
- GIVEN SCA is required (3DS2 step-up)
- WHEN Stripe returns `requires_action` on the PaymentIntent
- THEN the adapter sets `Payment.status=pending` and returns `next_action.redirect_to_url` so the consumer app redirects the browser to Stripe's hosted flow; after the customer completes 3DS, Stripe sends a webhook with `status=succeeded`

#### Scenario 4: Stripe subscription with automatic payment methods
- GIVEN a consumer app calls `subscription.create({klantId, bedrag: 999, interval: "1 month"})` against a Stripe source
- WHEN executed
- THEN the adapter creates a Stripe `Customer`, attaches a `PaymentMethod` (if not already vaulted), creates a `Subscription` with the price and interval, stores the `providerSubscriptionId`, and schedules the first charge

### REQ-004: Webhook handling with signature verification and replay protection
Every incoming webhook MUST be verified for signature, freshness (timestamp ≤ 5 min) and idempotency.

#### Scenario 1: Invalid Mollie signature
- GIVEN a Mollie webhook without the configured `webhookSigningSecret` matching
- WHEN received
- THEN the request is rejected with HTTP 401 and logged as a security incident

#### Scenario 2: Stripe webhook timestamp replay protection
- GIVEN a Stripe webhook with a valid signature but `timestamp` > 5 minutes old
- WHEN received
- THEN rejected with HTTP 400 (replay protection), and a warning is logged

#### Scenario 3: Duplicate webhook idempotency
- GIVEN the same provider event `evt_xxx` arrives twice (e.g., provider retry)
- WHEN received
- THEN the adapter detects the duplicate via the `PaymentEvent.providerEventId` unique index and returns HTTP 200 without double-processing (Payment row is not updated a second time)

#### Scenario 4: Valid webhook processing
- GIVEN a valid Mollie webhook with matching signature and current timestamp
- WHEN received
- THEN the adapter processes it, creates or updates a `PaymentEvent` row, updates `Payment.status` and `betaalDatum`, and returns HTTP 200

### REQ-005: Refund and partial refund
The adapter MUST support full and partial refunds via a unified `payment.refund` API.

#### Scenario 1: Full refund request
- GIVEN a `Payment` with `status=paid` and `bedrag=10000` (€100.00)
- WHEN `payment.refund({paymentId, bedrag: 10000, reden: "Customer return"})` is called
- THEN the adapter calls Mollie `POST /payments/{id}/refunds` or Stripe `POST /refunds`, persists a `Refund(status=pending)`, and returns the Refund object with the provider `providerId`

#### Scenario 2: Partial refund
- GIVEN a `Payment` with `status=paid` and `bedrag=10000`
- WHEN `payment.refund({paymentId, bedrag: 3000, reden: "Partial return"})` is called
- THEN the adapter calls the provider, persists `Refund(bedrag=3000, status=pending)`, and updates `Payment.status=partially_refunded` once webhook confirms

#### Scenario 3: Refund webhook confirmation
- GIVEN a refund webhook confirms a refund is processed
- WHEN received and verified
- THEN `Refund.status=processed`, `Payment.status=partially_refunded` (or `refunded` if cumulative refunds == original `bedrag`), and `openconnector.payment.refunded` is emitted

#### Scenario 4: Refund amount exceeds remaining
- GIVEN a refund that would exceed the remaining refundable amount (e.g., total prior refunds = €80, requesting €30 refund on €100 payment)
- WHEN attempted
- THEN the adapter rejects locally with `PaymentError.REFUND_AMOUNT_EXCEEDS_REMAINING` BEFORE calling the provider, avoiding unnecessary API calls

#### Scenario 5: Multiple partial refunds
- GIVEN a `Payment` with three `Refund` rows (€30, €20, €50) all with `status=processed`
- WHEN the total cumulative refund (€100) equals the original `bedrag`
- THEN `Payment.status=refunded` (not `partially_refunded`)

### REQ-006: Subscriptions (Mollie + Stripe)
The adapter MUST manage recurring billing subscriptions on both providers via a single API.

#### Scenario 1: Mollie subscription with SEPA mandate
- GIVEN a consumer app calls `subscription.create({klantId, bedrag: 999, valuta: "EUR", interval: "1 month", methode: "ideal"})` against a Mollie source
- WHEN executed
- THEN the adapter ensures the customer has a valid first-payment + mandate (or creates one), then creates `POST /customers/{cstId}/subscriptions`, persists the `Subscription` row with `status=active` and `nextPaymentDate=today+interval`

#### Scenario 2: Stripe subscription with payment method
- GIVEN a Stripe-routed subscription
- WHEN created with a vaulted `PaymentMethod`
- THEN the adapter creates a Stripe `Customer`, attaches the `PaymentMethod`, creates a `Subscription` with the price and interval, and stores the `providerSubscriptionId`

#### Scenario 3: Subscription cancellation at period end
- GIVEN a subscription is canceled by the customer in a self-service portal
- WHEN `subscription.cancel({subscriptionId, atPeriodEnd: true})` is called
- THEN the adapter translates `atPeriodEnd` per provider (`cancel_at_period_end=true` for Stripe; immediate-at-cycle-end for Mollie), cancels, persists `Subscription.status=canceled`, and emits `openconnector.subscription.canceled`

#### Scenario 4: Subscription webhook on next payment
- GIVEN a Stripe subscription's next scheduled payment succeeds
- WHEN the `customer.subscription.updated` webhook fires
- THEN the adapter updates `Subscription.nextPaymentDate` and emits `openconnector.subscription.payment-received`

#### Scenario 5: Subscription requires authentication
- GIVEN a subscription's off-session charge fails with `authentication_required` (e.g., SCA challenge on a vaulted card)
- WHEN detected via webhook
- THEN the adapter pauses the `Subscription`, emits `openconnector.subscription.action-required` with a customer-facing re-auth link, and the consumer app (e.g., scholiq) surfaces a "complete payment" button in the customer portal

### REQ-007: Vaulted payment methods (no card-data stored on Conduction servers)
The adapter MUST NEVER store PAN, CVC, or expiry on Conduction infrastructure. Only opaque provider tokens may be persisted in `PaymentCustomer.vaultedMethods`.

#### Scenario 1: Stripe card vaulting
- GIVEN a Stripe Elements card form returns a `PaymentMethod.id=pm_xxx` to the browser
- WHEN attached via `customer.attachPaymentMethod({klantId, providerToken: "pm_xxx"})`
- THEN the adapter calls Stripe to attach the PaymentMethod to the Customer and persists ONLY `{type: "card", last4, expMonth, expYear, providerToken}` to `vaultedMethods` — never the PAN

#### Scenario 2: PAN rejection
- GIVEN any field looking like a PAN (16+ digits matching Luhn) is sent to any adapter API endpoint
- WHEN received
- THEN the request is rejected with HTTP 422 and logged as a security incident; the request is NOT processed

#### Scenario 3: PCI audit compliance
- GIVEN PCI scope review
- WHEN auditors inspect the database
- THEN no column in `Payment*` or `Subscription*` tables contains card primary account numbers in any form; all vaulted tokens are opaque provider IDs

### REQ-008: SCA (Strong Customer Authentication) compliance
The adapter MUST handle 3DS2 step-up flows for both providers and surface them to the consumer app.

#### Scenario 1: Stripe 3DS2 redirect
- GIVEN a card payment that triggers 3DS2 challenge on Stripe
- WHEN the PaymentIntent returns `requires_action`
- THEN the adapter returns `Payment.status=pending` with `nextAction.type=redirect` and `nextAction.url` (Stripe's hosted 3DS page) for the consumer app to redirect the browser

#### Scenario 2: Mollie SCA via checkout URL
- GIVEN Mollie returns a `checkoutUrl` that itself handles 3DS internally
- WHEN the user completes the flow on Mollie's hosted pages
- THEN the standard webhook flow updates `Payment.status=succeeded` without the consumer app doing anything special

#### Scenario 3: Subscription re-authentication
- GIVEN a subscription's first off-session charge fails with `authentication_required`
- WHEN detected via webhook
- THEN the adapter pauses the subscription, emits `openconnector.subscription.action-required`, and surfaces a user-facing link for the customer to re-authenticate (Stripe's /payment_methods/{id}/action endpoint or Mollie's hosted checkout)

### REQ-009: Cost reporting and provider arbitrage
The adapter MUST persist per-transaction provider fees and expose a query API for cost reporting.

#### Scenario 1: Stripe fee extraction
- GIVEN a paid `Payment` with provider fee `0.29 EUR`
- WHEN the webhook with `balance_transaction.fee` (Stripe) is processed
- THEN `Payment.vergoeding=29` (cents) is persisted with `vergoedingValuta=EUR`

#### Scenario 2: Mollie fee extraction
- GIVEN a Mollie settlement with `settlementAmount` (netto) different from the original payment amount
- WHEN the settlement webhook is processed
- THEN the adapter calculates the fee delta and persists `Payment.vergoeding` in cents

#### Scenario 3: Cost-report API
- GIVEN mydash queries `GET /apps/openconnector/api/payments/cost-report?from=2026-01-01&to=2026-03-31&groupBy=provider,methode`
- WHEN executed
- THEN the response returns totals, fees, effective fee percentage, and average per-transaction cost per provider/method combination:
  ```json
  [
    {
      "provider": "mollie",
      "methode": "ideal",
      "transactionCount": 87,
      "totalBedrag": 12450,
      "totalVergoeding": 2523,
      "effectiveFeePercentage": 2.03
    }
  ]
  ```

#### Scenario 4: Provider arbitrage
- GIVEN a consumer app wants to pick the cheaper provider for a given method (e.g., iDEAL costs €0.29 on Mollie and 0.8% + €0.25 on Stripe)
- WHEN `payment.create` is called with `provider: "auto"`
- THEN the adapter queries the cost-report, selects the provider with the lower effective cost for the request `bedrag` and `methode`, records the decision in `Payment.metadata.providerSelected`, and routes accordingly

### REQ-010: Reconciliation with bank settlement
The adapter MUST link payouts/settlements back to individual `Payment` rows so finance teams can reconcile bank statements.

#### Scenario 1: Mollie settlement reconciliation
- GIVEN a Mollie settlement (`stl_xxx`) is created weekly
- WHEN the `openconnector:payments:reconcile` cron runs
- THEN the adapter fetches the settlement, maps every line to a `Payment.id`, and writes a `Settlement` row with `bedragNetto`, `bedragFees`, `iban`, `datum`, and `paymentIds[]`

#### Scenario 2: Stripe payout reconciliation
- GIVEN a Stripe payout (`po_xxx`) is created
- WHEN reconciled
- THEN the same `Settlement` schema is populated with `balance_transaction` items, and `paymentIds[]` is built by querying all `Payment` rows with matching `provider=stripe` and `betaalDatum` in the settlement window

#### Scenario 3: Bank statement import reconciliation
- GIVEN a bank statement (CAMT.053) is imported into shillinq with a credit matching a `Settlement.bedragNetto` (e.g., €10,000)
- WHEN the shillinq reconciliation engine queries the adapter for `Settlement` by date/amount
- THEN the matched `paymentIds[]` are returned so the bank-line can be split into the constituent payments automatically; shillinq marks each linked invoice as "reconciled to bank"

#### Scenario 4: Partial settlement mapping
- GIVEN a settlement includes 5 payments but only 3 are visible in the fetch (e.g., provider API lag)
- WHEN reconciliation runs
- THEN the adapter retries with exponential backoff (up to 7 days) and logs a warning; the Settlement is marked `status=pending` until complete

## Non-Functional Requirements

- **Performance:** Payment creation (POST) MUST complete in < 2s including provider API latency. Webhook ingestion (POST) MUST return 200 within < 500ms (signature verification + DB insert).
- **Availability:** Webhook routes MUST be highly available; failure to process a webhook is retried by the provider (Mollie and Stripe both retry for 72 hours). One-time failure on Conduction side MUST not cascade to customer experience.
- **Accessibility:** Not applicable — this is a backend service. Consumer apps render payment UIs and MUST comply with WCAG 2.1 AA.
- **Internationalization:** Payment method names and error messages MUST be available in Dutch (nl_NL) and English (en_US). Payment amounts are currency-aware; no implicit locale conversions.
- **Audit:** Every payment event (create, pending, paid, failed, refunded, expired, disputed) MUST be logged in `PaymentEvent` with the raw provider webhook body for forensic analysis and PCI scope documentation.

## Acceptance Criteria

- [ ] All 10 requirements (REQ-001 through REQ-010) are implemented and tested
- [ ] Unified `payment.create` API routes correctly based on configured Sources and method availability
- [ ] Mollie OAuth2 refresh is transparent and does not fail customer payments
- [ ] Stripe 3DS2 flows are testable with Stripe test mode (requires_action card: 4000002500003155)
- [ ] Webhook signature verification (Mollie SHA256, Stripe Ed25519) is validated with test webhooks
- [ ] Refund flows (full, partial, multiple) are tested and reconcile correctly to Payment status
- [ ] Subscriptions (Mollie + Stripe) create and cancel correctly; next-payment dates are accurate
- [ ] No PAN, CVC, or expiry is stored; code review gate confirms this
- [ ] Cost-report API returns accurate totals and effective fees
- [ ] Settlement reconciliation maps payouts to payments with 100% coverage (no orphans)
- [ ] All error scenarios (method not available, insufficient refund amount, provider down, invalid signature) return appropriate HTTP status + message
- [ ] PHPUnit tests cover core services; Newman/Postman tests cover API endpoints; integration tests verify Mollie/Stripe test accounts
- [ ] Dutch and English translation strings are present for all user-facing errors
- [ ] Code passes hydra-gates (security, style, auth checks)

## Notes

- The adapter's lifecycle is separate from consumer apps. A consumer app (e.g., shillinq) can adopt the adapter incrementally: first for new invoices, then backfilled for older ones. The adapter emits events; consumer apps listen.
- Webhook endpoints are public (`#[PublicPage]`) so providers can POST without Nextcloud session/auth. Signature verification is the only security gate. CSRF is not applicable.
- Idempotency key support on POST endpoints is future work; for now, rely on provider idempotency (webhook dedup) and schema constraints (Unique indexes).
- Mollie Magnetic integration (in-app payments without redirect) is out of scope; the adapter assumes redirect-based flows (hosted checkout for Mollie, Payment Intent for Stripe).
- Provider API version pinning: Mollie v2 (current), Stripe (versioned by account, default latest). If a major upgrade is announced, migration is captured in a successor OpenSpec change.

## MODIFIED Requirements

<!-- None yet. As the adapter evolves post-launch, requirement changes are tracked here. -->

## REMOVED Requirements

<!-- None yet. Deprecated requirements are tracked here. -->

## RENAMED Requirements

<!-- None yet. Requirement renames are tracked here. -->
