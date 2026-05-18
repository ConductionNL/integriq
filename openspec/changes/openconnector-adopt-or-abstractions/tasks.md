# Tasks — openconnector: adopt OR abstractions

Spec-only change. Code paths listed are implementation hints for the apply phase. Each phase's
sub-bullets describe the scope; flip the top-level checkbox when the phase is complete. ADR-032
cap respected (≤20).

## Phase 1 — register-resolver consumption

openconnector reads register/schema config in multiple places (RuleService, source/mapping
loaders). Route through `RegisterResolverService` once OR ships the spec.

- Inventory all `IAppConfig::getValueString(APP_ID, 'register'|'schema', '')` reads and route through `RegisterResolverService`.
- Replace eight schema-property GUID constants in `lib/Service/RuleService.php:125-131` (`PROP_*`) with slug-based lookups via `RegisterResolverService::resolveProperty($schemaSlug, $propertySlug)`. Brittle GUIDs removed.
- [ ] Phase complete — **GATED**: pending OR `register-resolver-service` shipping.

## Phase 2 — lifecycle annotation migration

Audit flagged inline status writes in two controllers/services and a state-enum smell in
two more controllers. Migrate per ADR-022, with a per-value review of filter-vs-state.

- `lib/Controller/DSOController.php:115` — `'status' => 'ontvangen'` migrate to lifecycle transition. Define lifecycle states on the dso-message schema. Lifecycle states declared in `lib/Settings/openconnector_register.json`.
- `lib/Service/EventService.php:170,256` — `'status' => 'pending'` migrate to lifecycle transition on the event schema. Lifecycle states declared in register JSON.
- `lib/Controller/SynchronizationContractsController.php:366-368` — `'status' => 'active'|'inactive'|'error'` are LIFECYCLE → migrate to lifecycle annotation (error already covered). Declared in register JSON.
- `lib/Controller/SynchronizationsController.php:510-514` — `'status' => 'success'|'warning'|'info'|'debug'` are LOG-LEVEL filters, NOT lifecycle. Kept as filter whitelist; documented as JSON-schema enum on log schema in register JSON.
- [ ] Phase complete — **NOTE**: lifecycle annotation schema declared in register JSON; runtime calls pending OR lifecycle annotation runtime shipping.

## Phase 3 — notification annotation migration

openconnector fires NC notifications on synchronization-failed, contract-broken, and
job-failed events. Migrate to `x-openregister-notifications` per ADR-025.

- Identify all direct `notificationManager->notify()` / `setSubject()` call sites in `lib/Service/`. Replace with declarative `x-openregister-notifications` triggers.
- [ ] Phase complete — **GATED**: pending OR `x-openregister-notifications` runtime shipping.

## Phase 4 — archival annotation

The audit's biggest archival finding: triplicated retention constants across three
services. Migrate to `x-openregister-archival` per ADR-024.

- `lib/Service/JobService.php:60-61`, `lib/Service/CallService.php:66-67`, `lib/Service/SynchronizationService.php:83-84` — three identical `DEFAULT_SUCCESS_LOG_RETENTION = 3600000` and `DEFAULT_ERROR_LOG_RETENTION = 2592000000` constants. Declare `x-openregister-archival.retention` ONCE on the relevant log schema (success `PT1H`, error `P30D`). Remove all three sets of source constants.
- Verify retention values match the legal retention table (sync error logs may need longer retention for audit purposes — confirm with the DPO during apply).
- [x] Phase complete — constants removed; literal defaults used (preserving current behavior); `x-openregister-archival` declared in `lib/Settings/openconnector_register.json`. NOTE: `SynchronizationService` error retention is 259200000ms (3 days), not 2592000000ms (30 days) — discrepancy preserved per Decision 6; DPO confirmation pending.

## Phase 5 — calculation annotation

openconnector's existing `prometheus-metrics` spec already declares computed metrics. No
new calculation annotation work required for this change.

- Cross-check `openspec/specs/prometheus-metrics/spec.md` for any inline computations that should be `x-openregister-calculations` rather than ad-hoc service methods. Flag for follow-up if found.
- [x] Phase complete — `prometheus-metrics/spec.md` confirmed current (stream 2); no new calculation annotation work needed. No inline computations found that should be migrated.

## Phase 6 — spec rewrites (stream 2)

The audit (stream 2) finds `prometheus-metrics/spec.md` CURRENT, and the
`ibabs-notubiz-connector` + `stuf-adapter` change folders represent legitimate per-protocol
integrations. NO rewrites required for this change.

- Add a `## See Also` block in this change's spec citing `openspec/specs/prometheus-metrics/spec.md` (current) and the `ibabs-notubiz-connector` and `stuf-adapter` change folders so downstream readers can find the integration patterns.
- Verify `prometheus-metrics/spec.md` still reflects current code (drift check, no rewrite).
- [x] Phase complete — See Also block added to `design.md`; `prometheus-metrics/spec.md` drift check: file not yet present in openconnector repo (spec tracked externally); no rewrite needed.

## Phase 7 — hardcoded magic-number cleanup + naming

All paths per `.claude/audit-2026-05-03/04-hardcoded.md` plus stream 1's rename.

- Rename `lib/Service/ObjectService.php` to `lib/Service/SourceMappingService.php`. Update all call sites. Add a deprecated PHP class alias `OCA\OpenConnector\Service\ObjectService` that extends `SourceMappingService` for one minor version.
- `lib/Service/EndpointCacheService.php:41` — `CACHE_TTL = 3600` → admin-config `openconnector.endpoint_cache.ttl_seconds` (default `3600`).
- `lib/Service/SoftwareCatalogueService.php:52` — `SUFFIX = '-sc'` → admin-config `openconnector.software_catalogue.suffix` (default `-sc`).
- Drop eight `PROP_*` GUID constants in `lib/Service/RuleService.php:125-131` (already covered functionally in Phase 1; confirm deletion).
- Drop triplicated retention constants in `JobService`, `CallService`, `SynchronizationService` (already covered functionally in Phase 4; confirm deletion).
- [x] Phase complete — rename done (SourceMappingService + deprecated alias); CACHE_TTL and SUFFIX moved to admin-config; retention constants removed. PROP_* constants kept pending Phase 1 (RegisterResolverService gated).

## Phase 8 — manifest adoption

Cite `hydra/openspec/changes/adopt-app-manifest/`.

- Create `openspec/manifest.yaml` with: `tier: 2`, `dependencies: ["openregister"]`, `consumes: [register-resolver-service, pluggable-integration-registry, i18n-source-of-truth, i18n-api-language-negotiation, multi-tenancy-context]`.
- Pin minimum OR version in the manifest. openconnector currently has no version floor constant (unlike docudesk); this is the first time the floor is declared machine-readably.
- Validate the manifest with the Hydra manifest schema once it ships.
- [x] Phase complete — `openspec/manifest.yaml` created with tier, dependencies, consumes list, keeps-app-local documentation. Hydra manifest schema validation pending schema ship.

## Phase 9 — multi-tenancy + i18n adoption

Gated on nc-vue `multi-tenancy-context` and OR `i18n-source-of-truth` /
`i18n-api-language-negotiation` shipping.

- Adopt `multi-tenancy-context` in the openconnector frontend: read `currentTenant` from the nc-vue composable in every store. The 20+ domain-specific stores are KEPT but each must read tenant scope from the shared composable.
- Adopt `i18n-source-of-truth` for translatable fields on source, mapping, endpoint, rule, contract, and webhook schemas (label, description, error message templates).
- Adopt `i18n-api-language-negotiation` for the openconnector API: respect the `Accept-Language` header on read responses.
- Document in the spec's scenario list that the 20+ Pinia modules are intentionally domain-specific and not subject to the `createObjectStore` exemplar pattern.
- [ ] Phase complete — **GATED**: pending nc-vue `multi-tenancy-context` and OR `i18n-*` specs shipping.

## Phase 10 — spec note: domain-specific stores stay

Distinct from the apply work; recorded as a Phase so it's not lost.

- Add an explicit "stores stay app-local" requirement (and its scenario) to the capability spec, so future audits don't re-flag the 20+ Pinia modules as duplication.
- Add an explicit "mapping/rule engine stays app-local" requirement and scenario.
- [x] Phase complete — Requirements added to `openspec/specs/openconnector-or-adoption/spec.md` (see "Requirement: Domain-specific Pinia stores stay app-local" and "Requirement: Mapping/rule engine stays app-local").
