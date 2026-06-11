# Tasks — webhook payload signing and verification

## 1. Shared crypto service

- [ ] 1.1 `lib/Service/WebhookSignatureService.php`: `sign(rawBody, secret,
  ?previousSecret): string` (header value) + `verify(rawBody, headerValue,
  config): bool` — `hash_hmac('sha256')`, `hash_equals`, `random_bytes`
  generation with `whsec_` prefix
- [ ] 1.2 Scheme parsers/presets: `openconnector` (`t=`,`v1=`), `stripe`,
  `github` (`sha256=<hex>`, no timestamp)

## 2. Outbound signing

- [ ] 2.1 Inject signature + `X-OpenConnector-Event-Id` headers in
  `EventService::deliverMessage` when `protocolSettings.signingSecret` present
  (sign the exact serialized body bytes)
- [ ] 2.2 Dual-sign during the 24h rotation grace window
  (`previousSigningSecret` + `secretRotatedAt`, lazily expired at sign time)
- [ ] 2.3 Treat signing failures as failed delivery attempts (no unsigned
  fallback, no escaping exception)

## 3. Secret lifecycle

- [ ] 3.1 Admin-gated, CSRF-protected generate + rotate endpoints (routes in
  `appinfo/routes.php`; no `@NoAdminRequired`/`@NoCSRFRequired`)
- [ ] 3.2 Redact `signingSecret`/`previousSigningSecret` in every subscription
  read path (list, detail, subscriptionMessages, synced-from tab)
- [ ] 3.3 Add the signing keys to the `configuration-export-import` credential
  redaction set
- [ ] 3.4 Document the `protocolSettings` signing keys on the
  `event_subscription` schema description in
  `lib/Settings/openconnector_register.json` (free-form object — additive)

## 4. Inbound verification rule

- [ ] 4.1 `webhook_signature` rule type in the rule pipeline: raw-body capture
  before decode, pre-pipeline ordering, 401 short-circuit with
  undifferentiated error body
- [ ] 4.2 Timestamp tolerance enforcement for timestamped schemes; logged
  warning (not error) when tolerance is configured on `scheme: github`
- [ ] 4.3 Rule schema/config registration so the rule editor can offer the type

## 5. UI

- [ ] 5.1 Subscription edit modal: signing section with generate/rotate,
  one-time secret display + copy, redaction marker afterwards (modal in its
  own file per modal-isolation gate)
- [ ] 5.2 Rule editor: `webhook_signature` type with scheme/header/secret/
  tolerance fields
- [ ] 5.3 nl + en translations (English source keys)

## 6. Tests

- [ ] 6.1 PHPUnit: sign/verify round trip per scheme, raw-bytes invariance,
  tolerance matrix, dual-sign grace window + expiry, constant-time path,
  redaction on every read surface
- [ ] 6.2 Newman: signed outbound delivery captured by a fixture sink and
  verified; inbound endpoint accept/tamper/replay/missing-header matrix;
  generate/rotate reveal-once behaviour
- [ ] 6.3 Playwright (gate-19): REQ-WHS-004 scenarios (generate-and-see-once,
  rule-type selectable)

## Acceptance criteria

- A Stripe/GitHub-style receiver can verify OpenConnector deliveries using
  only the shared secret and public documentation of the header format.
- A tampered or replayed inbound webhook never reaches mapping/sync rules.
- No read surface (API, UI, export) ever returns secret material after the
  generate/rotate response.
- Existing unsigned subscriptions and endpoints behave exactly as before
  (signing is opt-in per subscription / per rule).
