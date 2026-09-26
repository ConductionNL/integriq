# Test Plan: integriq-adapter-uwlr-eduv

## Test Cases

### TC-1: log provider returns a synthetic ref with no network call
- **spec_ref**: `openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings`
- **type**: functional
- **preconditions**: source configured with `configuration.provider: log`
- **steps**: call `UwlrEduVService::send()` for each of the four targets
- **expected result**: a `MOCK-UWLREDUV-<n>` ref is returned, no HTTP call
- **test command**: PHPUnit

### TC-2: uwlr-eduv provider refuses closed without a certificate reference
- **spec_ref**: `.../spec.md#req-001-shared-provider-abstraction-with-log-and-uwlr-eduv-bindings`
- **type**: security
- **preconditions**: `configuration.provider: uwlr-eduv`, no `certificateRef`
- **steps**: call `send()`
- **expected result**: `UwlrEduVProviderException` naming the missing certificate reference
- **test command**: PHPUnit

### TC-3: each UWLR subtype translates correctly and carries eckId
- **spec_ref**: `.../spec.md#req-002-uwlr-export-envelope-translation-across-three-subtypes`
- **type**: functional
- **preconditions**: fixture payloads for pupil/group/teacher
- **steps**: `UwlrExportEnvelopeTranslator::translate()` for each subtype
- **expected result**: each envelope carries `eckId`
- **test command**: PHPUnit

### TC-4: a missing eckId never reaches the UWLR envelope
- **spec_ref**: `.../spec.md#req-002-uwlr-export-envelope-translation-across-three-subtypes`
- **type**: functional
- **preconditions**: fixture payload missing `eckId`
- **steps**: `translate()`
- **expected result**: `UwlrEduVTranslationException` naming `eckId`
- **test command**: PHPUnit

### TC-5: each Edu-V subtype names its own targetSchema
- **spec_ref**: `.../spec.md#req-003-edu-v-export-envelope-translation-across-three-qualified-data-services`
- **type**: functional
- **preconditions**: fixture payloads for all three qualified data services
- **steps**: `EduVExportEnvelopeTranslator::translate()` for each
- **expected result**: three distinct `targetSchema` values
- **test command**: PHPUnit

### TC-6: an unknown Edu-V data service is rejected
- **spec_ref**: `.../spec.md#req-003-edu-v-export-envelope-translation-across-three-qualified-data-services`
- **type**: functional
- **preconditions**: fixture payload naming an unqualified data service
- **steps**: `translate()`
- **expected result**: `UwlrEduVTranslationException` naming the unknown data service
- **test command**: PHPUnit

### TC-7: a complete Basispoort sync payload carries the SSO audience
- **spec_ref**: `.../spec.md#req-004-basispoort-sync-translation-with-sso-hand-off`
- **type**: functional
- **preconditions**: fixture payload with `ssoAudience`
- **steps**: `BasispoortSyncTranslator::translate()`
- **expected result**: envelope carries `ssoAudience`
- **test command**: PHPUnit

### TC-8: a complete Entree content payload carries the SSO audience
- **spec_ref**: `.../spec.md#req-005-entree-content-sso-hand-off-translation`
- **type**: functional
- **preconditions**: fixture payload with `ssoAudience`
- **steps**: `EntreeContentSyncTranslator::translate()`
- **expected result**: envelope carries `ssoAudience`
- **test command**: PHPUnit

### TC-9: an accepted acknowledgement dispatches the event as accepted
- **spec_ref**: `.../spec.md#req-006-shared-acknowledgement-translation-and-event-dispatch`
- **type**: functional
- **preconditions**: fixture retour XML, accepted status
- **steps**: `UwlrEduVAcknowledgementTranslator::translate()` then dispatch
- **expected result**: event reports `accepted: true`
- **test command**: PHPUnit

### TC-10: a retour with no kenmerk is rejected before dispatch
- **spec_ref**: `.../spec.md#req-006-shared-acknowledgement-translation-and-event-dispatch`
- **type**: functional
- **preconditions**: fixture retour XML missing `kenmerk`
- **steps**: `translate()`
- **expected result**: `UwlrEduVTranslationException`, no dispatch
- **test command**: PHPUnit

### TC-11: a failed send persists and is retried in isolation
- **spec_ref**: `.../spec.md#req-007-per-target-audit-persistence-and-isolated-retry`
- **type**: functional
- **preconditions**: two failed rows across different targets, one raises again on retry
- **steps**: run `UwlrEduVRetryJob::run()`
- **expected result**: failing row logged and skipped, other row retried
- **test command**: PHPUnit

### TC-12: the uwlr export endpoint returns a ref on success
- **spec_ref**: `.../spec.md#req-008-pushsync-endpoints-and-a-shared-signed-retour-endpoint`
- **type**: api
- **preconditions**: authenticated session, `log` source active
- **steps**: `POST /api/uwlr-eduv/uwlr` with a complete pupil payload
- **expected result**: HTTP 200, `{ref, target: "uwlr", status: "sent"}`
- **test command**: PHPUnit controller test

### TC-13: an unsigned retour is rejected before processing
- **spec_ref**: `.../spec.md#req-008-pushsync-endpoints-and-a-shared-signed-retour-endpoint`
- **type**: security
- **preconditions**: missing/invalid HMAC header
- **steps**: `POST /api/uwlr-eduv/retour`
- **expected result**: HTTP 401, no `uwlr_eduv_message` record created
- **test command**: PHPUnit controller test

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-001 | TC-1, TC-2 |
| REQ-002 | TC-3, TC-4 |
| REQ-003 | TC-5, TC-6 |
| REQ-004 | TC-7 |
| REQ-005 | TC-8 |
| REQ-006 | TC-9, TC-10 |
| REQ-007 | TC-11 |
| REQ-008 | TC-12, TC-13 |
| REQ-009 | verified via `check_icon_vocabulary.py` and catalogue descriptor test, not a PHPUnit TC |

## Out of Scope

- Live UWLR/Edu-V/Basispoort/Entree-content traffic — blocked on each
  target's own certification/aansluiting (M3(c)).
- Playwright/e2e coverage — every scenario carries `@e2e exclude`.
- UWLR's "results back" import direction — `integriq-adapter-lvs-imports`'
  scope, not this change's.
- `entree-surfconext-sso-contract`'s login-federation boundary — a
  separate learniq/Nextcloud-app-level change.
