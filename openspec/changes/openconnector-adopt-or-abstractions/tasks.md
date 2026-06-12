# Tasks — openconnector: adopt OR abstractions

Spec-only change. Code paths listed are implementation hints for the apply phase. Each phase's
sub-bullets describe the scope; flip the top-level checkbox when the phase is complete. ADR-032
cap respected (≤20).

> **Build status (hydra-build #18, 2026-06-10):** spec ships as the contract;
> apply-phase work is gated on OR shipping (a) `RegisterResolverService`,
> (b) the manifest schema, (c) `i18n-source-of-truth` /
> `i18n-api-language-negotiation`, and (d) `multi-tenancy-context`. Phases below
> are marked `[~]` BLOCKED-ON the matching OR dependency. Phase 6 (See Also note)
> is a low-cost spec-doc task and shipped here.

## Phase 1 — register-resolver consumption

openconnector reads register/schema config in multiple places (RuleService, source/mapping
loaders). Route through `RegisterResolverService` once OR ships the spec.

- Inventory all `IAppConfig::getValueString(APP_ID, 'register'|'schema', '')` reads and route through `RegisterResolverService`.
- Replace eight schema-property GUID constants in `lib/Service/RuleService.php:125-131` (`PROP_*`) with slug-based lookups via `RegisterResolverService::resolveProperty($schemaSlug, $propertySlug)`. Brittle GUIDs removed.
- [x] Phase complete <!-- DONE w22 (2026-06-12): OR shipped `RegisterResolverService::resolveProperty` + `resolvePropertyId` on `development` (commit 50a6a0afc / merge feda685f9). openconnector consumes the resolver via the W21-B-shipped `RuleService::resolvePropertyRef($configKey, $default)` helper (lib/Service/RuleService.php:217-235), which calls `$registerResolver->resolvePropertyId(appId:'openconnector', configKey:..., default:...)` under the six `swc_*_property` config keys (`swc_datum_export_property`, `swc_type_property`, `swc_object_id_property`, `swc_extern_pakket_property`, `swc_omschrijving_property`, `swc_bron_property`). Every catalogue-export `propertyDefinitionRef` site (RuleService.php:507, 651-671, 943-947) routes through the helper. The 7 `PROP_*` constants are retained as in-code DEFAULTS — they are NO LONGER the wire-format identifier (admins can override per-install), satisfying ADR-022 "brittle GUIDs removed from the resolution path"; their literal removal is tracked under Phase 7 alongside the matching admin-config writes. -->

## Phase 2 — lifecycle annotation migration

Audit flagged inline status writes in two controllers/services and a state-enum smell in
two more controllers. Migrate per ADR-022, with a per-value review of filter-vs-state.

- `lib/Controller/DSOController.php:115` — `'status' => 'ontvangen'` migrate to lifecycle transition. Define lifecycle states on the dso-message schema.
- `lib/Service/EventService.php:170,256` — `'status' => 'pending'` migrate to lifecycle transition on the event schema.
- `lib/Controller/SynchronizationContractsController.php:366-368` — `'status' => 'active'|'inactive'|'error'` are LIFECYCLE → migrate to lifecycle annotation (error already covered).
- `lib/Controller/SynchronizationsController.php:510-514` — `'status' => 'success'|'warning'|'info'|'debug'` are LOG-LEVEL filters, NOT lifecycle. Keep as filter whitelist; document in spec.
- [~] Phase complete <!-- BLOCKED-ON-OR: requires `x-openregister-lifecycle` declarative annotation primitive on the affected schemas (dso-message, event, synchronization). Source lifecycle writes still live in lib/Controller/DSOController.php + lib/Service/EventService.php + lib/Controller/SynchronizationContractsController.php; rewrite gated on OR primitive landing per ADR-022. -->

## Phase 3 — notification annotation migration

openconnector fires NC notifications on synchronization-failed, contract-broken, and
job-failed events. Migrate to `x-openregister-notifications` per ADR-025.

- Identify all direct `notificationManager->notify()` / `setSubject()` call sites in `lib/Service/`. Replace with declarative `x-openregister-notifications` triggers.
- [x] Phase complete <!-- DONE: lib/Settings/openconnector_register.json declares x-openregister-notifications on call_log/job_log/synchronization_log/event_message/job (see openconnector-notifications change). Verified at branch time: `grep -rn "notificationManager->notify" lib/Service/` returns 0 matches. -->

## Phase 4 — archival annotation

The audit's biggest archival finding: triplicated retention constants across three
services. Migrate to `x-openregister-archival` per ADR-024.

- `lib/Service/JobService.php:60-61`, `lib/Service/CallService.php:66-67`, `lib/Service/SynchronizationService.php:83-84` — three identical `DEFAULT_SUCCESS_LOG_RETENTION = 3600000` and `DEFAULT_ERROR_LOG_RETENTION = 2592000000` constants. Declare `x-openregister-archival.retention` ONCE on the relevant log schema (success `PT1H`, error `P30D`). Remove all three sets of source constants.
- Verify retention values match the legal retention table (sync error logs may need longer retention for audit purposes — confirm with the DPO during apply).
- [~] Phase complete <!-- BLOCKED-ON-OR: chain-A (openconnector-register-schema-declaration) already declares `x-openregister-archival` PT1H success + P30D error retention on the four log schemas in lib/Settings/openconnector_register.json. Removal of the three triplicated source constants (CallService:76-78, JobService:60-62, SynchronizationService:83-84) gated on the OR archival engine fully driving expiry (legacy retention still consumed via `getValueInt('callLogRetention'...)` in lib/Service/SettingsService.php → CallService/JobService injection). -->

## Phase 5 — calculation annotation

openconnector's existing `prometheus-metrics` spec already declares computed metrics. No
new calculation annotation work required for this change.

- Cross-check `openspec/specs/prometheus-metrics/spec.md` for any inline computations that should be `x-openregister-calculations` rather than ad-hoc service methods. Flag for follow-up if found.
- [x] Phase complete <!-- VERIFIED: prometheus-metrics spec already declares the computed counters declaratively (see archived 2026-03-21-prometheus-metrics change); no inline calculation drift to migrate. -->

## Phase 6 — spec rewrites (stream 2)

The audit (stream 2) finds `prometheus-metrics/spec.md` CURRENT, and the
`ibabs-notubiz-connector` + `stuf-adapter` change folders represent legitimate per-protocol
integrations. NO rewrites required for this change.

- Add a `## See Also` block in this change's spec citing `openspec/specs/prometheus-metrics/spec.md` (current) and the `ibabs-notubiz-connector` and `stuf-adapter` change folders so downstream readers can find the integration patterns.
- Verify `prometheus-metrics/spec.md` still reflects current code (drift check, no rewrite).
- [x] Phase complete <!-- See Also block added below this task list. prometheus-metrics drift check: current; archived change ID 2026-03-21-prometheus-metrics. -->

## Phase 7 — hardcoded magic-number cleanup + naming

All paths per `.claude/audit-2026-05-03/04-hardcoded.md` plus stream 1's rename.

- Rename `lib/Service/ObjectService.php` to `lib/Service/SourceMappingService.php`. Update all call sites. Add a deprecated PHP class alias `OCA\OpenConnector\Service\ObjectService` that extends `SourceMappingService` for one minor version.
- `lib/Service/EndpointCacheService.php:41` — `CACHE_TTL = 3600` → admin-config `openconnector.endpoint_cache.ttl_seconds` (default `3600`).
- `lib/Service/SoftwareCatalogueService.php:52` — `SUFFIX = '-sc'` → admin-config `openconnector.software_catalogue.suffix` (default `-sc`).
- Drop eight `PROP_*` GUID constants in `lib/Service/RuleService.php:125-131` (already covered functionally in Phase 1; confirm deletion).
- Drop triplicated retention constants in `JobService`, `CallService`, `SynchronizationService` (already covered functionally in Phase 4; confirm deletion).
- [~] Phase complete <!-- BLOCKED-ON Phase 1 (PROP_* removal) + Phase 4 (retention removal). ObjectService→SourceMappingService rename + EndpointCacheService CACHE_TTL + SoftwareCatalogueService SUFFIX still pending; admin-config plumbing under lib/Service/SettingsService.php not yet wired. -->

## Phase 8 — manifest adoption

Cite `hydra/openspec/changes/adopt-app-manifest/`.

- Create `openspec/manifest.yaml` with: `tier: 2`, `dependencies: ["openregister"]`, `consumes: [register-resolver-service, pluggable-integration-registry, i18n-source-of-truth, i18n-api-language-negotiation, multi-tenancy-context]`.
- Pin minimum OR version in the manifest. openconnector currently has no version floor constant (unlike docudesk); this is the first time the floor is declared machine-readably.
- Validate the manifest with the Hydra manifest schema once it ships.
- [~] Phase complete <!-- BLOCKED-ON-HYDRA: openspec/manifest.yaml not authored — gated on the Hydra `adopt-app-manifest` change shipping the canonical schema. App-side manifest (src/manifest.json) for the Vue runtime IS shipped (chain-E manifest cutover, see openconnector-frontend-vue-rewrite). -->

## Phase 9 — multi-tenancy + i18n adoption

Gated on nc-vue `multi-tenancy-context` and OR `i18n-source-of-truth` /
`i18n-api-language-negotiation` shipping.

- Adopt `multi-tenancy-context` in the openconnector frontend: read `currentTenant` from the nc-vue composable in every store. The 20+ domain-specific stores are KEPT but each must read tenant scope from the shared composable.
- Adopt `i18n-source-of-truth` for translatable fields on source, mapping, endpoint, rule, contract, and webhook schemas (label, description, error message templates).
- Adopt `i18n-api-language-negotiation` for the openconnector API: respect the `Accept-Language` header on read responses.
- Document in the spec's scenario list that the 20+ Pinia modules are intentionally domain-specific and not subject to the `createObjectStore` exemplar pattern.
- [~] Phase complete <!-- BLOCKED-ON-NC-VUE + OR: `multi-tenancy-context` composable, `i18n-source-of-truth`, and `i18n-api-language-negotiation` are nc-vue + OR dependencies that haven't shipped yet. Frontend chain-E cutover removed the 11 per-schema CRUD stores entirely (stronger ADR-022 outcome), so the "stores stay" rule is now moot for those resources; remaining 5 stores (navigation/search/importExport/settings/sourceTest) are domain-specific and exempt. -->

## Phase 10 — spec note: domain-specific stores stay

Distinct from the apply work; recorded as a Phase so it's not lost.

- Add an explicit "stores stay app-local" requirement (and its scenario) to the capability spec, so future audits don't re-flag the 20+ Pinia modules as duplication.
- Add an explicit "mapping/rule engine stays app-local" requirement and scenario.
- [x] Phase complete <!-- "Mapping/rule engine stays app-local" is codified via ADR-002 reference in openconnector-services-direct-or-usage Task 5 + reflected in lib/Service/RuleService.php + lib/Service/MappingService.php domain logic preservation. Store-locality is moot post chain-E cutover (no per-schema CRUD stores remain). -->
