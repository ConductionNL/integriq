# Test Plan: environments-and-promotion

## Test Cases

### TC-1: Creating an environment requires an existing Source reference
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001`
- **type**: api
- **preconditions**: An admin session; an existing `type: api` Source object
- **steps**: `POST /api/environments` with `slug: "acceptance"`, `role: "target"`, `sourceRef` = the existing Source's uuid
- **expected result**: HTTP 200/201; the `environment` object is created; no new credential material is stored on it
- **test command**: /test-api

### TC-2: An environment without a resolvable sourceRef cannot be used as a promotion target
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-named-environments-are-openregister-objects-that-wrap-an-existing-source-for-connectivity-req-001`
- **type**: api
- **preconditions**: An `environment` object whose `sourceRef` uuid has since been deleted
- **steps**: `POST /api/promotions/preview` targeting that environment
- **expected result**: Actionable error naming the missing `sourceRef`; no export or remote call attempted
- **test command**: /test-api

### TC-3: Promotion reuses the unmodified export pipeline
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-promotion-exports-locally-unchanged-and-dispatches-to-the-targets-existing-import-endpoints-req-002`
- **type**: api
- **preconditions**: A configuration group `cfg-1` with one Source (apikey set) and one Endpoint
- **steps**: `POST /api/promotions/preview` for `cfg-1`
- **expected result**: The document underlying the preview reflects REQ-001–REQ-005 export/redaction/slug-translation exactly as a manual `/api/configurations/{id}/export` call would produce
- **test command**: /test-api

### TC-4: Promotion dispatch reuses CallService against the target's environment Source
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-promotion-exports-locally-unchanged-and-dispatches-to-the-targets-existing-import-endpoints-req-002`
- **type**: integration
- **preconditions**: Two OpenConnector instances (A, B) reachable from each other; `environment` object on A pointing at a Source describing B's API
- **steps**: Confirm a promotion from A to B
- **expected result**: A `CallLog` is created on A for the dispatch, identical in shape to any other Source call's CallLog
- **test command**: /test-api

### TC-5: Preview reflects the target's own creates/updates/collisions classification
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003`
- **type**: api
- **preconditions**: Target environment already has a Source whose slug matches one Source in the export document; a second Source's slug is new
- **steps**: `POST /api/promotions/preview`
- **expected result**: `updates` contains the first Source, `creates` contains the second — sourced from the target's own `/api/configurations/import/preview` response
- **test command**: /test-api

### TC-6: Preview is computed internally before every confirmed promotion
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003`
- **type**: api
- **preconditions**: Valid configuration id and target environment
- **steps**: `POST /api/promotions` with `confirmed: true` directly, without a separate prior preview call
- **expected result**: The confirm call still computes an equivalent preview internally before dispatching the write (mirrors REQ-008's `import()` behaviour)
- **test command**: /test-api

### TC-7: A Source's credentialRef is flagged for rebinding
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-credentialref-placeholders-are-re-bound-per-target-environment-never-resolved-to-a-secret-req-004`
- **type**: api
- **preconditions**: Configuration group containing a Source with `configuration.authentication.credentialRef.credentialId` set
- **steps**: `POST /api/promotions/preview`
- **expected result**: `credentialRefsNeedingRebind` contains that Source's slug and field path
- **test command**: /test-api

### TC-8: Operator-supplied rebinding replaces the reference before the target sees the original (integration, credentialRef re-bind not secret copy)
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-credentialref-placeholders-are-re-bound-per-target-environment-never-resolved-to-a-secret-req-004`
- **type**: api
- **preconditions**: Flagged Source; `credentialBindings` supplying a target-valid `credentialName`
- **steps**: `POST /api/promotions` with `confirmed: true` and the `credentialBindings` entry; capture the outbound document (test double on the dispatch layer)
- **expected result**: The dispatched document's `credentialRef.credentialName` equals the supplied replacement, not the source environment's original `credentialId`; no plaintext secret appears anywhere in the request/response/log
- **test command**: /test-api

### TC-9: An un-rebound credentialRef is sent verbatim, not resolved or dropped
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-credentialref-placeholders-are-re-bound-per-target-environment-never-resolved-to-a-secret-req-004`
- **type**: api
- **preconditions**: Flagged Source with no `credentialBindings` entry
- **steps**: Confirm the promotion
- **expected result**: The dispatched document's `credentialRef` is byte-for-byte the original; the eventual failure (if any) surfaces only when the target later calls that Source, not during promotion
- **test command**: /test-api

### TC-10: Promotion without confirmation is rejected
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005`
- **type**: api
- **preconditions**: Valid configuration id and target environment
- **steps**: `POST /api/promotions` with `confirmed` omitted or `false`
- **expected result**: HTTP 400; no dispatch; no `promotion_audit` object written
- **test command**: /test-api

### TC-11: A user without environment.promote cannot promote
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-promotion-requires-explicit-confirmation-and-the-same-action-matrix-authorization-as-exportimport-req-005`
- **type**: security
- **preconditions**: Non-admin user whose groups are unmapped to `environment.promote`
- **steps**: Call preview and confirm endpoints
- **expected result**: `OCSForbiddenException` before any export or remote call
- **test command**: /test-api
- **@e2e exclude**: API-level action-matrix denial has no browser surface — covered by PHPUnit `PromotionControllerTest::testPromoteDeniedForUnmappedNonAdmin`

### TC-12: A successful promotion is audited
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-every-promotion-attempt-is-recorded-in-an-append-only-promotion-audit-log-req-006`
- **type**: api
- **preconditions**: A confirmable promotion writing two Sources and one Endpoint
- **steps**: Confirm the promotion
- **expected result**: A `promotion_audit` object exists with `outcome: "success"`, correct `fromEnvironmentSlug`/`toEnvironmentSlug`, a counts-only `previewSummary`, and no credential values or full entity payloads
- **test command**: /test-api

### TC-13: A failed promotion is still audited
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-every-promotion-attempt-is-recorded-in-an-append-only-promotion-audit-log-req-006`
- **type**: api
- **preconditions**: Target environment simulated to return 404 (older OpenConnector without import routes)
- **steps**: Confirm the promotion
- **expected result**: A `promotion_audit` object exists with `outcome: "failed"` and a message identifying the failure; no fabricated `written` summary
- **test command**: /test-api

### TC-14: promotion_audit objects cannot be edited or deleted after creation
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-every-promotion-attempt-is-recorded-in-an-append-only-promotion-audit-log-req-006`
- **type**: api
- **preconditions**: An existing `promotion_audit` object
- **steps**: Attempt `PUT`/`DELETE` on it via the OpenRegister object API
- **expected result**: Rejected by `appendOnly`/`immutable` enforcement, matching `call_log`/`job_log` behaviour
- **test command**: /test-api

### TC-15: A Source's credentialRef is not redacted on export (configuration-export-import delta)
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/configuration-export-import/spec.md#requirement-credentialref-authentication-placeholders-pass-through-export-and-import-unresolved-and-untranslated-req-010`
- **type**: regression
- **preconditions**: A Source with `configuration.authentication.credentialRef.credentialId` set
- **steps**: Export the Source via `SourceHandler::export()`
- **expected result**: The `credentialRef.credentialId` value is unchanged, not `***REDACTED***`
- **test command**: /test-functional (PHPUnit, no browser surface)

### TC-16: Importing a non-resolving credentialRef does not block the write (configuration-export-import delta)
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/configuration-export-import/spec.md#requirement-credentialref-authentication-placeholders-pass-through-export-and-import-unresolved-and-untranslated-req-010`
- **type**: regression
- **preconditions**: OAS document with a Source whose `credentialRef.credentialId` does not exist on the importing environment
- **steps**: `importConfiguration()`
- **expected result**: The Source object is written with the reference verbatim; no exception at import time
- **test command**: /test-functional (PHPUnit, no browser surface)

### TC-17: Environments & Promotion page lists environments with accessible selects
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#non-functional-requirements`
- **type**: accessibility
- **preconditions**: At least two seeded environments
- **steps**: Open the Environments & Promotion page; inspect the target-environment `NcSelect`
- **expected result**: Environments render as a list/table; the select carries an explicit `inputLabel`
- **test command**: /test-accessibility

### TC-18: Promote flow shows diff preview before confirm is enabled
- **spec_ref**: `openspec/changes/environments-and-promotion/specs/environments-and-promotion/spec.md#requirement-diff-preview-merges-the-targets-existing-preview-response-with-a-credential-rebind-classification-req-003`
- **type**: functional
- **preconditions**: A configuration group and a target environment with at least one collision/create
- **steps**: Open the promote flow, select configuration + target
- **expected result**: `PromotePreviewModal` renders creates/updates/collisions and `credentialRefsNeedingRebind`; Confirm is disabled until the preview has loaded
- **test command**: /test-functional

## Coverage Summary
- REQ-001 (named environments wrap a Source): TC-1, TC-2 — covered
- REQ-002 (promotion reuses export + dispatch): TC-3, TC-4 — covered
- REQ-003 (diff preview reuse + merge): TC-5, TC-6, TC-18 — covered
- REQ-004 (credentialRef re-binding, never secret copy): TC-7, TC-8, TC-9 — covered
- REQ-005 (confirmation + action-matrix authorization): TC-10, TC-11 — covered
- REQ-006 (promotion audit log): TC-12, TC-13, TC-14 — covered
- configuration-export-import REQ-010 (credentialRef pass-through contract): TC-15, TC-16 — covered
- Non-Functional (accessibility, i18n): TC-17 — covered (i18n verified via code review of translation keys, no dedicated TC — all strings added under `l10n/en.json`/`l10n/nl.json` per existing convention)

## Out of Scope
- Git-backed configuration storage / GitOps (proposal.md Out of Scope) — no test cases.
- Unattended/scheduled promotion — this change is operator-confirmed only; no test cases for automated triggers.
- Multi-hop promotion chains (A→B→C) — not supported; no test cases.
