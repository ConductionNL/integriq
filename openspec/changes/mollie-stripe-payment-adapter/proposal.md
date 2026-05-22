# Proposal: Mollie + Stripe Payment Adapter

## Summary

A unified OpenConnector adapter that integrates with Mollie and Stripe payment service providers through a single `IPaymentGateway` interface and canonical OpenRegister `Payment` schema. The adapter abstracts OAuth onboarding, webhook handling, idempotency, refund flows, SCA compliance, and token vaulting across both providers, eliminating duplicate payment infrastructure across Conduction apps while maintaining PCI DSS compliance by never storing card data locally.

## Motivation

Today, multiple Conduction apps independently implement online payments (shillinq for invoice payments, pipelinq for POS, hrmq for payroll payouts, larpingapp for ticket sales, scholiq for ouderbijdragen collection). Each reimplements OAuth, webhooks, idempotency, refunds, SCA, and token vaulting separately. Worse, each risks storing card data or payment secrets locally — a PCI nightmare.

Mollie and Stripe together cover virtually 100% of realistic use cases: Mollie provides Dutch-native methods (iDEAL, Bancontact, SEPA, Apple Pay, KBC, Belfius, EPS, Klarna, PayPal) with first-class subscription and direct debit support, while Stripe offers global coverage with strong subscription, marketplace, and SCA tooling.

A shared adapter does payment once, end-to-end, with zero card data on Conduction servers. It also normalises cost reporting so finance teams can see real margins and pick the cheapest route per transaction.

## Affected Projects

- [ ] Project: `openconnector` — hosts the unified payment adapter and schemas
- [ ] Project: `shillinq` — consumes for invoice payment links
- [ ] Project: `pipelinq` — consumes for POS and quick-pay links
- [ ] Project: `hrmq` — consumes for vakantiegeld and bonus payouts
- [ ] Project: `larpingapp` — consumes for event ticket sales
- [ ] Project: `scholiq` — consumes for ouderbijdragen collection via SEPA Direct Debit
- [ ] Project: `decidesk` / `procest` — occasional leges via iDEAL links
- [ ] Project: `mydash` — consumes cost-report API for fee dashboards

## Scope

### In Scope

- Unified `IPaymentGateway` interface abstracting Mollie and Stripe REST APIs
- OAuth2 and API-key authentication for both providers
- All payment methods supported by Mollie and Stripe (iDEAL, card, SEPA, Klarna, etc.)
- Webhook handling with signature verification and replay protection
- Full and partial refunds via a unified API
- Subscriptions / recurring billing on both providers
- Vaulted payment methods (customer tokens, no card storage)
- SCA/3DS2 compliance with customer-facing action URLs
- Per-transaction fee tracking and cost-report API
- Bank settlement reconciliation (mapping payouts to individual payments)
- Append-only audit log via `PaymentEvent` table
- Integration with OpenRegister canonical `Payment` and `Subscription` schemas

### Out of Scope

- Payout transfers from Stripe Connect to employee/vendor Stripe accounts (future enhancement via ADR)
- Direct iDEAL integrations bypassing Mollie (Mollie handles iDEAL; no separate Rabobank/ING SDK)
- Custom payment methods or non-PSP gateways
- Chargebacks beyond initial dispute detection (dispute evidence submission deferred to consumer app or manual process)
- Mobile app SDKs (Elements/Klarna SDK are web-only; native apps call this adapter as a backend service)
- Multi-currency conversion or FX handling (adapts currency-as-provided, no transform)

## Approach

The adapter follows the OpenConnector Source/Synchronization triad (ADR-005) for configuration:

1. **Sources** — one or more OAuth-connected Mollie/Stripe accounts, each with credentials, rate-limit tracking, and last-sync watermarks.
2. **Payment Entity** — canonical row in OpenRegister storing provider IDs, method, status, fees, and metadata linking to the consumer app (e.g., shillinq invoice UUID).
3. **Webhook Handlers** — per-provider signed webhook routes that fetch live state, update Payment status, and emit events.
4. **Refunds, Subscriptions, Disputes, Events** — first-class entities with full lifecycle management.
5. **Cost Reporting** — stored fees enable finance queries by provider, method, and date range.

All consumer apps interact via OpenRegister REST queries and OpenConnector create/refund APIs, never directly with Mollie/Stripe.

## New Dependencies

- `guzzlehttp/guzzle` (already in openconnector) — HTTP client for Mollie/Stripe APIs
- `symfony/serializer` (already in openconnector) — entity normalization
- No new external services beyond Mollie and Stripe test/live accounts

## Impact

- **openconnector** gains 6 new core entities (Payment, PaymentCustomer, Subscription, Refund, Dispute, PaymentEvent) and event emissions (`openconnector.payment.*`).
- **All consumer apps** can replace their ad-hoc payment flows with unified APIs; webhook routes simplify dramatically.
- **Finance systems** (shillinq, mydash) gain a single cost-report surface for fee analysis and margin tracking.
- **PCI compliance** — no card data ever touches Conduction servers; SAQ scope shrinks to a curated code path for third-party audit.

## Cross-Project Dependencies

- Depends on **OpenRegister** to host canonical Payment and Subscription schemas and provide REST query layer.
- Depends on **openconnector** to provide Source/Synchronization base triad and webhook event bus.
- Consumed by **shillinq**, **pipelinq**, **hrmq**, **larpingapp**, **scholiq**, **decidesk**, and **mydash**.
- No blocking dependencies; this adapter can be released in parallel with consumer app adoption.

## Risks

### Risk 1: Webhook Replay and Idempotency
**Severity:** High — **Mitigation:** `PaymentEvent.providerEventId` is unique-indexed to detect duplicate events. Webhook routes always check this index first before processing; a duplicate returns HTTP 200 without side effects.

### Risk 2: PCI Scope Creep
**Severity:** High — **Mitigation:** Code review gate (hydra-gate) enforces that no API endpoint accepts fields matching Luhn (card primary account number). Database schema never includes card-data columns. Stored tokens are provider-opaque only (e.g., Stripe `pm_xxx`, no PAN). Security review paired with an external PCI auditor pre-launch.

### Risk 3: Provider API Changes
**Severity:** Medium — **Mitigation:** Mollie and Stripe versioning is stable (Mollie v2 since 2018, Stripe API since 2015). OAuth token refresh is transparent. Major version upgrades (v3, v2025) are rare and announced 18+ months in advance. Adapter wraps provider APIs; consumer apps depend on OpenConnector schemas only, not raw provider responses.

### Risk 4: Billing Disputes and Chargebacks
**Severity:** Medium — **Mitigation:** The adapter stores dispute status and allows consumers to log evidence, but dispute resolution (negotiation, refund authority) stays with finance/support teams. Consumers query the Dispute entity for status; the adapter doesn't auto-refund on chargeback.

### Risk 5: Subscription Cancellation Timing
**Severity:** Low — **Mitigation:** `atPeriodEnd` behavior differs slightly between Mollie (immediate-at-cycle-end) and Stripe (`cancel_at_period_end=true`). API normalises this; consumer app always uses `atPeriodEnd` boolean; adapter translates per provider.

## Rollback Strategy

- **Schema rollback:** Migrations are applied in dependency order. If a Payment row references a PaymentCustomer, drop Payment first, then PaymentCustomer.
- **Source/Webhook rollback:** Consumer apps revert to pre-adapter code (shillinq reverts invoice payment links to inline Mollie calls, etc.). Sources remain in the database but unused.
- **Go-live pause:** If webhook handling has data corruption, disable webhook routes via `Source.active=false` to pause all incoming payments until fix is validated.

## Open Questions

1. **Multi-currency settlement:** If shillinq or decidesk operate in non-EUR currencies, does the cost-report API require FX conversion to base currency, or is fee reporting per-original-currency acceptable?
2. **Stripe Connect payout split:** Will hrmq eventually need to push payouts to Stripe-Connected accounts (employee vendors)? This is out of scope but should inform schema extensibility of `Refund` / `Payment.metadata` to link to payee accounts.
3. **Dispute evidence submission:** Should the adapter auto-submit evidence from the consumer app (e.g., shillinq shipping proof), or stay read-only and let finance teams upload manually?
