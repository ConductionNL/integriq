# Design — webhook payload signing and verification

## Context

Two directions, one primitive. Outbound: `EventService::deliverMessage` POSTs
CloudEvents to subscription sinks. Inbound: openconnector endpoints already run
a rule pipeline (`rule-pipeline` spec) with typed, ordered rules — the natural
attachment point for a verification gate. A single
`WebhookSignatureService` owns the crypto for both so the sign and verify
implementations cannot drift.

## Decisions

### D1 — Stripe-style timestamped scheme as the native format

Native header: `X-OpenConnector-Signature: t=<unix>,v1=<hex>` where
`v1 = HMAC-SHA256(secret, "<t>." + rawBody)`. Rationale:

- The timestamp inside the signed string gives receivers replay protection
  without any state (vs GitHub's scheme, which signs the body only).
- The `v1=` version tag allows additive `v2` (e.g. Ed25519) later; receivers
  ignore unknown pairs.
- During rotation grace, a second pair `v1=<hex over previous secret>` is
  appended — receivers that match ANY `v1` pair accept, which is exactly how
  Stripe ships rotation.

**Alternative considered**: standard-webhooks.com (`webhook-id`,
`webhook-timestamp`, `webhook-signature` with base64 + Ed25519 option).
Attractive, but its id/timestamp header split adds surface without a consumer
asking for it; our scheme is convertible later behind `v2`. Rejected for now,
noted as the upgrade path.

### D2 — Sign the exact bytes on the wire

The HMAC covers the raw JSON string that goes into the HTTP body — computed
*after* serialization, immediately before send. Never re-serialize for
signing: key order or unicode escaping differences would break verification.
Same rule inbound: the `webhook_signature` rule verifies against the raw
request body bytes (before any JSON decode/mapping), which requires the rule
to run as a **pre-pipeline gate** ahead of body-transforming rules.

### D3 — Secret lifecycle: generate server-side, reveal once, redact always

- `signingSecret` is generated server-side (32 random bytes, base64, prefix
  `whsec_` for recognizability/secret-scanner support) via an explicit
  admin-gated action; caller-supplied secrets are accepted but discouraged.
- The full secret appears in exactly one response: the generate/rotate call.
  Every other read path — subscription list/detail, the synced-from tab,
  `configuration-export-import` output — shows a redaction marker, reusing the
  export spec's existing credential-redaction contract.
- Rotation: current → `previousSigningSecret` + `secretRotatedAt`; outbound
  signs with both during the grace window (default 24h, constant), then the
  previous secret is dropped by the next delivery after expiry. No background
  job needed — expiry is evaluated lazily at sign time.

**Alternative considered**: store secrets in doriath (its OpenConnector
secret-store contract is an open item in the doriath re-evaluation). Right
shape long-term, wrong dependency today: doriath's contract is unspecced and
openconnector subscriptions already live as OR objects whose `protocolSettings`
carries delivery config. Decision: store on the subscription now, name the
doriath migration as the follow-up (`openconnector-doriath-secret-backend`)
and keep `WebhookSignatureService` as the single seam where the storage
backend would swap.

### D4 — Inbound verification as a rule type, not endpoint config

`webhook_signature` becomes a rule-pipeline rule (like auth/mapping/locking
rules) rather than a new endpoint property:

- Operators already manage endpoint behaviour as ordered rules; the rule
  editor UI exists (`rule-editor-ui`).
- A rule gets per-endpoint config naturally: `header`, `scheme`
  (`openconnector` | `github` | `stripe` — preset parsing differences),
  `secret`, `toleranceSeconds`.
- Failure short-circuits the pipeline with 401 exactly like existing auth
  rules, before mapping/sync side effects.

`scheme: github` disables the timestamp check (the scheme has none) — the
tolerance config is ignored with a logged warning rather than rejected, so a
preset switch can't brick an endpoint.

### D5 — Constant-time comparison, no exceptions

Verification uses `hash_equals()` end-to-end. A malformed header, missing
header, stale timestamp, or bad digest all produce the same 401 body
(`{"error":"invalid signature"}`) — no oracle distinguishing "wrong secret"
from "expired timestamp" beyond what the sender needs to fix clock skew
(a `Retry-After`-style hint is deliberately omitted).

### D6 — Signing failures must not lose deliveries

If signing config is malformed (e.g. corrupted secret), `deliverMessage`
fails that attempt like any transport error — it flows into the normal
failed/retry accounting rather than throwing out of the delivery path. A
subscription without a `signingSecret` delivers unsigned, exactly as today:
signing is strictly opt-in per subscription, so the change is
backwards-compatible for every existing subscriber.

## Reuse analysis

| Need | Existing surface | Strategy |
|---|---|---|
| Outbound delivery hook | `EventService::deliverMessage` (events-cloudevents REQ-002) | Header injection at send time; no delivery-flow changes |
| Inbound gate | rule-pipeline typed rules + rule-editor UI | New rule type, existing execution + editor machinery |
| Secret redaction on export | `configuration-export-import` credential redaction | Reused — signing secrets join the redaction set |
| Secret storage | `event_subscription.protocolSettings` (free-form object) | Documented keys now; doriath backend as named follow-up behind one service seam |
| Crypto | PHP `hash_hmac` / `hash_equals` / `random_bytes` | No new dependency |

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Secret at rest in an OR object | Redacted on every read path; admin-only reveal-once; doriath backend follow-up named |
| Receivers re-serialize JSON and fail verification | Docs state the raw-bytes rule; native scheme matches Stripe semantics receivers already know |
| Two changes touch `deliverMessage` (this + retry-hardening) | Orthogonal concerns (headers vs state); explicit rebase note in both proposals |
| Clock skew breaks timestamp tolerance | Default 300s tolerance, per-rule configurable |
| Grace-window dual signing confuses naive receivers | Multiple `v1` pairs are the established Stripe pattern; documented |
