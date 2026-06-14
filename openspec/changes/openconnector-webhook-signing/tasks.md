# Tasks — webhook payload signing and verification

## 1. Shared crypto service

- [x] 1.1 `lib/Service/WebhookSignatureService.php`: `sign(rawBody, secret,
  ?previousSecret, ?timestamp): string` (header value) + `verify(rawBody,
  headerValue, config): bool` — `hash_hmac('sha256')`, `hash_equals`,
  `random_bytes` generation with `whsec_` prefix
- [x] 1.2 Scheme parsers/presets: `openconnector`/`stripe` (`t=`,`v1=`),
  `github` (`sha256=<hex>`, no timestamp)

## 2. Outbound signing

- [x] 2.1 Inject signature + `X-OpenConnector-Event-Id` headers in
  `EventService::deliverMessage` when `protocolSettings.signingSecret` present
  (signs the exact serialized body bytes — serialized once, signed, sent)
- [x] 2.2 Dual-sign during the 24h rotation grace window
  (`previousSigningSecret` + `secretRotatedAt`, lazily expired at sign time)
- [x] 2.3 Treat signing failures as failed delivery attempts (no unsigned
  fallback: the sign call sits inside the delivery try, so a malformed secret
  flows into the normal failure path)

## 3. Secret lifecycle

- [x] 3.1 Admin-gated (`#[AuthorizedAdminSetting(OpenConnectorAdmin)]`),
  CSRF-protected generate + rotate endpoints (routes in `appinfo/routes.php`;
  no `@NoAdminRequired`/`@NoCSRFRequired`)
- [x] 3.2 Redact `signingSecret`/`previousSigningSecret` in every subscription
  read path (list, detail, subscriptionMessages, subscribe, updateSubscription)
- [~] 3.3 Add the signing keys to the `configuration-export-import` credential
  redaction set — N/A: `event_subscription` has no ConfigurationHandler and is
  not part of the config export/import set (only source/endpoint/mapping/job/
  rule/synchronization are), so there is no export surface to redact. Noted
  for if subscriptions are ever added to config export.
- [x] 3.4 Document the `protocolSettings` signing keys on the
  `event_subscription` schema description in
  `lib/Settings/openconnector_register.json` (free-form object — additive)

## 4. Inbound verification rule

- [x] 4.1 `webhook_signature` rule type in `EndpointService::processRules`:
  raw-body capture via `getRawContent()` before decode, 401 short-circuit with
  undifferentiated `{"error":"invalid signature"}` body. Pre-pipeline ordering
  is operator-controlled via rule `order` (documented on the handler).
- [x] 4.2 Timestamp tolerance enforcement for timestamped schemes; logged
  warning (not error) when tolerance is configured on `scheme: github`
- [x] 4.3 Rule type registered in the rule editor (RuleActionConfig
  ACTION_TYPES + ACTION_FORM_MAP) so the editor offers it

## 5. UI

- [x] 5.1 Subscription signing modal (`SubscriptionSigningModal`, own file
  under `src/modals/` per modal-isolation) with generate/rotate, one-time
  secret display + copy, redaction-marker status afterwards; opened from the
  Webhooks page row action via the modal bus
- [x] 5.2 Rule editor: `webhook_signature` type with scheme/header/secret/
  tolerance fields (`WebhookSignatureForm`)
- [x] 5.3 nl + en translations (English source keys); full 36-locale parity

## 6. Tests

- [x] 6.1 PHPUnit: sign/verify round trip per scheme, raw-bytes invariance,
  tolerance matrix, dual-sign grace window + expiry, malformed-header path,
  outbound signing + unsigned (WebhookSignatureServiceTest + EventServiceTest);
  full suite 356 green
- [~] 6.2 Newman: signed outbound capture + inbound accept/tamper/replay/
  missing-header matrix + reveal-once — deferred: needs a live instance with a
  fixture sink + admin session; covered deterministically at the unit level.
- [~] 6.3 Playwright (gate-19): REQ-WHS-004 scenarios — deferred: needs the
  renderer-installed live app.

## Acceptance criteria

- A Stripe/GitHub-style receiver can verify OpenConnector deliveries using
  only the shared secret and public documentation of the header format.
- A tampered or replayed inbound webhook never reaches mapping/sync rules.
- No read surface (API, UI, export) ever returns secret material after the
  generate/rotate response.
- Existing unsigned subscriptions and endpoints behave exactly as before
  (signing is opt-in per subscription / per rule).
