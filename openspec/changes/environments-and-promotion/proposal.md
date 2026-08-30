# Proposal: environments-and-promotion

## Summary
This change adds first-class named environments (e.g. staging, production) and a
promotion workflow to Integriq: promoting a configuration group means
exporting it from the local instance via the existing `ConfigurationService`
and pushing it into a registered target environment's existing import
endpoints, with a pre-promotion diff preview, explicit credential re-binding
(never secret copying) via the OpenRegister credential broker, and an
append-only promotion audit log. It builds entirely on the already-merged
configuration export/import substrate (slug translation, credential
redaction) and the credential broker (`source-broker-credentials`) — nothing
in either is forked.

## Motivation
n8n gates environment promotion behind its paid Enterprise tier; Workato
sells this as "Recipe Lifecycle Management." Integriq already has the
hard parts — slug-referenced export/import, credential redaction, an import
preview endpoint, and a `credentialRef`-based credential broker — but no
concept of a *named* target environment, no automated push between
environments, no pre-promotion diff, and no audit trail of who promoted what,
from where, to where, and when. Shipping this open under EUPL is a
Common-Ground procurement wedge: government customers evaluating n8n/Workato
alternatives can get environment promotion without an enterprise license.
Codeberg issue #155.

## Affected Projects
- [x] Project: `integriq` — new `environment` and `promotion_audit`
  OpenRegister schemas, `PromotionService`, `PromotionController` + routes,
  new `environment.manage` / `environment.promote` ADR-023 action keys, and
  an Environments & Promotion manifest-v2 UI page.

## Scope

### In Scope
1. An `environment` OpenRegister object schema (name, slug, role, and a
   `sourceRef` pointing at an existing `source`-schema object of
   `type: "api"` that describes how to reach that environment's Integriq
   API — reusing the Source schema's existing `location` +
   `configuration.authentication.credentialRef` shape instead of inventing a
   new connection-descriptor format).
2. A `PromotionService` that: (a) calls the existing, unmodified
   `ConfigurationService::exportConfiguration()` locally; (b) dispatches the
   exported document to the target environment's existing, unmodified
   `POST /api/configurations/import/preview` and `POST
   /api/configurations/import` endpoints (REQ-007/REQ-008) via the existing
   `CallService::call()` outbound pipeline, using the target environment's
   `sourceRef` Source — so retry, rate-limiting, CallLog auditing, and
   `credentialRef` broker resolution for reaching the target are all reused
   unchanged, not reimplemented.
3. Explicit credential re-binding: any `Source` in the exported document whose
   `configuration.authentication` carries a `credentialRef` placeholder is
   surfaced by the preview as needing an operator-supplied re-binding
   (`credentialId`/`credentialName` valid in the TARGET environment's broker)
   before the promotion is confirmed. `credentialRef` values are never
   resolved to plaintext and never copied between environments — only the
   reference is rewritten.
4. A diff preview step before promotion, reusing the target environment's
   existing import-preview response (creates/updates/collisions/unresolved
   references/credentials-needing-reentry) plus a promotion-specific
   `credentialRefsNeedingRebind` bucket computed client-side from the
   exported document.
5. An append-only, immutable `promotion_audit` OpenRegister object schema
   (who, configuration id, from-environment, to-environment, timestamp,
   preview summary, outcome) written after every promotion attempt,
   following the same `appendOnly`/`immutable` convention as the existing
   `call_log`/`job_log` schemas.
6. An Environments & Promotion manifest-v2 UI page: environment CRUD list and
   a promote flow (select configuration group → select target environment →
   review diff + credential rebind prompts → confirm).
7. Unit tests for environment metadata and credential-rebind resolution;
   integration tests exporting from environment A and importing into
   environment B, asserting `credentialRef`s are re-bound, not copied as
   secrets.

### Out of Scope
- Git-backed configuration storage / GitOps workflows — a follow-up change.
- Automatic, unattended promotion (e.g. on a schedule or CI trigger) — this
  change is operator-confirmed only, matching REQ-008's existing
  confirmation requirement.
- Multi-hop promotion chains (A→B→C in one operation) — one promotion is
  always a single source→target pair.

## Approach
Reuse, don't fork. Environment connectivity is modelled as an existing
`source`-schema object so the existing `CallService`/`BrokeredCallService`
dispatch pipeline (auth, retry, CallLog, redaction) carries promotion traffic
without new HTTP client code. The diff preview is the existing target-side
`/api/configurations/import/preview` endpoint, invoked remotely instead of
in-process — no new diff algorithm. Credential re-binding is a thin
preprocessing/postprocessing layer in `PromotionService` around the
unmodified export/import pipeline: it never touches `ConfigurationHandlers`
or `SensitiveFieldRegistry`. See design.md for the full architecture and the
credential-rebinding decision.

## New Dependencies
None — reuses `ConfigurationService`, `ConfigurationImportPreviewService`,
`CallService`, `BrokeredCallService`, and OpenRegister's
`CredentialBrokerService`, all already present.

## Impact
- New: `lib/Service/PromotionService.php`, `lib/Controller/PromotionController.php`,
  `lib/Settings/integriq_register.json` additions (`environment`,
  `promotion_audit` schemas), `lib/actions.seed.json` additions, `appinfo/routes.php`
  additions, a new manifest-v2 page + Vue components under `src/`.
- Unchanged: `ConfigurationService`, `ConfigurationHandlers/*`,
  `ConfigurationImportPreviewService`, `SensitiveFieldRegistry`,
  `BrokeredCallService`, `CallService`.

## Cross-Project Dependencies
Depends on OpenRegister's `CredentialBrokerService` (already a hard runtime
dependency per `openconnector-direct-or-usage`) for resolving a target
environment's connection credential and for validating operator-supplied
credential re-bindings. No other apps consume this change.

## Risks

### Risk 1: Target environment API version skew
**Severity:** Medium — **Mitigation:** `PromotionService` calls the target's
`/api/configurations/import/preview` and `/api/configurations/import`
endpoints exactly as documented in `configuration-export-import` (REQ-007/
REQ-008); a target running an older Integriq without those routes
returns 404, surfaced to the operator as a promotion failure with an
actionable message, not a silent partial write.

### Risk 2: Operator promotes with an unresolved credentialRef
**Severity:** Medium — **Mitigation:** the diff preview's
`credentialRefsNeedingRebind` bucket is a blocking warning; `import` on the
target still enforces REQ-008's `confirmed: true` gate, and an unrebound
`credentialRef` that does not resolve on the target simply fails at the
target's own Source-auth guard (`BrokeredCallService`) the first time that
Source is used — never at promotion time with a leaked secret, because no
secret ever transits the promotion call.

### Risk 3: Promotion audit log grows unbounded
**Severity:** Low — **Mitigation:** `promotion_audit` follows the existing
log-schema retention convention (`x-openregister-archival`), matching
`call_log`/`job_log`.

## Rollback Strategy
The new schemas, service, controller, routes, and UI page are additive. To
roll back, remove the routes and hide the manifest page; the `environment`
and `promotion_audit` OpenRegister objects remain harmless, inert data.
`ConfigurationService` and `CredentialBrokerService` are never modified, so
rollback carries zero risk to existing export/import or brokered-call
functionality.

## Open Questions
- Should a promotion be retryable/resumable if the target import partially
  succeeds (e.g. sources written, endpoints fail)? Deferred to design.md;
  current default follows `importConfiguration()`'s existing per-type
  best-effort behaviour (unchanged), recorded as a known limitation in the
  audit entry rather than solved with new rollback machinery.
