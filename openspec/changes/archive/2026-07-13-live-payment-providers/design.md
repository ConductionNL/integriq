# Design: live-payment-providers

## Architecture Overview

```
        producing app (shillinq AR pay-by-link, future binding)
                                   │
        POST /api/payments                      POST /api/payments/webhook
        {amount, description,                    (Mollie calls back with {id})
         redirectUrl, webhookUrl,                            │
         method, metadata}                                   │
                                   │                          ▼
                                   ▼               webhookSignature verify (HMAC)
   PaymentsController.create ──► PaymentIntentService ──► PaymentsController.webhook
                                   │                          │
                                   ▼                          ▼
                    PaymentProviderInterface        re-fetch authoritative status
                    ├─ LogPaymentProvider (sandbox)  via PaymentProviderInterface
                    └─ MolliePaymentProvider ─────►  ::fetchPaymentStatus()
                       (via BrokeredCallService)                │
                                   │                             ▼
                        persists                    map provider status → OUTCOME_*
                     payment_intent (OR)             (shillinq's vocabulary)
                                   │                             │
                                   └──────── idempotency guard ──┘
                                                     │
                                                     ▼
                                   emit nl.conduction.payment.status
                                   {paymentIntentId, outcome, errorCode,
                                    errorMessage, settlementReference,
                                    gatewayFeeAmount} via EventService
```

Nothing new is invented for transport, brokering, or events: credentials flow
through the existing `BrokeredCallService`/`CredentialBrokerService`, HMAC
verification reuses the same scheme as `peppol-access-point-connector`'s
inbound webhook, and status changes fan out through the existing
`EventService`. The only new abstraction is the payment-provider seam,
mirroring `PeppolAccessPointProviderInterface` and
`Psd2AggregatorProviderInterface`.

### Why re-fetch instead of trusting the webhook body

Real Mollie webhooks are unauthenticated and carry only `{id}` — no status, no
signature. Trusting a caller-supplied status in the body would let anyone who
learns (or guesses) a payment id fabricate a "paid" event — a direct money
bug. The connector therefore treats the inbound POST as a bare trigger: it is
gated by the connector's own `webhookSignature` HMAC (source-level shared
secret, not something Mollie provides) so an attacker without the secret
cannot even trigger a re-fetch, and once past that gate the connector always
calls `fetchPaymentStatus()` against the provider using the brokered
credential — the provider's own authoritative answer is the only thing ever
mapped and emitted.

## API Design

### `POST /api/payments`

**Request:**
```json
{
  "sourceSlug": "mollie-payments",
  "amount": { "value": "10.00", "currency": "EUR" },
  "description": "Invoice INV-2026-0042",
  "redirectUrl": "https://shillinq.example/payments/return",
  "webhookUrl": "https://nc.example/apps/openconnector/api/payments/webhook",
  "method": "ideal",
  "metadata": {
    "invoiceId": "00000000-0000-0000-0000-000000000000",
    "administrationId": "00000000-0000-0000-0000-000000000000",
    "correlationId": "00000000-0000-0000-0000-000000000000"
  }
}
```
**Response:**
```json
{
  "paymentIntentId": "00000000-0000-0000-0000-000000000000",
  "providerPaymentId": "tr_mock_a1b2c3d4",
  "paymentStatus": "open",
  "checkoutUrl": "https://sandbox.mollie.example/checkout/tr_mock_a1b2c3d4",
  "dormant": false,
  "extras": { "method": "ideal" }
}
```
A malformed request (missing `amount.value`/`amount.currency`/`description`)
returns HTTP 400. An unreachable/erroring provider returns HTTP 502, never a
500.

### `POST /api/payments/webhook`

Gated by the source's `configuration.webhookSignature` (same
`{scheme, secret, header, toleranceSeconds}` shape and `WebhookSignatureService`
verification as `peppol-access-point-connector`).

**Request (mirrors the real Mollie webhook shape — only an id):**
```json
{ "id": "tr_mock_a1b2c3d4" }
```
**Effect (internal):** re-fetches the authoritative status via the provider,
maps it to one of `authorized|captured|failed|voided`, updates the matching
`payment_intent`, and — only if the mapped outcome actually changed — emits
`nl.conduction.payment.status`:
```json
{
  "paymentIntentId": "tr_mock_a1b2c3d4",
  "outcome": "captured",
  "errorCode": null,
  "errorMessage": null,
  "settlementReference": "tr_mock_a1b2c3d4",
  "gatewayFeeAmount": null
}
```
`paymentIntentId` in the emitted event is deliberately the *provider* payment
id (not openconnector's own object uuid) — `PaymentReconciliationService`
resolves `PaymentRequest`/`DepositPayment` records by that same value (see
"Cross-app contract" below). **Response:** `{"received": true}` (HTTP 200)
on any verified, processed call (including idempotent no-ops); HTTP 401 on
signature failure, with no state change and no event.

## Database Changes

One new OR schema `payment_intent` added to
`lib/Settings/openconnector_register.json` (register `openconnector`,
following the same direct-edit convention `peppol_transmission` and
`bankfeed_connection`/`bankfeed_batch` used — no SQL migration).

| Field | Type | Purpose |
|-------|------|---------|
| `sourceSlug` | string | FK to the openconnector `Source` slug used to create the payment |
| `provider` | enum | `log`\|`mollie` |
| `providerPaymentId` | string | Provider-assigned payment id (`tr_...` for Mollie) — the idempotency + lookup key |
| `amountValue` | string | Decimal string, e.g. `"10.00"` |
| `amountCurrency` | string | ISO 4217, e.g. `"EUR"` |
| `description` | string | Payment description shown to the payer |
| `method` | string\|null | Requested method (`ideal`, `creditcard`, `bancontact`, `sepadirectdebit`) |
| `redirectUrl` | string | Where the payer returns to after checkout |
| `checkoutUrl` | string | Hosted checkout URL returned to the caller |
| `metadata` | object | Passthrough `{invoiceId\|depositPaymentId, administrationId, correlationId}` |
| `paymentStatus` | string | Last provider-native status (`open\|pending\|authorized\|paid\|failed\|canceled\|expired\|refunded\|chargeback`) |
| `lastOutcome` | string\|null | Last *emitted* shillinq-vocabulary outcome (`authorized\|captured\|failed\|voided`) — the idempotency guard |
| `createdAt` / `updatedAt` | date-time | Audit timestamps |

## Nextcloud Integration

- Controllers: `PaymentsController` (`create`, `webhook`).
- Services: `PaymentIntentService` (create → persist → provider dispatch;
  webhook → re-fetch → map → idempotency → persist → emit),
  `Payment\PaymentProviderInterface` + `LogPaymentProvider` +
  `MolliePaymentProvider`.
- Mappers/Entities: none new — `payment_intent` via OR `ObjectService`.
- Events/Hooks: emits `nl.conduction.payment.status` via `EventService`.

## Security Considerations

- The inbound webhook MUST verify the source's `webhookSignature` (HMAC over
  the raw body, constant-time compare, timestamp tolerance — reusing
  `WebhookSignatureService::verify()` exactly as `PeppolController::inbound()`
  does) before any provider call, state change, or event emission.
- The webhook handler MUST NOT trust any status field in the inbound body —
  it always re-derives status from `fetchPaymentStatus()` against the
  provider using the brokered credential (see "Why re-fetch" above). This is
  the money-safety control: an unauthenticated payment-status claim can never
  reach `PaymentIntentService`.
- The Mollie API key is resolved only via `authentication.credentialRef`
  through `BrokeredCallService` (ADR-007); fail-closed on missing key
  material, no plaintext-on-disk fallback, never logged.
- `POST /api/payments` requires an authenticated NC session
  (`#[NoAdminRequired]`) — it is not a public endpoint; only the webhook is
  `#[PublicPage]`, and that route's "authentication" is the signature gate
  (mirrors `PeppolController::inbound()`/`DSOController`).
- No card/account data ever transits or is stored here — only amounts,
  descriptions, opaque provider ids, and status.

## Declarative-vs-imperative decision (ADR-031)

The `payment_intent` schema and its status enums are declared **declaratively**
in `lib/Settings/openconnector_register.json`. The payment creation call,
credential brokering, signature-gated webhook handling, and the
authoritative-refetch + idempotency + CloudEvent emission are **imperative**
and justified under ADR-031's "external integration" exemption: initiating a
payment against a certified PSP, verifying an inbound HMAC, and re-deriving
trustworthy state from an external system cannot be expressed as a declarative
lifecycle block. This mirrors `peppol-access-point-connector` (imperative
transmission, declarative transmission-record schema) and
`psd2-ais-bank-feed-connector` (imperative SCA/sync, declarative
connection/batch schemas).

## File Structure

```
lib/
  Controller/
    PaymentsController.php           # create(), webhook()
  Service/
    Payment/
      PaymentProviderInterface.php
      LogPaymentProvider.php
      MolliePaymentProvider.php
    PaymentIntentService.php         # create → persist → dispatch; webhook → refetch → map → emit
  Exception/
    PaymentProviderException.php
  Settings/
    openconnector_register.json      # + payment_intent schema
    openconnector_seed_data.json     # + sandbox source + example payment_intents
appinfo/
  routes.php                         # + /api/payments routes
```

## Seed Data

A single sandbox payment source (`configuration.provider: log`) plus example
payment intents so a fresh install demonstrates create → checkout → webhook →
status → event without a real Mollie account.

### Schema: `payment_intent`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | pi-invoice-gemeente-nijmegen | pi-invoice-consultancy | pi-deposit-travel-booking |
| sourceSlug | payment-sandbox | payment-sandbox | payment-sandbox |
| provider | log | log | log |
| providerPaymentId | MOCK-PAY-1 | MOCK-PAY-2 | MOCK-PAY-3 |
| amountValue / amountCurrency | 245.00 / EUR | 89.50 / EUR | 150.00 / EUR |
| description | Invoice INV-2026-0042 | Invoice INV-2026-0058 | Booking deposit BK-2026-0011 |
| method | ideal | ideal | ideal |
| paymentStatus | paid | open | authorized |
| lastOutcome | captured | null | authorized |

**Sandbox source** (`configuration.provider: log`) so create/webhook/status
answer from canned, deterministic data with no upstream call and no secret.

**Related items per object:**
- Files: none.
- Notes: none.
- Tasks: none.
- Contacts: the payer organisation (municipality / consultancy / travel
  agency flavour), matching the fleet-wide seed convention.

## Trade-offs

- **Re-fetch-on-webhook vs trust-the-body.** Chosen because it is the only
  correct model for Mollie's real (unauthenticated, status-free) webhook and
  generalises cleanly to any future provider whose webhook is similarly thin.
  Alternative (trust an HMAC-signed status field in the body, mirroring
  shillinq's OWN existing `PaymentRequestWebhookController`) was rejected for
  *this* connector: that controller is shillinq's pre-existing, already-shipped
  surface (not touched by this change) built against a simplified/testable
  contract; this connector is the actual production PSP integration and must
  match how Mollie really behaves.
- **Provider-neutral wire contract vs literal `MolliePaymentAdapterInterface`
  field names.** The HTTP response uses `providerPaymentId`/`paymentStatus`/
  `checkoutUrl`/`dormant`/`extras` rather than shillinq's exact
  `molliePaymentId` naming. This matches how `peppol-access-point-connector`
  and `psd2-ais-bank-feed-connector` did NOT mirror their consuming app's
  internal interface names either — the consuming app's (future,
  out-of-scope) adapter maps the generic response onto its own value object.
  Keeping the wire contract provider-neutral is also what makes a future Wero
  binding a non-breaking addition.
- **CloudEvent payload shaped exactly like `PaymentReconciliationService::reconcile()`'s
  `$event` parameter.** Chosen so a future shillinq event listener can pass
  the event `data` straight into `reconcile()` unmodified — the strongest,
  most literal reading of "shillinq's existing reconciliation can consume"
  the emitted event.
- **Own `payment_intent` idempotency vs relying solely on shillinq's
  `ALREADY_SETTLED` guard.** Chosen for defence-in-depth and because
  openconnector must not blindly re-emit on every provider poll/webhook
  redelivery regardless of what a downstream consumer does with it — the
  `lastOutcome` check keeps the event stream itself idempotent, independent of
  any particular consumer's own guard.

## Open Questions

None blocking — the sandbox `log` provider makes the change self-contained;
Wero is a documented future follow-up, not a blocker (see proposal.md Open
Questions).
