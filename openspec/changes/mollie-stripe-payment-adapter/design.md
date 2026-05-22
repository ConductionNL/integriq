# Design: Mollie + Stripe Payment Adapter

## Architecture Overview

The adapter sits in openconnector as a sixth major subsystem alongside Source/Synchronization (ADR-005) and the mapping/event-bus engines. It follows the same patterns:

```
Consumer App (shillinq, pipelinq, etc.)
    ↓
OpenRegister REST (GET /payments, POST /subscriptions)
    ↓
OpenConnector PaymentService
    ├─ IPaymentGateway interface
    │  ├─ MollieGateway (OAuth2 or API key)
    │  └─ StripeGateway (OAuth2 or API key)
    ├─ PaymentService (create, refund, get, list)
    ├─ SubscriptionService (create, list, cancel)
    ├─ WebhookHandlers (incoming mollie/{sourceId}, stripe/{sourceId})
    └─ CostReportService (fees by provider/method/date)
    ↓
Database (Payment, PaymentCustomer, Subscription, Refund, Dispute, PaymentEvent, Settlement)
    ├─ FK Payment.klantId → PaymentCustomer.id
    ├─ FK Subscription.klantId → PaymentCustomer.id
    ├─ FK Refund.paymentId → Payment.id
    ├─ FK Dispute.paymentId → Payment.id
    └─ FK PaymentEvent.paymentId → Payment.id
    ↓
Provider APIs (Mollie API v2, Stripe REST API)
```

Sources configure provider credentials and priority; a `Payment.create` call picks the highest-priority Source that supports the requested method, unless `provider` is explicit in the request.

## API Design

All endpoints follow ADR-002 (Nextcloud conventions): `/index.php/apps/openconnector/api/{resource}`, pagination via `_page` + `_limit`, errors with HTTP status + `message` field.

### `POST /api/payments`
Creates a new payment intent with one provider.

**Request:**
```json
{
  "bedrag": 1250,
  "valuta": "EUR",
  "methode": "ideal",
  "referentie": "shillinq:invoice:550e8400-e29b-41d4-a716-446655440000",
  "klantId": "550e8400-e29b-41d4-a716-446655440000",
  "omschrijving": "Invoice #2024-001 payment",
  "provider": "mollie",
  "metadata": {"invoiceId": "2024-001", "customerId": "ABC123"},
  "redirectUrl": "https://shillinq.local/invoices/550e8400-e29b-41d4-a716-446655440000",
  "webhookUrl": "https://openconnector.local/api/incoming/mollie/src_abc123"
}
```

**Response:**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "provider": "mollie",
  "providerId": "tr_WDqYK6vllg",
  "bedrag": 1250,
  "valuta": "EUR",
  "methode": "ideal",
  "referentie": "shillinq:invoice:550e8400-e29b-41d4-a716-446655440000",
  "klantId": "550e8400-e29b-41d4-a716-446655440000",
  "omschrijving": "Invoice #2024-001 payment",
  "status": "created",
  "checkoutUrl": "https://www.mollie.com/checkout/select-method/...",
  "redirectUrl": "https://shillinq.local/invoices/550e8400-e29b-41d4-a716-446655440000",
  "webhookUrl": "https://openconnector.local/api/incoming/mollie/src_abc123",
  "metadata": {"invoiceId": "2024-001", "customerId": "ABC123"},
  "vergoeding": null,
  "vergoedingValuta": null,
  "betaalDatum": null,
  "aangemaaktOp": "2026-05-22T14:30:00Z",
  "verlooptOp": "2026-05-22T14:45:00Z"
}
```

### `GET /api/payments/{id}`
Fetches a single payment by UUID.

**Response:** Payment object (as above).

### `GET /api/payments`
Lists payments with pagination.

**Query params:** `_page=1`, `_limit=50`, optional `status=paid`, `provider=mollie`, `from=2026-01-01`, `to=2026-03-31`.

**Response:**
```json
{
  "data": [/* Payment objects */],
  "total": 142,
  "page": 1,
  "pages": 3
}
```

### `POST /api/payments/{id}/refunds`
Requests a refund (full or partial).

**Request:**
```json
{
  "bedrag": 300,
  "reden": "Customer return"
}
```

**Response:**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440001",
  "paymentId": "550e8400-e29b-41d4-a716-446655440000",
  "providerId": "re_4ePb7j43PJuQVcQM6Z88nq",
  "bedrag": 300,
  "valuta": "EUR",
  "reden": "Customer return",
  "status": "pending",
  "aangemaaktOp": "2026-05-22T14:35:00Z",
  "verwerktOp": null
}
```

### `POST /api/subscriptions`
Creates a recurring subscription.

**Request:**
```json
{
  "klantId": "550e8400-e29b-41d4-a716-446655440000",
  "bedrag": 999,
  "valuta": "EUR",
  "interval": "1 month",
  "methode": "ideal",
  "provider": "mollie",
  "omschrijving": "Monthly service contract"
}
```

**Response:**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440002",
  "provider": "mollie",
  "providerSubscriptionId": "sub_rVAXvSSUtG",
  "klantId": "550e8400-e29b-41d4-a716-446655440000",
  "bedrag": 999,
  "valuta": "EUR",
  "interval": "1 month",
  "startDatum": "2026-05-22",
  "eindDatum": null,
  "status": "active",
  "nextPaymentDate": "2026-06-22",
  "methode": "ideal",
  "mandateId": "mdt_h3hAhExHWZVsFhBp8FhWzQ"
}
```

### `POST /api/subscriptions/{id}/cancel`
Cancels a subscription.

**Request:**
```json
{
  "atPeriodEnd": true
}
```

**Response:** Updated Subscription object (status = `canceled`).

### `GET /api/cost-report`
Finance dashboard: fees by provider, method, date range.

**Query params:** `from=2026-01-01`, `to=2026-03-31`, `groupBy=provider,methode` (required), `provider=mollie` (optional filter).

**Response:**
```json
{
  "data": [
    {
      "provider": "mollie",
      "methode": "ideal",
      "transactionCount": 87,
      "totalBedrag": 12450,
      "totalVergoeding": 2523,
      "effectiveFeePercentage": 2.03,
      "averageTransactionFee": 29
    },
    {
      "provider": "stripe",
      "methode": "creditcard",
      "transactionCount": 34,
      "totalBedrag": 5600,
      "totalVergoeding": 448,
      "effectiveFeePercentage": 0.8,
      "averageTransactionFee": 13
    }
  ]
}
```

### `GET /api/settlements`
Lists bank payouts with linked payments.

**Query params:** `_page=1`, `_limit=50`, optional `from=2026-01-01`, `to=2026-03-31`.

**Response:**
```json
{
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440003",
      "provider": "mollie",
      "providerSettlementId": "stl_QM6BTfVF33",
      "bedragNetto": 10000,
      "bedragFees": -250,
      "iban": "NL91ABNA0417164300",
      "datum": "2026-05-22",
      "paymentIds": ["550e8400-e29b-41d4-a716-446655440000", "550e8400-e29b-41d4-a716-446655440001"]
    }
  ],
  "total": 5,
  "page": 1,
  "pages": 1
}
```

### `POST /api/incoming/mollie/{sourceId}` (webhook)
Mollie sends signed webhook — no response body; always return 200 if signature is valid and event processed.

### `POST /api/incoming/stripe/{sourceId}` (webhook)
Stripe sends signed webhook — no response body; always return 200 if signature is valid and event processed.

## Database Changes

Six new tables in openconnector, all with UUID primary keys and timestamps (`aangemaaktOp`, optionally `verlooptOp` / `verwerktOp`).

### `Payment` table
```sql
CREATE TABLE oc_payment (
  id CHAR(36) PRIMARY KEY,
  provider VARCHAR(20) NOT NULL CHECK (provider IN ('mollie', 'stripe')),
  providerId VARCHAR(255) NOT NULL,
  bedrag DECIMAL(10,2) NOT NULL,
  valuta CHAR(3) NOT NULL,
  omschrijving TEXT,
  referentie VARCHAR(255),
  klantId CHAR(36),
  methode VARCHAR(50) NOT NULL,
  status VARCHAR(20) NOT NULL,
  redirectUrl TEXT,
  webhookUrl TEXT,
  checkoutUrl TEXT,
  metadata JSON,
  vergoeding INT,
  vergoedingValuta CHAR(3),
  betaalDatum DATETIME,
  aangemaaktOp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verlooptOp DATETIME,
  UNIQUE KEY (provider, providerId),
  FOREIGN KEY (klantId) REFERENCES oc_payment_customer(id) ON DELETE SET NULL,
  INDEX (status),
  INDEX (referentie),
  INDEX (betaalDatum)
);
```

### `PaymentCustomer` table
```sql
CREATE TABLE oc_payment_customer (
  id CHAR(36) PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  providerCustomerId VARCHAR(255) NOT NULL,
  email VARCHAR(255),
  naam VARCHAR(255),
  defaultMandateId VARCHAR(255),
  vaultedMethods JSON,
  metadata JSON,
  aangemaaktOp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (provider, providerCustomerId),
  INDEX (email)
);
```

### `Subscription` table
```sql
CREATE TABLE oc_subscription (
  id CHAR(36) PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  providerSubscriptionId VARCHAR(255) NOT NULL,
  klantId CHAR(36) NOT NULL,
  bedrag DECIMAL(10,2) NOT NULL,
  valuta CHAR(3) NOT NULL,
  interval VARCHAR(50) NOT NULL,
  startDatum DATE NOT NULL,
  eindDatum DATE,
  status VARCHAR(20) NOT NULL,
  nextPaymentDate DATE,
  methode VARCHAR(50) NOT NULL,
  mandateId VARCHAR(255),
  aangemaaktOp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (provider, providerSubscriptionId),
  FOREIGN KEY (klantId) REFERENCES oc_payment_customer(id) ON DELETE CASCADE,
  INDEX (status),
  INDEX (nextPaymentDate)
);
```

### `Refund` table
```sql
CREATE TABLE oc_refund (
  id CHAR(36) PRIMARY KEY,
  paymentId CHAR(36) NOT NULL,
  providerId VARCHAR(255),
  bedrag DECIMAL(10,2) NOT NULL,
  valuta CHAR(3) NOT NULL,
  reden VARCHAR(255),
  status VARCHAR(20) NOT NULL,
  aangemaaktOp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verwerktOp DATETIME,
  FOREIGN KEY (paymentId) REFERENCES oc_payment(id) ON DELETE CASCADE,
  INDEX (paymentId),
  INDEX (status)
);
```

### `Dispute` table
```sql
CREATE TABLE oc_dispute (
  id CHAR(36) PRIMARY KEY,
  paymentId CHAR(36) NOT NULL,
  providerId VARCHAR(255),
  bedrag DECIMAL(10,2) NOT NULL,
  reden VARCHAR(50),
  status VARCHAR(20) NOT NULL,
  evidenceDueBy DATETIME,
  evidence JSON,
  outcome VARCHAR(50),
  aangemaaktOp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (paymentId) REFERENCES oc_payment(id) ON DELETE CASCADE,
  INDEX (paymentId),
  INDEX (status)
);
```

### `PaymentEvent` table (audit log)
```sql
CREATE TABLE oc_payment_event (
  id CHAR(36) PRIMARY KEY,
  paymentId CHAR(36) NOT NULL,
  providerEventId VARCHAR(255),
  eventType VARCHAR(50) NOT NULL,
  tijdstip DATETIME NOT NULL,
  rawPayload JSON,
  aangemaaktOp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (paymentId) REFERENCES oc_payment(id) ON DELETE CASCADE,
  UNIQUE KEY (paymentId, providerEventId),
  INDEX (eventType),
  INDEX (tijdstip)
);
```

### `Settlement` table
```sql
CREATE TABLE oc_settlement (
  id CHAR(36) PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  providerSettlementId VARCHAR(255) NOT NULL,
  bedragNetto DECIMAL(10,2) NOT NULL,
  bedragFees DECIMAL(10,2),
  iban VARCHAR(255),
  datum DATE NOT NULL,
  paymentIds JSON,
  aangemaaktOp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (provider, providerSettlementId),
  INDEX (datum)
);
```

## Nextcloud Integration

- **Controllers:** `lib/Controller/PaymentController.php` (CRUD for Payment, list, get, create); `RefundController`, `SubscriptionController`, `SettlementController`; `WebhookController` for incoming Mollie/Stripe webhooks.
- **Services:** `lib/Service/PaymentService.php` (business logic, provider routing); `MollieGateway`, `StripeGateway` (provider-specific REST calls); `WebhookService` (signature verification, replay detection); `CostReportService` (fee aggregation); `SubscriptionService`.
- **Mappers/Entities:** `lib/Db/Payment.php`, `PaymentCustomer.php`, `Subscription.php`, `Refund.php`, `Dispute.php`, `PaymentEvent.php`, `Settlement.php` (standard Nextcloud ORM entities); `lib/Db/PaymentMapper.php`, etc.
- **Events/Hooks:** `openconnector.payment.created`, `openconnector.payment.pending`, `openconnector.payment.paid`, `openconnector.payment.failed`, `openconnector.payment.refunded`, `openconnector.payment.expired`, `openconnector.subscription.created`, `openconnector.subscription.canceled`, `openconnector.subscription.action-required`.

## Security Considerations

### Card Data
- **PCI DSS scope:** Zero. No PAN, CVC, or expiry ever stored, logged, or transmitted through Conduction servers. Only opaque provider tokens (e.g., Stripe `pm_xxx`, Mollie customer IDs) are persisted.
- **Input validation:** Any API endpoint that receives a field matching Luhn (16+ digits) is rejected with HTTP 422 and logged as a security incident.
- **Storage audit:** Regular database audits (quarterly, paired with external PCI assessor) confirm no card data in `Payment*` or `Subscription*` tables.

### Webhook Signature Verification
- **Mollie:** SHA256 HMAC of request body; signature provided in `X-Mollie-Signature` header. Verified before processing.
- **Stripe:** Ed25519 signature of `timestamp.body` in `Stripe-Signature` header. Verified before processing.
- **Replay protection:** Timestamp in webhook MUST be ≤ 5 minutes old (UTC); rejected otherwise.
- **Idempotency:** `PaymentEvent.providerEventId` (unique indexed) ensures a duplicate event (e.g., Mollie retry) is detected and skipped without side effects.

### OAuth Token Storage
- **Encryption:** `Source.credentials` column is encrypted at rest per ADR-007 (pending implementation). For now, plaintext secrets are marked as HIGH-RISK.
- **Refresh token aging:** Mollie refresh tokens are transparent-refreshed if older than 50 minutes (configurable); Stripe tokens are long-lived and only refreshed on 401.
- **No logging:** Provider API tokens are NEVER logged, even at debug level. Redacted in error messages.

### CORS & Public Routes
- Webhook routes are `#[PublicPage] #[NoCSRFRequired]` (webhooks don't have CSRF tokens).
- Payment creation from the browser (e.g., pipelinq POS) uses the consumer app's CSRF token, not the adapter's.
- `GET /api/cost-report` requires admin authentication (finance teams only).

## NL Design System

Not applicable — this is a backend payment service. Consumer apps (shillinq, pipelinq, etc.) render payment UIs using NL Design System components (forms, buttons, spinners); the adapter provides no UI.

## File Structure

```
lib/
  Controller/
    PaymentController.php
    RefundController.php
    SubscriptionController.php
    DisputeController.php
    SettlementController.php
    WebhookController.php
  Service/
    PaymentService.php
    RefundService.php
    SubscriptionService.php
    WebhookService.php
    CostReportService.php
    Gateway/
      IPaymentGateway.php
      MollieGateway.php
      StripeGateway.php
  Db/
    Payment.php
    PaymentCustomer.php
    Subscription.php
    Refund.php
    Dispute.php
    PaymentEvent.php
    Settlement.php
    PaymentMapper.php
    PaymentCustomerMapper.php
    SubscriptionMapper.php
    RefundMapper.php
    DisputeMapper.php
    PaymentEventMapper.php
    SettlementMapper.php
  Exception/
    PaymentException.php
    PaymentError.php
tests/
  Unit/
    Service/PaymentServiceTest.php
    Gateway/MollieGatewayTest.php
    Gateway/StripeGatewayTest.php
    Service/WebhookServiceTest.php
  Integration/
    Controller/PaymentControllerTest.php
docs/
  Payment_Adapter.md
  Mollie_Integration.md
  Stripe_Integration.md
```

## Seed Data

### Schema: `payment` (openconnector register)

| Field | Payment 1: Invoice | Payment 2: Refund | Payment 3: Pending SCA |
|-------|-------------------|------------------|----------------------|
| id | `12345678-1234-1234-1234-123456789012` | `12345678-1234-1234-1234-123456789013` | `12345678-1234-1234-1234-123456789014` |
| provider | `mollie` | `mollie` | `stripe` |
| providerId | `tr_WDqYK6vllg` | `tr_7UhSqVzEHU` | `pi_1A8OWZIsinZiN7LFYAB0` |
| bedrag | `5000` (€50.00) | `2500` (€25.00) | `12999` (€129.99) |
| valuta | `EUR` | `EUR` | `EUR` |
| methode | `ideal` | `ideal` | `creditcard` |
| status | `paid` | `partially_refunded` | `pending` |
| omschrijving | Ouderbijdrage 2026 Q2 | Refund: duplicate payment | Event ticket - Early Bird |
| referentie | `scholiq:bijdrage:q2-2026-de-andes` | `scholiq:bijdrage:q2-2026-de-andes` | `larpingapp:ticket:fantasy-fest-2026` |
| klantId | `87654321-4321-4321-4321-210987654321` | `87654321-4321-4321-4321-210987654321` | `11111111-2222-2222-2222-333333333333` |
| checkoutUrl | `https://www.mollie.com/checkout/select-method/...` | (none) | `https://stripe.com/3ds2/...` |
| redirectUrl | `https://scholiq.local/betaalbevestiging` | `https://scholiq.local/betaalbevestiging` | `https://larpingapp.local/checkout/success` |
| metadata | `{"schoolId": "12", "kindId": "1"}` | `{"refundReason": "duplicate"}` | `{"eventId": "fantasy-fest", "tier": "early-bird"}` |
| vergoeding | `29` (€0.29) | `15` (€0.15) | `165` (€1.65) |
| vergoedingValuta | `EUR` | `EUR` | `EUR` |
| betaalDatum | `2026-05-20T10:15:00Z` | `2026-05-20T10:15:00Z` | (null) |
| aangemaaktOp | `2026-05-20T09:45:00Z` | `2026-05-20T09:45:00Z` | `2026-05-22T14:00:00Z` |
| verlooptOp | `2026-05-20T10:00:00Z` | `2026-05-20T10:00:00Z` | `2026-05-22T14:05:00Z` |

**Related items per object:**
- Files: (none — payment links are ephemeral, consumer app stores invoice/ticket as separate record)
- Notes: Shillinq stores notes on invoice (linked via referentie). Larpingapp stores notes on ticket order.
- Tasks: None
- Contacts: Scholiq links to `klantId` which maps to OpenRegister `Klant` (parent contact)

### Schema: `payment_customer` (openconnector register)

| Field | Customer 1: Parent | Customer 2: Corporate | Customer 3: Ticket Buyer |
|-------|-------------------|----------------------|--------------------------|
| id | `87654321-4321-4321-4321-210987654321` | `22222222-3333-3333-3333-444444444444` | `11111111-2222-2222-2222-333333333333` |
| provider | `mollie` | `mollie` | `stripe` |
| providerCustomerId | `cst_95ngtd4F5V` | `cst_h3hAhExHWZVsFhBp8FhWzQ` | `cus_P1a2b3c4d5e6f` |
| email | `j.van.den.berg@voorbeeld.nl` | `accounts@grote-fabrikant.nl` | `janwillem@persoonlijk.nl` |
| naam | J. van den Berg | Grote Fabrikant BV | Jan-Willem Pieterzoon |
| defaultMandateId | `mdt_h3hAhExHWZVsFhBp8FhWzQ` | (null) | (null) |
| vaultedMethods | `[{"type":"creditcard","last4":"4242","expMonth":12,"expYear":2027,"providerToken":"pm_abc123"}]` | `[{"type":"ideal","providerToken":"mdt_xxx"}]` | `[]` |
| metadata | `{"klantId":"k-scholiq-12"}` | `{"klantId":"k-pipelinq-99"}` | `{"userId":"sem-de-jong"}` |
| aangemaaktOp | `2026-05-10T08:30:00Z` | `2026-04-15T14:22:00Z` | `2026-05-20T12:05:00Z` |

**Related items:**
- OpenRegister `Klant` (customer record in schooliq/pipelinq)
- Nextcloud `User` (if consumer app syncs users; larpingapp may link to user object)

### Schema: `subscription` (openconnector register)

| Field | Sub 1: Service | Sub 2: Donation |
|-------|----------------|-----------------|
| id | `44444444-5555-5555-5555-666666666666` | `55555555-6666-6666-6666-777777777777` |
| provider | `mollie` | `stripe` |
| providerSubscriptionId | `sub_rVAXvSSUtG` | `sub_1A8O...` |
| klantId | `87654321-4321-4321-4321-210987654321` | `11111111-2222-2222-2222-333333333333` |
| bedrag | `1500` (€15.00/month) | `5000` (€50.00/month) |
| valuta | `EUR` | `EUR` |
| interval | `1 month` | `1 month` |
| startDatum | `2026-05-22` | `2026-05-01` |
| eindDatum | (null) | (null) |
| status | `active` | `active` |
| nextPaymentDate | `2026-06-22` | `2026-06-01` |
| methode | `ideal` | `creditcard` |
| mandateId | `mdt_h3hAhExHWZVsFhBp8FhWzQ` | (null) |
| aangemaaktOp | `2026-05-22T09:00:00Z` | `2026-05-01T10:30:00Z` |

**Related items:**
- Service contract (pipelinq) or donation profile (larpingapp)
- Payment Customer (vaulted method for recurring charge)

## Trade-offs

**Trade-off 1: Provider routing (fixed vs. dynamic)**
- **Alternative:** Route payment to the "cheapest" provider on every call. Query cost-report, compute fee per provider/method, route accordingly.
- **Chosen:** Route to configured priority order unless explicitly specified. Cost arbitrage is opt-in and delegated to the consumer app (e.g., mydash exposes a recommendation API; shillinq can call it at invoice-creation time and embed `provider=stripe` in the payment link).
- **Why:** Payment routing-on-the-fly complicates error handling (if the cheapest provider is down, retry on the next?); cost reports are real-time updates and may lag. Allowing the consumer app to pre-decide (via cost-report API) keeps the payment path simple and testable.

**Trade-off 2: Webhook idempotency (event log vs. idempotency key)**
- **Alternative:** Issue a consumer-supplied idempotency key on payment creation; detect duplicate webhook by checking the key.
- **Chosen:** Detect duplicates via `PaymentEvent.providerEventId` (provider-supplied event ID). If the same provider event arrives twice, the unique index prevents double-processing.
- **Why:** Webhooks are the single source of truth; the provider's event ID is the authoritative idempotency token. Consumer-supplied keys are optional for non-webhook calls (idempotency headers on API calls).

**Trade-off 3: Refund reversal (full vs. partial support)**
- **Alternative:** Allow "refund reversal" (undo a refund). Stripe supports this; Mollie does not.
- **Chosen:** One-way refund flow. Once a refund is created, only dispute evidence or manual bank reversal can undo it.
- **Why:** Keeping Stripe and Mollie on one path avoids confusion. Consumer apps can store undo/correction as a follow-up refund note if needed.

**Trade-off 4: Settlement reconciliation (real-time vs. batch)**
- **Alternative:** Update settlements on every payment webhook (real-time, but expensive queries to group payouts).
- **Chosen:** Batch reconciliation via `openconnector:payments:reconcile` cron job (weekly). Settlements are read-only after creation.
- **Why:** Settlements rarely change after booking, and the cron window is small (one query per settlement, not per payment). Real-time updates would require complex aggregation logic.
