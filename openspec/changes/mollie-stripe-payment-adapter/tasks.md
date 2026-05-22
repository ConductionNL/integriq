# Tasks: Mollie + Stripe Payment Adapter

## Implementation Tasks

### Task 1: Create database entities and migrations
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/design.md#database-changes`
- **files**: `lib/Db/Payment.php`, `lib/Db/PaymentCustomer.php`, `lib/Db/Subscription.php`, `lib/Db/Refund.php`, `lib/Db/Dispute.php`, `lib/Db/PaymentEvent.php`, `lib/Db/Settlement.php`, `lib/Db/PaymentMapper.php` (and all other Mappers), `migrations/Version*.php`
- **acceptance_criteria**:
  - GIVEN a migration runs on a fresh Nextcloud instance, WHEN the schema is inspected, THEN all 7 tables (Payment, PaymentCustomer, Subscription, Refund, Dispute, PaymentEvent, Settlement) exist with correct columns, types, indexes, and FKs
  - AND no Payment row exists without a valid provider (enum check)
  - AND PaymentEvent.providerEventId is unique-indexed per paymentId
  - AND FKs cascade correctly (e.g., deleting a Payment cascades to Refund, Dispute, PaymentEvent)
- [ ] Create entity classes (Payment.php, etc.)
- [ ] Create Mapper classes with CRUD methods
- [ ] Generate migration files via Nextcloud migration generator
- [ ] Test migration on MySQL and PostgreSQL
- [ ] Verify indexes exist for performance-critical columns (status, betaalDatum, referentie)

### Task 2: Implement IPaymentGateway interface and router
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-001`
- **files**: `lib/Service/Gateway/IPaymentGateway.php`, `lib/Service/PaymentService.php` (routing logic), `lib/Exception/PaymentException.php`
- **acceptance_criteria**:
  - GIVEN a PaymentService with configured Sources (Mollie priority 10, Stripe priority 5), WHEN `create()` is called with method=ideal and no provider override, THEN Mollie is selected
  - AND when `create()` is called with method=przelewy24 (Stripe-only), THEN Stripe is selected
  - AND when `create()` is called with method=przelewy24 on a Mollie-only deployment, THEN `PaymentError.METHOD_NOT_AVAILABLE` is thrown with a list of supported methods
  - AND when `create()` is called with `provider="stripe"` override, THEN Stripe is always used regardless of priority
- [ ] Create IPaymentGateway interface with create(), refund(), getPayment() signatures
- [ ] Implement PaymentService router logic (Source priority, method support matrix, explicit override)
- [ ] Implement PaymentException and PaymentError enums
- [ ] Write unit tests for router logic (all 3 scenarios above)
- [ ] Write integration test with test Sources

### Task 3: Implement Mollie gateway (OAuth + API integration)
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-002`
- **files**: `lib/Service/Gateway/MollieGateway.php`, `lib/Service/MollieOAuthService.php`
- **acceptance_criteria**:
  - GIVEN a MollieGateway with a valid API key, WHEN `create({bedrag: 1250, methode: "ideal"})` is called, THEN a Mollie payment is created, a Payment row is stored, and checkoutUrl is returned
  - AND when a Mollie webhook with `id=tr_xxx` is received 1 hour after payment creation, THEN the adapter fetches live status, updates Payment.status, and emits openconnector.payment.paid
  - AND when OAuth refresh token is > 50 minutes old, THEN the next API call transparently refreshes via POST /oauth2/tokens before proceeding
  - AND when a payment expires after 15 minutes, THEN the webhook updates Payment.status=expired and emits openconnector.payment.expired
- [ ] Implement MollieGateway.create() with request to POST /payments
- [ ] Implement MollieGateway.refund() with request to POST /refunds
- [ ] Implement MollieGateway.getPayment() with fetch to GET /payments/{id}
- [ ] Implement MollieOAuthService.refreshToken() with 50-minute aging logic
- [ ] Test with Mollie sandbox credentials
- [ ] Write unit tests (mock HTTP; test request/response mapping)
- [ ] Write integration test with real Mollie sandbox

### Task 4: Implement Stripe gateway (PaymentIntent + Connect)
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-003`
- **files**: `lib/Service/Gateway/StripeGateway.php`, `lib/Service/StripeConnectService.php`
- **acceptance_criteria**:
  - GIVEN a StripeGateway with valid API key, WHEN `create({bedrag: 12999, methode: "creditcard"})` is called, THEN a Stripe PaymentIntent is created with `automatic_payment_methods.enabled=true`, a Payment row is stored, and `status=created` or `status=pending` (if 3DS required)
  - AND when a PaymentIntent webhook for `payment_intent.succeeded` is received, THEN the adapter verifies signature, fetches live state, updates Payment.status=paid, extracts vergoeding from balance_transaction, and emits openconnector.payment.paid
  - AND when a PaymentIntent requires 3DS (`requires_action`), THEN Payment.status=pending, next_action.url is returned to redirect the browser, and after 3DS completion, a subsequent webhook marks it paid
  - AND when a subscription's first charge succeeds, THEN Subscription.nextPaymentDate is set correctly
- [ ] Implement StripeGateway.create() with request to POST /payment_intents
- [ ] Implement StripeGateway.refund() with request to POST /refunds
- [ ] Implement StripeGateway.getPayment() with fetch to GET /payment_intents/{id}
- [ ] Implement StripeConnectService for multi-tenant OAuth (if needed)
- [ ] Test with Stripe test mode and 3DS test card (4000002500003155)
- [ ] Write unit tests
- [ ] Write integration test with real Stripe test account

### Task 5: Implement webhook handlers (Mollie + Stripe)
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-004`
- **files**: `lib/Controller/WebhookController.php`, `lib/Service/WebhookService.php`
- **acceptance_criteria**:
  - GIVEN a Mollie webhook with valid signature and current timestamp, WHEN POST /api/incoming/mollie/{sourceId} is called, THEN the signature is verified, the duplicate event is detected (if any), Payment row is updated, PaymentEvent is created, and HTTP 200 is returned
  - AND when a webhook with invalid signature is received, THEN HTTP 401 is returned and logged
  - AND when a Stripe webhook with timestamp > 5 minutes old is received, THEN HTTP 400 is returned (replay protection)
  - AND when the same Mollie event `evt_xxx` arrives twice, THEN the first is processed, the second is skipped via unique index, and both return HTTP 200
- [ ] Create WebhookController with routes for POST /api/incoming/mollie/{sourceId} and POST /api/incoming/stripe/{sourceId}
- [ ] Mark routes as `#[PublicPage] #[NoCSRFRequired]`
- [ ] Implement Mollie signature verification (SHA256 HMAC)
- [ ] Implement Stripe signature verification (Ed25519)
- [ ] Implement timestamp freshness check (≤ 5 min)
- [ ] Implement idempotency detection via PaymentEvent.providerEventId unique index
- [ ] Write unit tests for signature verification
- [ ] Write integration test with test webhooks from sandbox

### Task 6: Implement refund API (full + partial)
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-005`
- **files**: `lib/Controller/RefundController.php`, `lib/Service/RefundService.php`
- **acceptance_criteria**:
  - GIVEN a Payment with bedrag=10000, WHEN refund(bedrag=3000) is called, THEN a Refund(status=pending) is created, the provider API is called, and HTTP 201 is returned
  - AND when the refund webhook confirms processing, THEN Refund.status=processed and Payment.status=partially_refunded
  - AND when cumulative refunds equal the original bedrag, THEN Payment.status=refunded (not partially_refunded)
  - AND when refund amount exceeds remaining (total refunded + new request > original), THEN PaymentError.REFUND_AMOUNT_EXCEEDS_REMAINING is thrown BEFORE calling the provider
- [ ] Create RefundService with refund() method
- [ ] Add validation to reject refund if amount exceeds remaining
- [ ] Implement provider-agnostic refund routing (Mollie vs. Stripe)
- [ ] Create RefundController with POST /api/payments/{paymentId}/refunds
- [ ] Handle refund webhook (update Refund.status, Payment.status, emit event)
- [ ] Write unit tests for validation logic
- [ ] Write integration test (full + partial refunds)

### Task 7: Implement subscription API (create + cancel)
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-006`
- **files**: `lib/Controller/SubscriptionController.php`, `lib/Service/SubscriptionService.php`
- **acceptance_criteria**:
  - GIVEN a SubscriptionService with Mollie source, WHEN create({klantId, bedrag: 999, interval: "1 month"}) is called, THEN a Mollie subscription is created via POST /customers/{cstId}/subscriptions, a Subscription row is stored, and nextPaymentDate is set to today+1month
  - AND when a Stripe source is used, THEN a Stripe Customer, PaymentMethod, and Subscription are created with correct interval
  - AND when cancel({subscriptionId, atPeriodEnd: true}) is called, THEN the subscription is canceled at period end (translated per provider), Subscription.status=canceled, and event is emitted
  - AND when a subscription's first payment fails with authentication_required, THEN Subscription is paused, status=action_required, and openconnector.subscription.action-required event is emitted with re-auth link
- [ ] Implement SubscriptionService.create() with provider routing
- [ ] Implement SubscriptionService.cancel() with atPeriodEnd translation per provider
- [ ] Implement MollieGateway.createSubscription() and .cancelSubscription()
- [ ] Implement StripeGateway.createSubscription() and .cancelSubscription()
- [ ] Handle subscription webhooks (payment scheduled, payment succeeded, payment failed with auth_required)
- [ ] Write unit tests
- [ ] Write integration test (create Mollie + Stripe subscriptions, cancel, verify webhook handling)

### Task 8: Implement vaulted payment methods (card tokenization)
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-007`
- **files**: `lib/Service/PaymentCustomerService.php`, `lib/Controller/PaymentCustomerController.php`
- **acceptance_criteria**:
  - GIVEN a Stripe PaymentMethod token pm_xxx, WHEN attachPaymentMethod({klantId, providerToken: "pm_xxx"}) is called, THEN the adapter attaches it to Stripe Customer, stores only {type, last4, expMonth, expYear, providerToken} in vaultedMethods (no PAN), and returns PaymentCustomer
  - AND when a request with a 16-digit Luhn-valid field is sent to ANY endpoint, THEN HTTP 422 is returned, the request is NOT processed, and a security incident is logged
- [ ] Implement PaymentCustomerService with attachPaymentMethod(), listVaultedMethods()
- [ ] Implement input validation: reject any Luhn-matching 16+ digit field
- [ ] Ensure vaultedMethods JSON never includes full PAN or CVC
- [ ] Write unit tests for Luhn rejection
- [ ] Write integration test with real Stripe test PaymentMethod

### Task 9: Implement SCA (3DS2) compliance
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-008`
- **files**: `lib/Service/PaymentService.php` (extend with SCA handling), `lib/Controller/PaymentController.php`
- **acceptance_criteria**:
  - GIVEN a Stripe payment that requires 3DS, WHEN the PaymentIntent returns requires_action, THEN Payment.status=pending, nextAction.url is returned, and the consumer app redirects the browser
  - AND after the customer completes 3DS, a webhook updates Payment.status=succeeded
  - AND when a Mollie payment is returned with a checkoutUrl, THEN the consumer app is redirected; Mollie handles 3DS internally on the checkout page
  - AND when a subscription's first charge fails with authentication_required, THEN the adapter pauses the subscription, emits openconnector.subscription.action-required, and surfaces a re-auth link
- [ ] Handle Stripe `requires_action` response in create()
- [ ] Store nextAction.url in Payment row (if needed) or return directly
- [ ] Test with Stripe 3DS test card (4000002500003155)
- [ ] Test Mollie hosted checkout 3DS flow
- [ ] Test subscription off-session charge with 3DS challenge
- [ ] Write integration test

### Task 10: Implement cost reporting (fee tracking + arbitrage)
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-009`
- **files**: `lib/Service/CostReportService.php`, `lib/Controller/CostReportController.php`
- **acceptance_criteria**:
  - GIVEN paid Payments with various vergoeding amounts from Mollie and Stripe, WHEN GET /api/cost-report?from=2026-01-01&to=2026-03-31&groupBy=provider,methode is called, THEN the response returns accurate totals, fees, and effective fee percentages per provider/method combination
  - AND when a payment.create() call includes provider="auto", THEN the adapter queries cost-report internally, selects the provider with the lowest effective fee for the requested bedrag and methode, records the decision in metadata, and routes accordingly
- [ ] Implement CostReportService.getCostReport() with groupBy logic
- [ ] Add provider arbitrage logic to PaymentService.create()
- [ ] Create CostReportController with GET /api/cost-report (admin-only)
- [ ] Ensure fee extraction from provider webhooks populates vergoeding correctly
- [ ] Write unit tests for cost calculation
- [ ] Write integration test (compare Mollie vs. Stripe fees)

### Task 11: Implement settlement reconciliation (bank payout mapping)
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-010`
- **files**: `lib/Service/SettlementService.php`, `lib/Cron/ReconcileSettlements.php`, `lib/Controller/SettlementController.php`
- **acceptance_criteria**:
  - GIVEN a Mollie settlement stl_xxx with 10 payment lines, WHEN the openconnector:payments:reconcile cron runs, THEN a Settlement row is created with bedragNetto, bedragFees, iban, and paymentIds[] mapped to Payment rows, and the Settlement is queryable via GET /api/settlements
  - AND when a Stripe payout po_xxx is reconciled, THEN the same Settlement schema is populated with balance_transaction line items
  - AND when a bank statement import in shillinq queries GET /api/settlements?datum=2026-05-22&bedragNetto=10000, THEN the matched Settlement with paymentIds[] is returned so the import engine can split the bank line into constituent payments
  - AND when a settlement's mapping is incomplete (e.g., provider API lag), THEN the Settlement is marked status=pending and reconciliation retries with exponential backoff for up to 7 days
- [ ] Implement SettlementService.reconcile() for Mollie (fetch settlements, map lines to Payments)
- [ ] Implement SettlementService.reconcile() for Stripe (fetch payouts, map balance_transactions to Payments)
- [ ] Create ReconcileSettlements cron job (weekly)
- [ ] Implement SettlementController with GET /api/settlements and query filters (date, amount)
- [ ] Add retry logic for incomplete settlements (exponential backoff, 7-day TTL)
- [ ] Write unit tests
- [ ] Write integration test (create Mollie + Stripe settlements, verify mapping)

### Task 12: Implement event emissions
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#non-functional-requirements`
- **files**: `lib/Service/PaymentService.php`, `lib/Service/SubscriptionService.php`, `lib/Service/WebhookService.php` (emit events)
- **acceptance_criteria**:
  - GIVEN a payment is created, WHEN PaymentService.create() completes, THEN openconnector.payment.created event is emitted with the Payment object
  - AND when a webhook updates Payment.status=paid, THEN openconnector.payment.paid is emitted
  - AND when a webhook updates Payment.status=expired, THEN openconnector.payment.expired is emitted
  - AND when a refund is processed, THEN openconnector.payment.refunded is emitted with Refund object
  - AND when a subscription is canceled, THEN openconnector.subscription.canceled is emitted
  - AND when a subscription requires authentication, THEN openconnector.subscription.action-required is emitted with re-auth link
- [ ] Emit openconnector.payment.* events from PaymentService and WebhookService
- [ ] Emit openconnector.subscription.* events from SubscriptionService and WebhookService
- [ ] Document event format (event class, payload shape)
- [ ] Write unit test (mock event dispatcher, verify events are emitted)

### Task 13: Integration tests (end-to-end Mollie + Stripe flows)
- **spec_ref**: All REQ-001 through REQ-010
- **files**: `tests/Integration/PaymentFlowTest.php`, `tests/Integration/SubscriptionFlowTest.php`, `tests/Integration/WebhookFlowTest.php`
- **acceptance_criteria**:
  - GIVEN Mollie sandbox credentials, WHEN a full payment flow (create → webhook → mark paid) is executed, THEN the Payment row is updated, event is emitted, and cost-report reflects the fee
  - AND when a full refund flow (create → refund → webhook) is executed, THEN the Refund row transitions to processed and Payment reflects the refund
  - AND when a Stripe payment with 3DS challenge is created, THEN requires_action is returned and after webhook (simulated 3DS completion), Payment is marked paid
  - AND when a subscription is created, charged, and canceled, THEN Subscription.status transitions correctly and events are emitted
  - AND when duplicate webhooks are sent (provider retry), THEN the first is processed, the second is skipped
- [ ] Create integration test suite with test Mollie and Stripe accounts
- [ ] Test full payment flow (create, webhook, status transitions)
- [ ] Test refund flow (full and partial)
- [ ] Test subscription flow (create, charge, cancel)
- [ ] Test webhook idempotency (duplicate events)
- [ ] Test 3DS challenge and recovery
- [ ] Test settlement reconciliation
- [ ] Verify all events are emitted correctly

### Task 14: Security review and PCI compliance audit
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/design.md#security-considerations`, `openspec/changes/mollie-stripe-payment-adapter/spec.md#req-007`
- **files**: All PHP files; database schema
- **acceptance_criteria**:
  - GIVEN a code review, WHEN all lib/ files are inspected, THEN no PAN, CVC, or expiry is stored, logged, or transmitted
  - AND when the database schema is audited, THEN no column in Payment*, Subscription*, or PaymentCustomer* tables contains card data
  - AND when a request with a 16-digit Luhn-valid field is sent to any endpoint, THEN HTTP 422 is returned
  - AND when webhook signatures are verified, THEN both Mollie (SHA256) and Stripe (Ed25519) are correctly validated
  - AND when OAuth tokens are logged, THEN they are redacted
- [ ] Security code review (check for card data storage, logging, transmission)
- [ ] Database schema audit (no card-data columns)
- [ ] Signature verification test (Mollie + Stripe)
- [ ] PCI DSS compliance checklist (SAQ-A or SAQ-A-EP applicable)
- [ ] Internal security sign-off before launch

### Task 15: Documentation and translations
- **spec_ref**: `openspec/changes/mollie-stripe-payment-adapter/spec.md#non-functional-requirements`
- **files**: `docs/Payment_Adapter.md`, `docs/Mollie_Integration.md`, `docs/Stripe_Integration.md`, `translations/nl_NL/openconnector.po`, `translations/en_US/openconnector.po`
- **acceptance_criteria**:
  - GIVEN consumer app developers, WHEN they read docs/Payment_Adapter.md, THEN they understand the unified API (create, refund, subscribe, cost-report) and how to integrate
  - AND when they read docs/Mollie_Integration.md and docs/Stripe_Integration.md, THEN they understand provider-specific setup (OAuth, API keys, webhooks)
  - AND when translations are extracted, THEN all user-facing strings (error messages, status labels) are in Dutch (nl_NL) and English (en_US)
- [ ] Write docs/Payment_Adapter.md (API overview, quick start)
- [ ] Write docs/Mollie_Integration.md (OAuth setup, sandbox credentials, testing)
- [ ] Write docs/Stripe_Integration.md (Connect setup, API keys, 3DS testing)
- [ ] Extract and translate all strings to nl_NL and en_US
- [ ] Add i18n to all error messages and status enums

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria (or automated integration tests)
- [ ] Code review against spec requirements
- [ ] Security review for PCI compliance
- [ ] Performance testing (payment creation < 2s, webhook ingestion < 500ms)

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for PaymentService, MollieGateway, StripeGateway, WebhookService, CostReportService, SettlementService (tests/Unit/)
  - Target: ≥ 80% code coverage for critical paths (create, refund, webhook handling, signature verification)
  - Tests: routing logic, provider method support, cost calculation, Luhn rejection, signature verification, timestamp validation, idempotency detection
- [ ] Newman/Postman tests for API endpoints (tests/Integration/postman/)
  - GET /api/payments, POST /api/payments, POST /api/payments/{id}/refunds, GET /api/cost-report, GET /api/settlements
  - Responses: correct HTTP status, JSON schema validation, pagination
- [ ] Browser tests (Playwright MCP) for webhook routes (if applicable; webhooks are backend-only)
  - Fallback: manual curl/httpie tests with test webhooks
- [ ] Integration tests with real Mollie and Stripe sandbox accounts (tests/Integration/)
  - Payment flow (create, webhook, status transitions)
  - Refund flow (full, partial)
  - Subscription flow (create, charge, cancel)
  - 3DS challenge and recovery
  - Settlement reconciliation
- [ ] All tests pass (`composer test`, `newman run`)
- [ ] Code coverage report reviewed (target ≥ 80% for critical code)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/`
  - docs/Payment_Adapter.md (API overview)
  - docs/Mollie_Integration.md (setup guide)
  - docs/Stripe_Integration.md (setup guide)
- [ ] Consumer app integration guide (e.g., "How to use the payment adapter in shillinq")
- [ ] Screenshots captured (e.g., webhook configuration, cost-report dashboard) and committed to `docs/images/`
- [ ] README.md updated to reference the payment adapter

## i18n (company-wide ADR-007)

- [ ] Dutch (`nl_NL`) translation strings added for all user-facing errors and status labels
- [ ] English (`en_US`) translation strings added
- [ ] Strings extracted to `translations/*.po` files
- [ ] Verify translations appear in error responses and API responses
