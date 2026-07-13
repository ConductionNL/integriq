---
status: done
---

# live-payment-providers Specification

## Purpose

OpenConnector connects to a live payment service provider (PSP) so sibling
apps can create a payment, present a checkout URL, and reliably learn the
outcome — without embedding a PSP client of their own. It is delivered in the
same family as `peppol-access-point-connector` and
`psd2-ais-bank-feed-connector`: a provider abstraction (interface + sandbox
`log` binding + a real REST binding), PSP credentials resolved through the
OpenRegister credential broker (ADR-007), and integration via CloudEvents
(`events-cloudevents`) and the openconnector HMAC webhook-signature pattern
(`webhook-signing`) — not a parallel framework. Mollie is the first real
binding (dominant NL PSP; iDEAL the dominant NL method).

Two controls are load-bearing because this moves money. First, the inbound
webhook is signature-gated: an unauthenticated payment-status webhook would
let anyone who learns a payment id fabricate a "paid" event. Second, the
webhook body's status claim is never trusted — a real Mollie webhook is
unauthenticated and carries only an `id`, so the connector treats the POST as
a bare trigger and always re-derives the authoritative status from the
provider. Replay is guarded by `payment_intent.lastOutcome` so a redelivered
webhook cannot double-reconcile.

Per ADR-022 the PSP integration lives here; leaf apps (e.g. shillinq) consume
it via its REST surface and its `nl.conduction.payment.status` CloudEvent,
whose payload is shaped identically to `PaymentReconciliationService::
reconcile()`'s `$event` parameter.

## Requirements
### Requirement: Payment creation endpoint accepting shillinq's payload contract (REQ-LPP-001)

OpenConnector MUST expose `POST /api/payments` that creates a payment against
the configured PSP source and returns a checkout URL. The request body MUST
accept exactly the envelope shillinq's `MolliePaymentAdapterInterface::
createPayment()` already documents: `amount{value, currency}`, `description`,
`redirectUrl`, `webhookUrl`, an optional `method` (`ideal` is the default/
primary method; `creditcard`, `bancontact`, `sepadirectdebit` MUST also be
accepted as passthrough), and `metadata` (opaque object, e.g.
`invoiceId`/`depositPaymentId`, `administrationId`, `correlationId`). A
request missing `amount.value`, `amount.currency`, or `description` MUST
return HTTP 400, never a 500. When the PSP is unreachable or errors the
endpoint MUST return HTTP 502 with a descriptive, secret-free error, never a
crash. The endpoint requires an authenticated Nextcloud session
(`#[NoAdminRequired]`) — it is not public.

#### Scenario: a valid request returns a checkout URL

- GIVEN a payment source and a request with `amount.value="10.00"`,
  `amount.currency="EUR"`, `description`, `redirectUrl`, `webhookUrl`,
  `method="ideal"`
- WHEN `POST /api/payments` is called
- THEN the response SHALL be HTTP 200 with a non-empty `checkoutUrl` and a
  provider-assigned `providerPaymentId`
- @e2e exclude backend payment creation — covered by PHPUnit/Newman, no
  browser UI

#### Scenario: a request missing a required amount field is rejected with 400

- GIVEN a request for `POST /api/payments` with no `amount.currency`
- WHEN the endpoint runs
- THEN the response SHALL be HTTP 400 with a descriptive error, not a 500
- @e2e exclude backend input validation — covered by PHPUnit

#### Scenario: an unreachable provider returns a descriptive 502

- GIVEN a `mollie` payment source whose configured endpoint is unreachable
- WHEN `POST /api/payments` is called
- THEN the response SHALL be HTTP 502 with a secret-free descriptive error
- @e2e exclude backend provider failure handling — covered by PHPUnit

### Requirement: Payment-provider abstraction with log and Mollie bindings (REQ-LPP-002)

The connector MUST define a `PaymentProviderInterface`
(`lib/Service/Payment/`) with at minimum `createPayment(sourceConfiguration,
payload)` and `fetchPaymentStatus(sourceConfiguration, providerPaymentId)`. A
source's `configuration.provider` selects the binding:

- `log` — `LogPaymentProvider`, a sandbox binding that performs no real
  network call, returns a synthetic `MOCK-PAY-<n>` payment id and a
  deterministic canned checkout URL from `createPayment`, and returns a
  caller-seeded status from `fetchPaymentStatus` with no upstream call. It
  MUST NOT read any secret. It is the default for dev/CI and every test in
  this change MUST exercise it (no network).
- `mollie` — `MolliePaymentProvider`, a Mollie Payments API v2 binding driven
  by `configuration.baseUrl` (defaults to `https://api.mollie.com/v2`) and
  `authentication.credentialRef`; every outbound call MUST go through the
  credential broker (`BrokeredCallService`) so the Mollie API key is injected
  at call time and never stored in the source config.

The interface MUST be the single seam through which payment creation and
status lookup occur, and MUST be designed so a second method or provider
(e.g. a future Wero binding — iDEAL retires end-2027) can be added without
changing the interface signature.

#### Scenario: the log provider creates a payment without a network call or secret

- GIVEN a payment source with `configuration.provider: log`
- WHEN a payment is created
- THEN a synthetic `MOCK-PAY-<n>` provider payment id and a canned checkout
  URL SHALL be returned with no outbound HTTP call and no credential read
- @e2e exclude backend provider binding — covered by PHPUnit

#### Scenario: the Mollie provider brokers its API key

- GIVEN a payment source with `configuration.provider: mollie` and
  `authentication.credentialRef`
- WHEN a payment is created or a status is fetched
- THEN the outbound call SHALL be dispatched through the credential broker
  with the Mollie API key injected at call time
- AND the API key SHALL NOT appear in the source configuration, exports, or
  logs
- @e2e exclude backend credential brokering — covered by PHPUnit

### Requirement: Signature-gated webhook that never trusts an inbound status claim (REQ-LPP-003)

The connector MUST expose `POST /api/payments/webhook`, a `#[PublicPage]`
`#[NoCSRFRequired]` endpoint gated by the source's `configuration.
webhookSignature` (`{scheme, secret, header, toleranceSeconds}`), verified via
the same HMAC scheme (constant-time compare, timestamp tolerance) as
`peppol-access-point-connector`'s inbound webhook. A missing or invalid
signature MUST return HTTP 401 before any provider call, state change, or
event emission. Once verified, the endpoint MUST treat the inbound body as a
bare trigger only — it MUST NOT trust any status field supplied in the body —
and MUST always re-derive the authoritative status by calling
`PaymentProviderInterface::fetchPaymentStatus()` against the configured
provider before doing anything else.

#### Scenario: an unsigned or tampered callback is rejected before any side effect

- GIVEN an inbound callback whose signature is missing or does not verify
- WHEN it arrives at `POST /api/payments/webhook`
- THEN the response SHALL be HTTP 401
- AND no payment_intent SHALL change state and no event SHALL be emitted
- @e2e exclude backend signature gate — covered by PHPUnit

#### Scenario: a verified callback ignores any status claim in the body

- GIVEN a verified webhook body that (incorrectly) claims `"status": "paid"`
  for a payment id whose true provider status is `open`
- WHEN the webhook is processed
- THEN the connector SHALL call `fetchPaymentStatus()` and use ITS answer
  (`open`) — the body's claimed status SHALL have no effect
- @e2e exclude backend authoritative status re-derivation — covered by
  PHPUnit

### Requirement: Status mapping to shillinq's outcome vocabulary and CloudEvent emission (REQ-LPP-004)

On a verified webhook call (REQ-LPP-003), the connector MUST map the
provider-native status returned by `fetchPaymentStatus()` onto the outcome
vocabulary `PaymentReconciliationService` already defines
(`authorized|captured|failed|voided`), using at minimum: Mollie
`paid`→`captured`, `authorized`→`authorized`, `failed`|`expired`→`failed`,
`canceled`→`voided`. Statuses with no mapped outcome (`open`, `pending`)
MUST be treated as a no-op (no event). On an actual mapped-outcome change the
connector MUST emit exactly one `nl.conduction.payment.status` CloudEvent via
`EventService::emitCloudEvent()`, with a payload shaped identically to
`PaymentReconciliationService::reconcile()`'s `$event` parameter:
`{paymentIntentId, outcome, errorCode, errorMessage, settlementReference,
gatewayFeeAmount}`, where `paymentIntentId` carries the *provider* payment id
(the same value `PaymentReconciliationService` resolves records by).

#### Scenario: a captured payment emits a status event shillinq's reconciliation can consume

- GIVEN a `payment_intent` created via the log provider and a verified
  webhook call whose re-fetched status is `paid`
- WHEN the webhook is processed
- THEN one `nl.conduction.payment.status` CloudEvent SHALL be emitted with
  `outcome="captured"` and `paymentIntentId` equal to the provider payment id
- @e2e exclude backend event emission — covered by PHPUnit

#### Scenario: an unmapped status is a no-op

- GIVEN a verified webhook call whose re-fetched status is `open`
- WHEN the webhook is processed
- THEN no `payment_intent` state change and no CloudEvent SHALL occur
- @e2e exclude backend status mapping — covered by PHPUnit

### Requirement: Idempotency on payment id — a replayed webhook must not double-reconcile (REQ-LPP-005)

The connector MUST track the last-emitted outcome per `payment_intent`
(`lastOutcome`). A webhook call whose re-fetched status maps to the SAME
outcome already recorded MUST be a no-op: no second CloudEvent SHALL be
emitted, and the response MUST still be `{"received": true}` (HTTP 200) so
the provider stops retrying.

#### Scenario: a replayed webhook for an already-captured payment does not double-reconcile

- GIVEN a `payment_intent` whose `lastOutcome` is already `captured`
- WHEN a second, correctly signed webhook call re-fetches status `paid`
  (mapping to `captured` again)
- THEN no second `nl.conduction.payment.status` CloudEvent SHALL be emitted
- AND the response SHALL still be HTTP 200 `{"received": true}`
- @e2e exclude backend webhook idempotency — covered by PHPUnit

### Requirement: Payment-provider credentials brokered, never plaintext (REQ-LPP-006)

The Mollie API key MUST be resolved through the OpenRegister credential
broker via `authentication.credentialRef` and MUST NOT be stored as plaintext
in source configuration, exports, logs, or error messages (ADR-007). When
required key material cannot be supplied for the `mollie` provider, the
connector MUST fail closed with an actionable configuration error and MUST
NOT fall back to a plaintext key. The `log` provider needs no secret and MUST
remain usable with none configured.

#### Scenario: the Mollie key is brokered and never appears in config or logs

- GIVEN a `mollie` payment source configured with
  `authentication.credentialRef`
- WHEN a payment is created or its status is fetched
- THEN the Mollie key SHALL be resolved through the credential broker
- AND the key SHALL NOT appear in source config, exports, logs, or errors
- @e2e exclude backend credential brokering — covered by PHPUnit

#### Scenario: absent key material fails closed with no plaintext fallback

- GIVEN a `mollie` payment source whose `credentialRef` cannot supply the key
- WHEN a payment creation is attempted
- THEN it SHALL fail with an actionable config error (e.g.
  `YOUR_API_KEY_HERE` placeholder text in documentation only, never a
  fallback value used at runtime)
- AND no plaintext-key fallback SHALL be used
- @e2e exclude backend credential brokering — covered by PHPUnit

