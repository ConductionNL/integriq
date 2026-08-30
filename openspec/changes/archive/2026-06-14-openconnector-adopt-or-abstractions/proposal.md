# openconnector: adopt OpenRegister abstractions

## Why

The OR-abstraction audit (2026-05-03) places openconnector at Tier 2 — backend-heavy with
some legitimately app-specific machinery (mapping/rule transforms, source orchestration)
and some duplicated abstractions that now live in OR.

Findings driving this change:

- **Naming collision**: `lib/Service/ObjectService.php` is a connector-specific source +
  mapping orchestrator, not a generic object CRUD service. It collides cognitively with
  OR's generic `ObjectService`. The audit (stream 1) recommends renaming to
  `SourceMappingService`.
- **Hardcoded schema GUIDs**: `lib/Service/RuleService.php:125-131` has eight `PROP_*`
  constants holding raw schema property GUIDs (e.g. `id-7d91e5c8-…`). Brittle to schema
  rebuilds; should reference schemas by slug.
- **Triplicated retention constants**: `DEFAULT_SUCCESS_LOG_RETENTION = 3600000` and
  `DEFAULT_ERROR_LOG_RETENTION = 2592000000` appear identical in three services
  (`JobService`, `CallService`, `SynchronizationService`). Belongs in OR's
  `x-openregister-archival` annotation, declared once on the log schema.
- **Hardcoded magic numbers**: `EndpointCacheService::CACHE_TTL = 3600`,
  `SoftwareCatalogueService::SUFFIX = '-sc'` belong in admin-config.
- **State-enum smell on the wire**: synchronization-contracts and synchronizations
  controllers expose `'status' => 'active'|'inactive'|'error'|'success'|...` as filter
  values. Some of these are legitimate filter taxonomy, some are lifecycle states. Audit
  flags both for a per-value review.
- **Lifecycle status writes**: `DSOController:115` and `EventService:170,256` write
  `'status' => 'ontvangen'|'pending'` inline.
- **No app manifest** declaring tier, dependencies, or consumed shared specs.

Findings explicitly KEPT (per audit, not migrated):

- **Domain-specific stores**: openconnector has 20+ Pinia modules (source, mapping,
  endpoint, contract, webhooks, rule, etc). The audit (stream 1) classifies these as
  intentionally domain-specific, NOT duplication of OR's object store. KEEP as-is. This
  spec explicitly notes that.
- **Mapping/Rule rewrite engine**: by-design transforms between schemas, app-local. KEEP.

This change adopts the OR-side specs (`register-resolver-service`,
`pluggable-integration-registry`, `i18n-source-of-truth`,
`i18n-api-language-negotiation`), the nc-vue `multi-tenancy-context` spec, and the Hydra
`adopt-app-manifest` change. It also migrates retention constants and lifecycle writes onto
OR annotations.

The audit references this proposal must respect:

- `.claude/audit-2026-05-03/01-code-cleanup.md` (stream 1: rename, keep stores, keep mapping)
- `.claude/audit-2026-05-03/02-spec-rewrite.md` (stream 2: prometheus-metrics current,
  ibabs-notubiz + stuf-adapter integrations stay app-local)
- `.claude/audit-2026-05-03/04-hardcoded.md` (stream 4: GUID consts, retention, magic numbers)
- `hydra/openspec/architecture/ADR-022.md` (lifecycle annotation)
- `hydra/openspec/architecture/ADR-024.md` (archival annotation)

## What Changes

### Code-level renames + de-duplication

1. Rename `lib/Service/ObjectService.php` to `lib/Service/SourceMappingService.php` (S
   effort, M impact). Disambiguates from OR's generic `ObjectService`. All call sites
   updated.
2. Replace eight `PROP_*` GUID constants in `RuleService.php:125-131` with schema-slug
   based property references. Lookup happens at boot time via `RegisterResolverService`.
3. Consolidate `DEFAULT_SUCCESS_LOG_RETENTION` / `DEFAULT_ERROR_LOG_RETENTION` triplicated
   across `JobService`, `CallService`, `SynchronizationService` into a single
   `x-openregister-archival` declaration on the log schema. Drop the source constants.

### Lifecycle annotation migration

4. `lib/Controller/DSOController.php:115` `'status' => 'ontvangen'` and
   `lib/Service/EventService.php:170,256` `'status' => 'pending'` migrated to
   `x-openregister-lifecycle` annotation per ADR-022.
5. Status filter values exposed by `SynchronizationContractsController:366-368` and
   `SynchronizationsController:510-514` reviewed: filter-only values stay in the
   controller's filter whitelist; lifecycle states migrate to the annotation. The
   spec's scenario list documents the per-value disposition.

### Hardcoded magic-number cleanup

6. `EndpointCacheService::CACHE_TTL` → admin-config.
7. `SoftwareCatalogueService::SUFFIX` → admin-config.

### Manifest + multi-tenancy + i18n adoption

8. `openspec/manifest.yaml` — Tier 2, `dependencies: ["openregister"]`,
   `consumes: [register-resolver-service, pluggable-integration-registry,
   i18n-source-of-truth, i18n-api-language-negotiation, multi-tenancy-context]`.
9. Frontend stores stay app-local but consume `multi-tenancy-context` for tenant scope.
10. i18n adoption for endpoint/source/mapping label + description + error messages.

### Spec note: domain-specific stores stay

11. The new `openconnector-or-adoption` capability spec explicitly documents that the 20+
    Pinia modules are intentionally domain-specific and not subject to the
    `createObjectStore` exemplar pattern (which applies to apps with generic object views).

## Impact

- Affected code (apply-phase hints, NOT changed here):
  `lib/Service/ObjectService.php` (rename), `lib/Service/RuleService.php`,
  `lib/Service/JobService.php`, `lib/Service/CallService.php`,
  `lib/Service/SynchronizationService.php`, `lib/Service/EndpointCacheService.php`,
  `lib/Service/SoftwareCatalogueService.php`,
  `lib/Controller/DSOController.php`,
  `lib/Service/EventService.php`,
  `lib/Controller/SynchronizationContractsController.php`,
  `lib/Controller/SynchronizationsController.php`.
- Affected specs: `openspec/specs/prometheus-metrics/spec.md` (kept current; cite from this
  change), `ibabs-notubiz-connector` and `stuf-adapter` change folders (kept app-local;
  cite from this change). New `openconnector-or-adoption` capability spec.
- Breaking changes:
  - `ObjectService` → `SourceMappingService` rename. Any external consumer importing the
    class breaks; grep confirms current usages are internal only. PHP class alias kept for
    one minor version per ADR-022 deprecation policy.
  - `RuleService::PROP_*` constants removed. Internal-only; no external consumers.
  - `DEFAULT_SUCCESS_LOG_RETENTION` / `DEFAULT_ERROR_LOG_RETENTION` removed.
- Dependencies: same as docudesk — OR + nc-vue + Hydra ship the prerequisite specs first.
