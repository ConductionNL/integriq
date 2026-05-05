# Tasks — openconnector: adopt OR abstractions

Spec-only change. Code paths listed are implementation hints for the apply phase.

## Phase 1 — register-resolver consumption

openconnector reads register/schema config in multiple places (RuleService, source/mapping
loaders). Route through `RegisterResolverService` once OR ships the spec.

- [ ] 1.1 Inventory all `IAppConfig::getValueString(APP_ID, 'register'|'schema', '')` reads
      and route through `RegisterResolverService`.
- [ ] 1.2 Replace eight schema-property GUID constants in
      `lib/Service/RuleService.php:125-131` (`PROP_*`) with slug-based lookups via
      `RegisterResolverService::resolveProperty($schemaSlug, $propertySlug)`. Brittle GUIDs
      removed.

## Phase 2 — lifecycle annotation migration

Audit flagged inline status writes in two controllers/services and a state-enum smell in
two more controllers. Migrate per ADR-022, with a per-value review of filter-vs-state.

- [ ] 2.1 `lib/Controller/DSOController.php:115` — `'status' => 'ontvangen'` migrate to
      lifecycle transition. Define lifecycle states on the dso-message schema.
- [ ] 2.2 `lib/Service/EventService.php:170,256` — `'status' => 'pending'` migrate to
      lifecycle transition on the event schema.
- [ ] 2.3 `lib/Controller/SynchronizationContractsController.php:366-368` — review
      `'status' => 'active'|'inactive'|'error'` filter values:
      - `active` / `inactive` are LIFECYCLE → migrate to lifecycle annotation.
      - `error` is LIFECYCLE → already covered by lifecycle's error state.
- [ ] 2.4 `lib/Controller/SynchronizationsController.php:510-514` — review
      `'status' => 'success'|'warning'|'info'|'debug'` filter values:
      - These are LOG-LEVEL filters, NOT lifecycle. Keep as filter whitelist; document why
        in the spec's scenario list.

## Phase 3 — notification annotation migration

openconnector fires NC notifications on synchronization-failed, contract-broken, and
job-failed events. Migrate to `x-openregister-notifications` per ADR-025.

- [ ] 3.1 Identify all direct `notificationManager->notify()` / `setSubject()` call sites
      in `lib/Service/`. Replace with declarative `x-openregister-notifications` triggers.

## Phase 4 — archival annotation

The audit's biggest archival finding: triplicated retention constants across three
services. Migrate to `x-openregister-archival` per ADR-024.

- [ ] 4.1 `lib/Service/JobService.php:60-61`,
      `lib/Service/CallService.php:66-67`,
      `lib/Service/SynchronizationService.php:83-84` — three identical
      `DEFAULT_SUCCESS_LOG_RETENTION = 3600000` and
      `DEFAULT_ERROR_LOG_RETENTION = 2592000000` constants. Declare
      `x-openregister-archival.retention` ONCE on the relevant log schema (success
      retention `PT1H`, error retention `P30D` — match current values). Remove all three
      sets of source constants.
- [ ] 4.2 Verify retention values match the legal retention table (sync error logs may need
      longer retention for audit purposes — confirm with the DPO during apply).

## Phase 5 — calculation annotation

openconnector's existing `prometheus-metrics` spec already declares computed metrics. No
new calculation annotation work required for this change.

- [ ] 5.1 Cross-check `openspec/specs/prometheus-metrics/spec.md` for any inline
      computations that should be `x-openregister-calculations` rather than ad-hoc service
      methods. Flag for follow-up if found.

## Phase 6 — spec rewrites (stream 2)

The audit (stream 2) finds `prometheus-metrics/spec.md` CURRENT, and the
`ibabs-notubiz-connector` + `stuf-adapter` change folders represent legitimate per-protocol
integrations. NO rewrites required for this change.

- [ ] 6.1 Add a `## See Also` block in this change's spec citing
      `openspec/specs/prometheus-metrics/spec.md` (current) and the `ibabs-notubiz-connector`
      and `stuf-adapter` change folders so downstream readers can find the integration
      patterns.
- [ ] 6.2 Verify `prometheus-metrics/spec.md` still reflects current code (drift check, no
      rewrite).

## Phase 7 — hardcoded magic-number cleanup + naming

All paths per `.claude/audit-2026-05-03/04-hardcoded.md` plus stream 1's rename.

- [ ] 7.1 Rename `lib/Service/ObjectService.php` to `lib/Service/SourceMappingService.php`.
      Update all call sites. Add a deprecated PHP class alias `OCA\OpenConnector\Service\ObjectService`
      that extends `SourceMappingService` for one minor version.
- [ ] 7.2 `lib/Service/EndpointCacheService.php:41` — `CACHE_TTL = 3600` → admin-config
      `openconnector.endpoint_cache.ttl_seconds` (default `3600`).
- [ ] 7.3 `lib/Service/SoftwareCatalogueService.php:52` — `SUFFIX = '-sc'` → admin-config
      `openconnector.software_catalogue.suffix` (default `-sc`).
- [ ] 7.4 Drop eight `PROP_*` GUID constants in `lib/Service/RuleService.php:125-131`
      (already covered functionally in 1.2; this task confirms the constants are deleted).
- [ ] 7.5 Drop triplicated retention constants in `JobService`, `CallService`,
      `SynchronizationService` (already covered functionally in 4.1; this task confirms
      deletion).

## Phase 8 — manifest adoption

Cite `hydra/openspec/changes/adopt-app-manifest/`.

- [ ] 8.1 Create `openspec/manifest.yaml` with: `tier: 2`,
      `dependencies: ["openregister"]`,
      `consumes: [register-resolver-service, pluggable-integration-registry,
      i18n-source-of-truth, i18n-api-language-negotiation, multi-tenancy-context]`.
- [ ] 8.2 Pin minimum OR version in the manifest. openconnector currently has no version
      floor constant (unlike docudesk); this is the first time the floor is declared
      machine-readably.
- [ ] 8.3 Validate the manifest with the Hydra manifest schema once it ships.

## Phase 9 — multi-tenancy + i18n adoption

Gated on nc-vue `multi-tenancy-context` and OR `i18n-source-of-truth` /
`i18n-api-language-negotiation` shipping.

- [ ] 9.1 Adopt `multi-tenancy-context` in the openconnector frontend: read
      `currentTenant` from the nc-vue composable in every store. The 20+ domain-specific
      stores are KEPT but each must read tenant scope from the shared composable.
- [ ] 9.2 Adopt `i18n-source-of-truth` for translatable fields on source, mapping, endpoint,
      rule, contract, and webhook schemas (label, description, error message templates).
- [ ] 9.3 Adopt `i18n-api-language-negotiation` for the openconnector API: respect the
      `Accept-Language` header on read responses.
- [ ] 9.4 Document in the spec's scenario list that the 20+ Pinia modules are intentionally
      domain-specific and not subject to the `createObjectStore` exemplar pattern.

## Phase 10 — spec note: domain-specific stores stay

Distinct from the apply work; recorded as a Phase so it's not lost.

- [ ] 10.1 Add an explicit "stores stay app-local" requirement (and its scenario) to the
      capability spec, so future audits don't re-flag the 20+ Pinia modules as duplication.
- [ ] 10.2 Add an explicit "mapping/rule engine stays app-local" requirement and scenario.
