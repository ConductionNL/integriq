# Test Plan: integriq-adapter-rod

## Test Cases

### TC-1: log provider returns a synthetic ref with no network call
- **spec_ref**: `openspec/changes/integriq-adapter-rod/specs/rod-adapter/spec.md#req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings`
- **type**: functional
- **preconditions**: source configured with `configuration.provider: log` (or unset)
- **steps**: call `RodService::sendBericht()` with a complete inschrijving payload
- **expected result**: a `MOCK-ROD-<n>` ref is returned, no HTTP client is invoked
- **test command**: PHPUnit (`tests/unit/Service/Rod/LogRodProviderTest.php`)

### TC-2: Edukoppeling provider refuses closed without a certificate reference
- **spec_ref**: `.../spec.md#req-001-rod-provider-abstraction-with-log-and-edukoppeling-bindings`
- **type**: security
- **preconditions**: source configured with `configuration.provider: edukoppeling`, no `certificateRef`
- **steps**: call `send()`
- **expected result**: `RodProviderException` naming the missing certificate reference; no envelope built
- **test command**: PHPUnit (`tests/unit/Service/Rod/RodEdukoppelingClientTest.php`)

### TC-3: a complete inschrijving translates to a valid envelope
- **spec_ref**: `.../spec.md#req-002-outbound-envelope-translation-with-a-literal-leak-guard`
- **type**: functional
- **preconditions**: fixture payload with bsn, inschrijvingsdatum, leerjaar, groep
- **steps**: `RodEnvelopeTranslator::translate('inschrijving', payload)`
- **expected result**: envelope carries all four fields, matches `tests/fixtures/rod/inschrijving-request.xml`
- **test command**: PHPUnit contract test against recorded fixture

### TC-4: a missing required field never reaches the envelope
- **spec_ref**: `.../spec.md#req-002-outbound-envelope-translation-with-a-literal-leak-guard`
- **type**: functional
- **preconditions**: fixture payload missing `leerjaar`
- **steps**: `translate('inschrijving', payload)`
- **expected result**: `RodTranslationException` naming `leerjaar`; no envelope returned
- **test command**: PHPUnit

### TC-5: an accepted acknowledgement dispatches accepted:true
- **spec_ref**: `.../spec.md#req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event`
- **type**: functional
- **preconditions**: fixture retour `tests/fixtures/rod/retour-accepted.xml`, `signaalcode: 0`
- **steps**: `RodAcknowledgementTranslator::translate()`
- **expected result**: `RodAcknowledgementReceivedEvent` dispatched with `accepted: true`
- **test command**: PHPUnit

### TC-6: a rejection signaalcode dispatches accepted:false with the reason
- **spec_ref**: `.../spec.md#req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event`
- **type**: functional
- **preconditions**: fixture retour `tests/fixtures/rod/retour-rejected.xml`, `signaalcode: 7`
- **steps**: translate
- **expected result**: event carries `accepted: false`, `signaalcode: 7`, description preserved
- **test command**: PHPUnit

### TC-7: push endpoint happy path
- **spec_ref**: `.../spec.md#req-004-push-endpoint-and-signed-retour-receiver`
- **type**: api
- **preconditions**: authenticated session, `log` source active
- **steps**: `POST /api/rod/berichten` with complete inschrijving payload
- **expected result**: HTTP 200, `{ref, berichtsoort: "inschrijving", status: "sent"}`
- **test command**: PHPUnit controller test

### TC-8: push endpoint with no active source
- **spec_ref**: `.../spec.md#req-004-push-endpoint-and-signed-retour-receiver`
- **type**: api
- **preconditions**: no active `type=rod` source
- **steps**: `POST /api/rod/berichten`
- **expected result**: HTTP 503 `not_configured`
- **test command**: PHPUnit controller test

### TC-9: unsigned retour rejected before processing
- **spec_ref**: `.../spec.md#req-004-push-endpoint-and-signed-retour-receiver`
- **type**: security
- **preconditions**: `POST /api/rod/retour` with missing/invalid HMAC header
- **steps**: send request
- **expected result**: HTTP 401, no `rod_message` record created
- **test command**: PHPUnit controller test

### TC-10: verified retour with unknown kenmerk still acknowledges
- **spec_ref**: `.../spec.md#req-004-push-endpoint-and-signed-retour-receiver`
- **type**: functional
- **preconditions**: correctly signed retour, `kenmerk` not found locally
- **steps**: send request
- **expected result**: HTTP 200 `{received: true}`, unresolved reference logged, never a 500
- **test command**: PHPUnit controller test

### TC-11: failed send persists and is retried in isolation
- **spec_ref**: `.../spec.md#req-005-per-message-audit-persistence-and-isolated-retry`
- **type**: functional
- **preconditions**: two failed `rod_message` rows, one raises again on retry
- **steps**: run `RodRetryJob::run()`
- **expected result**: the failing row is logged and skipped; the other row is retried and its status updates
- **test command**: PHPUnit (`tests/unit/BackgroundJob/RodRetryJobTest.php`)

### TC-12: BSN hashed at rest, raw on the wire
- **spec_ref**: `.../spec.md#req-006-bsn-hygiene--raw-on-the-wire-hashed-at-rest`
- **type**: security
- **preconditions**: inschrijving push with a raw fixture BSN
- **steps**: `sendBericht()`
- **expected result**: envelope handed to the provider contains the raw BSN; the persisted `rod_message` record contains only its SHA-256 hash
- **test command**: PHPUnit

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-001 (provider bindings) | TC-1, TC-2 |
| REQ-002 (envelope translation, literal-leak guard) | TC-3, TC-4 |
| REQ-003 (acknowledgement translation) | TC-5, TC-6 |
| REQ-004 (push/retour endpoints) | TC-7, TC-8, TC-9, TC-10 |
| REQ-005 (audit persistence and retry) | TC-11 |
| REQ-006 (BSN hygiene) | TC-12 |

## Out of Scope

- Live DUO preproduction/production traffic — blocked on the certificate
  (M3(c)); not testable until that gate clears. `RodEdukoppelingClient`'s
  fail-closed path (TC-2) is the boundary of what can be verified today.
- Playwright/e2e coverage: every scenario in the spec carries
  `@e2e exclude ... — covered by PHPUnit`, consistent with `iwmo-ijw-adapter`
  precedent — this is a backend-only integration seam with no new UI beyond
  the existing Adapters catalogue card and source configuration form.
