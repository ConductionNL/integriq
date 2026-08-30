# webhook-signing — delta

## ADDED Requirements

### Requirement: Outbound deliveries are HMAC-signed when the subscription has a signing secret (REQ-WHS-001)

Outbound push deliveries MUST be HMAC-signed when the subscription carries a
signing secret. When an `event_subscription` carries
`protocolSettings.signingSecret`, every push delivery attempt for that
subscription (immediate, retry-sweep, and operator replay) MUST include the
headers:

- `X-OpenConnector-Signature: t=<unix-ts>,v1=<hex>` where
  `v1 = HMAC-SHA256(signingSecret, "<t>." + rawBody)` and `rawBody` is the
  exact byte sequence sent as the HTTP body (computed after serialization,
  never re-serialized);
- `X-OpenConnector-Event-Id: <event message uuid>`.

During a rotation grace window (REQ-WHS-002) an additional
`v1=<hex over previousSigningSecret>` pair MUST be appended to the same
header. Subscriptions without a `signingSecret` MUST deliver unsigned,
unchanged from current behaviour. A signing failure (e.g. malformed secret)
MUST be treated as a failed delivery attempt (normal failure accounting), not
as an unsigned send and not as an exception escaping the delivery path.

#### Scenario: a configured subscription receives a verifiable signature

- **GIVEN** a push subscription with a `signingSecret`
- **WHEN** a message is delivered to its sink
- **THEN** the request SHALL carry `X-OpenConnector-Signature` with a `t` pair
  and a `v1` pair
- **AND** recomputing `HMAC-SHA256(secret, "<t>." + receivedBody)` SHALL equal
  the `v1` value

#### Scenario: an unconfigured subscription is delivered unsigned

- **GIVEN** a push subscription whose `protocolSettings` has no `signingSecret`
- **WHEN** a message is delivered
- **THEN** the request SHALL NOT carry `X-OpenConnector-Signature`

#### Scenario: retry attempts are signed with a fresh timestamp

- **GIVEN** a signed subscription whose first delivery attempt failed
- **WHEN** the retry sweep re-attempts the delivery
- **THEN** the new request SHALL carry a signature whose `t` reflects the
  retry attempt time (not the original attempt)

### Requirement: Signing secret lifecycle — generate, reveal once, redact, rotate (REQ-WHS-002)

The system SHALL provide admin-gated, CSRF-protected endpoints to generate and
rotate a subscription's signing secret. Generation MUST produce a server-side
random secret (≥ 32 bytes entropy, `whsec_` prefix) and return it in full in
that response ONLY. Every other read surface — subscription list/detail
responses, the synced-from tab, and `configuration-export-import` exports —
MUST redact the secret (and `previousSigningSecret`) using the export spec's
credential-redaction convention. Rotation MUST move the current secret to
`previousSigningSecret`, set `secretRotatedAt`, and return the new secret once;
outbound deliveries MUST dual-sign with both secrets until 24h after
`secretRotatedAt`, after which the previous secret is no longer used.

#### Scenario: the secret is shown exactly once

- **GIVEN** an admin generates a signing secret for a subscription
- **WHEN** the generate response returns AND the subscription is subsequently
  fetched via the subscriptions list or detail endpoint
- **THEN** the generate response SHALL contain the full `whsec_...` value
- **AND** every subsequent read SHALL contain a redaction marker instead

#### Scenario: configuration export never leaks signing secrets

- **GIVEN** a subscription with a configured signing secret
- **WHEN** the configuration is exported via `configuration-export-import`
- **THEN** the exported document SHALL contain no signing secret material

#### Scenario: rotation keeps old-secret receivers working through the grace window

- **GIVEN** a subscription rotated 1 hour ago
- **WHEN** a delivery is sent
- **THEN** the signature header SHALL contain two `v1` pairs — one valid
  against the new secret, one against the previous secret
- **AND** a delivery sent after the 24h grace window SHALL contain only the
  new-secret pair

### Requirement: Inbound webhook signature verification rule (REQ-WHS-003)

The rule pipeline SHALL support a rule type `webhook_signature` with
configuration `{header, scheme: openconnector|github|stripe, secret,
toleranceSeconds (default 300)}`. When attached to an endpoint, the rule MUST
verify the configured header's HMAC-SHA256 over the RAW request body (before
any decode or mapping), using constant-time comparison (`hash_equals`), and
MUST run before any body-transforming or side-effecting rule. For timestamped
schemes (`openconnector`, `stripe`) the rule MUST reject requests whose
timestamp deviates from server time by more than `toleranceSeconds`. On any
verification failure (missing header, malformed header, digest mismatch, stale
timestamp) the rule MUST short-circuit the pipeline with HTTP 401 and an
undifferentiated error body, and the endpoint's downstream rules MUST NOT
execute. For `scheme: github` (no timestamp in the scheme) the tolerance
setting MUST be ignored with a logged warning, not an error.

#### Scenario: a correctly signed inbound webhook passes the gate

- **GIVEN** an endpoint with a `webhook_signature` rule (`scheme: openconnector`)
  and a sender signing `"<t>." + body` with the shared secret
- **WHEN** the request arrives within the timestamp tolerance
- **THEN** the rule SHALL pass and the remaining pipeline rules SHALL execute

#### Scenario: a tampered body is rejected before side effects

- **GIVEN** the same endpoint AND a request whose body was modified after
  signing
- **WHEN** the request arrives
- **THEN** the response SHALL be HTTP 401
- **AND** no downstream rule (mapping, synchronization, file handling) SHALL
  have executed

#### Scenario: a replayed request outside the tolerance window is rejected

- **GIVEN** a validly-signed request whose `t` is 10 minutes old and
  `toleranceSeconds = 300`
- **WHEN** the request arrives
- **THEN** the response SHALL be HTTP 401

#### Scenario: GitHub-style senders verify without a timestamp

- **GIVEN** a `webhook_signature` rule with `scheme: github` and a sender
  setting `X-Hub-Signature-256: sha256=<hex over body>`
- **WHEN** a correctly signed request arrives
- **THEN** the rule SHALL pass without evaluating any timestamp

### Requirement: Signing configuration UI (REQ-WHS-004)

The subscription edit modal SHALL include a signing section with
generate/rotate actions and a one-time secret display (copy-to-clipboard,
never shown again after dismissal), and the rule editor (`rule-editor-ui`)
SHALL offer the `webhook_signature` rule type with its scheme/header/tolerance
fields. All new UI strings SHALL ship with `nl` and `en` translations
(English source keys).

#### Scenario: admin generates a secret and sees it once

- **GIVEN** an admin editing a push subscription
- **WHEN** they click "Generate signing secret"
- **THEN** the full secret SHALL be displayed with a copy action and a
  shown-only-once warning
- **AND** reopening the modal afterwards SHALL show the redaction marker, not
  the secret

#### Scenario: rule editor offers the webhook_signature type

- **GIVEN** an admin adding a rule to an endpoint
- **WHEN** they open the rule-type selector
- **THEN** `webhook_signature` SHALL be selectable and expose scheme, header,
  secret, and tolerance fields
