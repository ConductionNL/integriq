---
kind: feature
depends_on: []
---

# openconnector — webhook payload signing and verification (HMAC)

## Why

OpenConnector pushes CloudEvents to arbitrary HTTPS sinks and receives webhook
calls on its own endpoints — and neither direction carries any message
authenticity proof. The spec corpus covers JWT bearer auth
(`authorization-jwt`) for *callers with credentials*, but the
industry-standard webhook trust model is different: a **shared-secret HMAC
signature over the exact payload** (GitHub `X-Hub-Signature-256`, Stripe
`Stripe-Signature`, Svix, standard-webhooks.com). Without it:

- **Outbound**: a sink receiving an OpenConnector event delivery has no way to
  verify it came from this instance and wasn't tampered with or replayed.
  Any party that learns a sink URL can forge "events" at it. Receivers that
  *require* signatures (increasingly the default for serious consumers)
  simply cannot subscribe.
- **Inbound**: an OpenConnector endpoint wired as a webhook receiver (e.g. a
  SaaS system POSTing change notifications) must either be left open or
  guarded by full JWT machinery the sending system usually doesn't support.
  Third-party webhook senders overwhelmingly offer exactly one verification
  mechanism: an HMAC header.

For an app positioning itself as the fleet's ESB/API gateway — and with the
connector-category roadmap (`add-openconnector-connector-categories`) about to
multiply webhook traffic in both directions — payload signing is a security
table-stake, not an enhancement. Nothing in the 31 existing specs or 13 active
changes covers it.

## What Changes

- **Outbound delivery signing**: when an `event_subscription` has a signing
  secret configured (`protocolSettings.signingSecret`), every push delivery
  (immediate, sweep retry, and operator replay) carries
  `X-OpenConnector-Signature: t=<unix-ts>,v1=<hex hmac-sha256 over "<t>.<raw body>">`
  plus `X-OpenConnector-Event-Id`. Timestamped scheme = receiver-side replay
  protection; versioned scheme (`v1`) = future algorithm agility.
- **Secret lifecycle**: server-side secret generation on request (returned in
  full exactly once), redacted everywhere afterwards — subscription
  list/detail responses AND configuration export (dovetails the credential
  redaction contract in `configuration-export-import`). Rotation keeps the
  previous secret verifiable/signable-against for a bounded grace window via
  dual signatures.
- **Inbound verification rule**: a new rule-pipeline rule type
  `webhook_signature` that endpoint operators attach to webhook-receiving
  endpoints: verifies a configurable header's HMAC over the **raw** request
  body with a per-rule secret, enforces a timestamp tolerance (default 300s),
  uses constant-time comparison, and rejects with 401 before any downstream
  rule (mapping, sync, etc.) runs. Configurable header/scheme presets so
  GitHub-style (`sha256=<hex>`, no timestamp) and Stripe-style
  (`t=...,v1=...`) senders both verify.

## Capabilities

### New Capabilities
- `webhook-signing`: HMAC payload authenticity for both webhook directions —
  signed outbound CloudEvent deliveries with secret lifecycle + rotation, and
  a rule-pipeline verification rule for inbound webhook endpoints.

## Impact

- **Code**: `lib/Service/EventService.php` (sign in `deliverMessage`), a small
  `lib/Service/WebhookSignatureService.php` (sign/verify, shared by both
  directions), rule-pipeline handler for `webhook_signature`, subscription
  serialization redaction, export redaction hook.
- **Schema**: `event_subscription.protocolSettings` gains `signingSecret` /
  `previousSigningSecret` / `secretRotatedAt` (documented shape; the property
  is already a free-form object — no breaking change). Rule schema gains the
  `webhook_signature` type + config block.
- **Ordering**: independent of `openconnector-event-retry-hardening` — signing
  wraps every delivery attempt wherever the retry machine triggers it. Both
  changes touch `deliverMessage`; whichever lands second rebases trivially
  (signing is a header concern, retry is a state concern).
- **UI**: subscription edit modal gains a signing section (generate / reveal-
  once / rotate); rule editor gains the `webhook_signature` type.

## Out of scope

- Asymmetric signing (Ed25519 per standard-webhooks.com) — `v2` candidate once
  a consumer demands public-key verification; the versioned header leaves room.
- mTLS to sinks, IP allowlisting — transport-level concerns, separate track.
- Fixing the `EventsController` IDOR/CSRF flags (REQ-005 Notes) — orthogonal,
  though the reveal-once secret endpoint specced here is explicitly
  admin-gated and must not inherit that posture.
- Signing of synchronization push traffic to non-subscription targets —
  follow-up once the sync-side consumer need is concrete.
