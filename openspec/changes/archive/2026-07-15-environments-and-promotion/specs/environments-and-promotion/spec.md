---
status: planned
---

# environments-and-promotion Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- environments-and-promotion

## Purpose

OpenConnector's `configuration-export-import` capability already moves a
coherent set of Sources/Endpoints/Mappings/Rules/Jobs/Synchronizations
between instances via a slug-referenced, credential-redacted OAS document,
and `source-broker-credentials` already lets a Source authenticate through a
`credentialRef` resolved by the OpenRegister credential broker instead of an
embedded secret. Neither capability names *environments* as first-class
objects, neither automates the export-then-import round trip between two
named environments, and neither surfaces a diff before writing or an audit
trail of who promoted what, from where, to where, and when. This capability
adds named `environment` metadata, a `PromotionService` that reuses
`ConfigurationService`, `ConfigurationImportPreviewService`'s existing
routed endpoints, and `CallService`/`BrokeredCallService`'s existing
dispatch pipeline to push a configuration group from one environment into
another, with mandatory diff preview, explicit credential re-binding
(references only, never secrets), and an append-only promotion audit log.
See `configuration-export-import` (REQ-001–REQ-010) and
`source-broker-credentials`'s `http-call-engine` delta for the underlying,
unmodified primitives this capability builds on.

## ADDED Requirements

### Requirement: Named environments are OpenRegister objects that wrap an existing Source for connectivity (REQ-001)

The system SHALL persist named environments as `environment`-schema
OpenRegister objects in the `openconnector` register (`name`, `slug`,
`role` of `source`, `target`, or `both`, `description`, `sourceRef`). The
system SHALL NOT store environment connectivity as a new credential format,
a new HTTP client configuration, or an `IAppConfig` value: `sourceRef`
SHALL reference an existing `source`-schema object (`type: "api"`) whose
`location` and `configuration.authentication.credentialRef` describe how to
reach that environment's OpenConnector API, so that dispatching a call to an
environment reuses `CallService::call()` and, when the referenced Source
carries a `credentialRef`, `BrokeredCallService`'s existing broker
resolution — unchanged and unforked.

#### Scenario: Creating an environment requires an existing Source reference
- GIVEN an operator with the `environment.manage` action permission
- WHEN they create an `environment` object with `slug: "acceptance"`, `role: "target"`, and `sourceRef` pointing at an existing `type: "api"` Source
- THEN the `environment` object is created in the `openconnector` register
- AND no new credential or connection material is stored on the `environment` object itself

#### Scenario: An environment without a resolvable sourceRef cannot be used as a promotion target
- GIVEN an `environment` object whose `sourceRef` no longer resolves to an existing Source
- WHEN an operator attempts to preview or confirm a promotion to that environment
- THEN the request is rejected with an actionable error naming the missing `sourceRef`
- AND no export or remote call is attempted

### Requirement: Promotion exports locally, unchanged, and dispatches to the target's existing import endpoints (REQ-002)

The system SHALL implement promotion as: (1) calling the existing, unmodified
`ConfigurationService::exportConfiguration()` on the local instance to
produce the OAS document for the requested configuration id; (2) dispatching
that document to the target environment's own, unmodified `POST
/api/configurations/import/preview` (preview) or `POST
/api/configurations/import` (confirmed) endpoint via `CallService::call()`
against the target environment's `sourceRef` Source. The system SHALL NOT
reimplement export, slug translation, or redaction logic inside the
promotion path — `ConfigurationService` and its handlers remain the single
source of truth for both.

#### Scenario: Promotion reuses the unmodified export pipeline
- GIVEN a configuration group `cfg-1` containing one Source and one Endpoint
- WHEN an operator promotes `cfg-1` from the local environment to a registered target environment
- THEN the system calls `ConfigurationService::exportConfiguration('cfg-1')` unchanged to build the document
- AND the redaction and slug-translation behaviour documented in `configuration-export-import` (REQ-001–REQ-005) applies identically to a promotion export as to a manual UI export

#### Scenario: Promotion dispatch reuses CallService against the target's environment Source
- GIVEN a target environment whose `sourceRef` Source has `location: "https://acceptance.example.org"`
- WHEN a promotion is confirmed
- THEN the system dispatches the import call via `CallService::call()` using that Source
- AND the resulting `CallLog` is created exactly as it would be for any other Source call against that Source

### Requirement: Diff preview merges the target's existing preview response with a credential-rebind classification (REQ-003)

The system SHALL, before any promotion write occurs, retrieve a preview by
calling the target environment's existing `POST
/api/configurations/import/preview` endpoint (`configuration-export-import`
REQ-007, unmodified) with the exported document, and SHALL merge that
response with a `credentialRefsNeedingRebind` array computed locally by
scanning the exported document for `{"credentialRef": {...}}` placeholders
(REQ-004 below). The system SHALL NOT compute creates/updates/collisions or
unresolved slug references itself — that classification SHALL always come
from the target environment's own preview response, since only the target
knows its own object state.

#### Scenario: Preview reflects the target's own creates/updates/collisions classification
- GIVEN a Source in the export document whose slug already exists on the target environment, and a second Source whose slug does not
- WHEN the promotion preview is requested
- THEN the response's `updates` array contains the first Source and `creates` contains the second, exactly as the target's own `/api/configurations/import/preview` response would classify them

#### Scenario: Preview is required before a promotion can be confirmed
- GIVEN a valid configuration id and target environment
- WHEN an operator attempts to confirm a promotion without having first retrieved a preview in the same request flow
- THEN the system still computes the preview internally as part of the confirm call (mirroring REQ-005's `confirmed: true` requirement) before dispatching the write, so a promotion can never write without an equivalent preview having been computed

### Requirement: credentialRef placeholders are re-bound per target environment, never resolved to a secret (REQ-004)

The system SHALL detect every `{"credentialRef": {"credentialId": ...}}` or
`{"credentialRef": {"credentialName": ...}}` placeholder inside a promoted
Source's `configuration.authentication` (the same shape
`BrokeredCallService::isPlaceholder()` detects) and SHALL list each one under
the preview's `credentialRefsNeedingRebind` array, naming the Source slug and
field path. The system SHALL rewrite a flagged placeholder in the outgoing
document only when the operator supplies an explicit replacement reference
(`credentialId` or `credentialName` valid on the target) as part of the
promotion request; an un-rebound placeholder SHALL be sent to the target
verbatim (carrying the source environment's own reference), never silently
dropped or defaulted. The system SHALL NOT, at any point during promotion,
call any credential-broker method that returns a plaintext secret value —
re-binding SHALL operate on reference strings only.

#### Scenario: A Source's credentialRef is flagged for rebinding
- GIVEN a Source in the export document with `configuration.authentication.credentialRef.credentialId` set to a UUID from the source environment's credential broker
- WHEN the promotion preview is computed
- THEN the response's `credentialRefsNeedingRebind` array contains that Source's slug and the field `configuration.authentication.credentialRef`

#### Scenario: An operator-supplied rebinding replaces the reference before the target ever sees the original
- GIVEN the promotion request includes `credentialBindings: [{"sourceSlug": "my-api-source", "credentialName": "prod-api-key"}]` for a flagged Source
- WHEN the promotion is confirmed
- THEN the document dispatched to the target environment's import endpoint contains `configuration.authentication.credentialRef.credentialName = "prod-api-key"` for that Source, not the original source-environment credentialId
- AND at no point does the system read or transmit the plaintext secret behind either reference

#### Scenario: An un-rebound credentialRef is sent verbatim, not resolved or dropped
- GIVEN a flagged Source with no corresponding entry in the promotion request's `credentialBindings`
- WHEN the promotion is confirmed
- THEN the document dispatched to the target contains the original, unmodified `credentialRef` value
- AND the target's own Source-auth guard (not the promotion path) is what eventually fails when that Source is eventually called against a credential that does not exist on the target

### Requirement: Promotion requires explicit confirmation and the same action-matrix authorization as export/import (REQ-005)

The system SHALL require `confirmed: true` on the confirm request and SHALL
reject the request with HTTP 400 if absent, mirroring
`configuration-export-import` REQ-008. Both the preview and confirm
promotion endpoints SHALL be gated by `ActionAuthService::requireAction()`
with a new `environment.promote` action key seeded `["admin"]` in
`lib/actions.seed.json`; environment CRUD endpoints SHALL be gated by a
separate `environment.manage` action key, also seeded `["admin"]`.

#### Scenario: Promotion without confirmation is rejected
- GIVEN a valid configuration id and target environment
- WHEN the confirm endpoint is called with `confirmed` omitted or `false`
- THEN the response is HTTP 400
- AND no export is dispatched to the target and no `promotion_audit` object is written

#### Scenario: A user without the environment.promote action permission cannot promote
- GIVEN a non-admin user whose groups are not mapped to `environment.promote` in the action matrix
- WHEN that user calls the promotion preview or confirm endpoint
- THEN the request is rejected with `OCSForbiddenException` before any export or remote call occurs
- @e2e exclude API-level action-matrix denial — covered by PHPUnit `PromotionControllerTest::testPromoteDeniedForUnmappedNonAdmin`

### Requirement: Every promotion attempt is recorded in an append-only promotion audit log (REQ-006)

The system SHALL write one `promotion_audit` OpenRegister object per
confirmed promotion attempt (success or failure), recording the acting
user, the configuration id, the source and target environment slugs, start
and completion timestamps, the outcome, a preview summary (counts and
slugs only — never entity payloads or credential values), the number of
credential rebindings applied, and the id of the `CallLog` created by the
underlying dispatch. The `promotion_audit` schema SHALL be declared
`appendOnly: true` and `immutable: true`, following the same convention as
the existing `call_log`/`job_log` schemas.

#### Scenario: A successful promotion is audited
- GIVEN a confirmed promotion of `cfg-1` from `local` to `acceptance` that writes two Sources and one Endpoint
- WHEN the promotion completes
- THEN a `promotion_audit` object is created with `outcome: "success"`, `fromEnvironmentSlug: "local"`, `toEnvironmentSlug: "acceptance"`, and a `previewSummary` reflecting the two creates/updates
- AND the object contains no credential values or full entity payloads

#### Scenario: A failed promotion is still audited
- GIVEN a confirmed promotion whose dispatch to the target fails (e.g. the target returns 404 because it runs an older OpenConnector without the import routes)
- WHEN the promotion attempt completes
- THEN a `promotion_audit` object is created with `outcome: "failed"` and a message identifying the failure
- AND no partial `written` summary is fabricated — only what the target actually confirmed, if anything, is recorded

#### Scenario: promotion_audit objects cannot be edited or deleted after creation
- GIVEN an existing `promotion_audit` object
- WHEN any caller attempts to update or delete it via the OpenRegister object API
- THEN the write is rejected by OpenRegister's `appendOnly`/`immutable` schema enforcement, identically to how `call_log`/`job_log` objects are protected today

## Non-Functional Requirements

- **Performance:** A promotion preview SHALL complete within the same order
  of magnitude as a manual export (REQ-001's documented O(all entities of
  each type) cost) plus one additional network round trip to the target
  environment; no new O(n²) behaviour is introduced.
- **Accessibility:** The Environments & Promotion UI page's environment and
  target selects MUST use `NcSelect` with an explicit `inputLabel` (WCAG
  2.1 AA 1.3.1/4.1.2), and the promotion confirmation flow MUST live in its
  own `NcModal` file, never inlined in a parent component.
- **Internationalization:** All UI strings and API error messages MUST be
  in English, matching the project-wide i18n-keys-English convention.

## Acceptance Criteria

- [ ] `environment` objects can be created, listed, and reference an
      existing `source`-schema object via `sourceRef`
- [ ] A promotion preview merges the target's own REQ-007 response with a
      `credentialRefsNeedingRebind` bucket computed locally
- [ ] A `credentialRef` placeholder is never resolved to a plaintext secret
      anywhere in the promotion path
- [ ] A confirmed promotion without `confirmed: true` is rejected with 400
- [ ] Every confirmed promotion attempt (success or failure) produces exactly
      one immutable `promotion_audit` object

## Notes

- This capability intentionally does NOT modify `ConfigurationService`,
  `ConfigurationHandlers/*`, `ConfigurationImportPreviewService`,
  `SensitiveFieldRegistry`, `CallService`, or `BrokeredCallService` — see
  design.md Decisions 1-4 for the reuse rationale.
- `configuration-export-import` REQ-009's `credentialsNeedingReentry`
  bucket and this capability's `credentialRefsNeedingRebind` bucket are
  deliberately distinct: REQ-009 covers top-level fields
  `SourceHandler::export()` strips outright; this capability covers
  `credentialRef` reference placeholders, which are never stripped (see the
  `configuration-export-import` delta in this change, REQ-010).
- Git-backed configuration storage / GitOps is explicitly out of scope
  (proposal.md) and may build on `environment` metadata in a future change.
