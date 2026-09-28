# Test Plan: integriq-adapter-verzuimloket

## Test Cases

### TC-1: log provider returns a synthetic ref with no network call
- **spec_ref**: `openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings`
- **type**: functional
- **preconditions**: source configured with `configuration.provider: log`
- **steps**: call `VerzuimloketService::sendMelding()` with a complete eerste-melding payload
- **expected result**: a `MOCK-VERZUIM-<n>` ref is returned, no HTTP call
- **test command**: PHPUnit

### TC-2: Edukoppeling provider refuses closed without a certificate reference
- **spec_ref**: `.../spec.md#req-001-verzuimloket-provider-abstraction-with-log-and-edukoppeling-bindings`
- **type**: security
- **preconditions**: `configuration.provider: edukoppeling`, no `certificateRef`
- **steps**: call `send()`
- **expected result**: `VerzuimloketProviderException` naming the missing certificate reference
- **test command**: PHPUnit

### TC-3: a complete eerste-melding translates to a valid envelope
- **spec_ref**: `.../spec.md#req-002-outbound-envelope-translation-with-a-literal-leak-guard`
- **type**: functional
- **preconditions**: fixture payload with bsn, windowStart, windowEnd, metricValue
- **steps**: `translate('eerste-melding', kenmerk, payload)`
- **expected result**: envelope carries all fields plus breachingRecords/interventions
- **test command**: PHPUnit contract test against recorded fixture

### TC-4: a missing required field never reaches the envelope
- **spec_ref**: `.../spec.md#req-002-outbound-envelope-translation-with-a-literal-leak-guard`
- **type**: functional
- **preconditions**: fixture payload missing `metricValue`
- **steps**: `translate(...)`
- **expected result**: `VerzuimloketTranslationException` naming `metricValue`
- **test command**: PHPUnit

### TC-5: an accepted acknowledgement dispatches accepted:true
- **spec_ref**: `.../spec.md#req-003-duo-acknowledgement-translation-to-a-typed-event`
- **type**: functional
- **preconditions**: fixture retour, `signaalcode: 0`
- **steps**: `VerzuimloketAcknowledgementTranslator::translate()`
- **expected result**: event dispatched with `accepted: true`
- **test command**: PHPUnit

### TC-6: push endpoint happy path
- **spec_ref**: `.../spec.md#req-004-push-endpoint-and-signed-retour-receiver`
- **type**: api
- **preconditions**: authenticated session, `log` source active
- **steps**: `POST /api/verzuimloket/berichten` with complete payload
- **expected result**: HTTP 200, `{ref, meldingType, status: "sent"}`
- **test command**: PHPUnit controller test

### TC-7: unsigned retour rejected before processing
- **spec_ref**: `.../spec.md#req-004-push-endpoint-and-signed-retour-receiver`
- **type**: security
- **preconditions**: missing/invalid HMAC header
- **steps**: send request
- **expected result**: HTTP 401, no `verzuim_message` record created
- **test command**: PHPUnit controller test

### TC-8: failed send persists and is retried in isolation
- **spec_ref**: `.../spec.md#req-005-per-message-audit-persistence-and-isolated-retry`
- **type**: functional
- **preconditions**: two failed rows, one raises again on retry
- **steps**: run `VerzuimloketRetryJob::run()`
- **expected result**: failing row logged and skipped, other row retried
- **test command**: PHPUnit

### TC-9: BSN hashed at rest, raw on the wire
- **spec_ref**: `.../spec.md#req-006-bsn-hygiene--raw-on-the-wire-hashed-at-rest`
- **type**: security
- **preconditions**: eerste-melding push with a raw fixture BSN
- **steps**: `sendMelding()`
- **expected result**: envelope contains raw BSN; persisted record contains only its SHA-256 hash
- **test command**: PHPUnit

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-001 | TC-1, TC-2 |
| REQ-002 | TC-3, TC-4 |
| REQ-003 | TC-5 |
| REQ-004 | TC-6, TC-7 |
| REQ-005 | TC-8 |
| REQ-006 | TC-9 |

## Out of Scope

- Live DUO traffic — blocked on the certificate (M3(c)).
- Playwright/e2e coverage — every scenario carries `@e2e exclude`, backend-only integration seam.
