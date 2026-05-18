# Design — openconnector: adopt OR abstractions

## Context

openconnector is the integration / synchronization fabric of the Conduction stack: sources,
mappings, contracts, webhooks, rules, and protocol-specific adapters (DSO/Omgevingsloket,
StUF, iBabs-Notubiz). The 2026-05-03 OR-abstraction audit places it at Tier 2: a real
domain-specific app with intentional store proliferation and a custom mapping/rule engine,
but with three concrete duplications of OR machinery that should migrate.

This change pairs with the docudesk and pipelinq adoption changes and depends on the same
OR-side and Hydra-side prerequisites.

## Goals

- Eliminate the cognitive collision between openconnector's `ObjectService` and OR's
  generic `ObjectService` by renaming.
- Stop hardcoding schema-property GUIDs that break on schema rebuilds.
- Stop triplicating retention constants across three services.
- Migrate inline `'status'` writes onto lifecycle annotations.
- Add a manifest declaring tier and dependencies.
- Explicitly document the parts that stay app-local (stores, mapping engine).

## Non-Goals

- Replacing the mapping/rule engine. By-design transforms between schemas, KEPT.
- Replacing the 20+ Pinia stores with `createObjectStore`. Intentionally
  domain-specific, KEPT.
- Replacing protocol-specific adapters. DSO, StUF, iBabs-Notubiz integrations stay
  app-local and use the `pluggable-integration-registry` from OR.
- Touching `prometheus-metrics/spec.md`. Audit flagged it CURRENT.

## Decisions

### Decision 1 — Rename to `SourceMappingService`, keep deprecated alias

`lib/Service/ObjectService.php` collides with OR's generic `ObjectService` cognitively.
Internal usages confirmed by audit grep; no external consumers depend on the class name.

**Decision**: rename to `SourceMappingService.php`. Keep
`OCA\OpenConnector\Service\ObjectService extends SourceMappingService` as a deprecated alias
for one minor version per ADR-022 deprecation policy.

**Why**: low-risk, high-clarity. Stream 1's S/M (small effort, medium impact) calculus.

### Decision 2 — Slug-based property lookup, not GUID constants

`RuleService.php:125-131` declares eight `PROP_*` constants holding raw schema-property GUIDs
(`id-7d91e5c8-…`). Schema rebuilds — common during dev — invalidate these GUIDs silently.

**Decision**: replace with `RegisterResolverService::resolveProperty($schemaSlug,
$propertySlug)` calls. Resolved at boot, cached for the request.

**Why**: stream 4 finding. Slugs are stable across rebuilds; GUIDs are not. Brittleness
removal is the audit's primary motivation here.

### Decision 3 — Single archival annotation, three services drop their constants

`DEFAULT_SUCCESS_LOG_RETENTION = 3600000` (1 hour in milliseconds) and
`DEFAULT_ERROR_LOG_RETENTION = 2592000000` (30 days in milliseconds) appear identical in
`JobService:60-61`, `CallService:66-67`, `SynchronizationService:83-84`.

**Decision**: declare retention ONCE on the log schema as
`x-openregister-archival.retention: PT1H` (success) and `P30D` (error). The schema may
need a discriminator (success/error) so the annotation can route by row.

**Why**: ADR-024 mandates per-schema declaration. Consolidating three identical constants
is the cheapest stream 4 win in the codebase.

### Decision 4 — Per-value review of state-enum smell

The audit flagged controllers exposing `'status' => 'active'|'inactive'|'error'|'success'|
'warning'|'info'|'debug'` as filter values. Some of these are lifecycle (active, inactive,
error). Some are not (success/warning/info/debug — log-level filters).

**Decision**:
- `active`, `inactive`, `error` on `SynchronizationContractsController:366-368` → LIFECYCLE.
  Migrate to `x-openregister-lifecycle` on the contract schema.
- `success`, `warning`, `info`, `debug` on `SynchronizationsController:510-514` → LOG-LEVEL
  filter, NOT lifecycle. Document as filter whitelist.

**Why**: the spec must distinguish state from filter. Audit explicitly flags both for
review; the disposition lives in the spec scenarios so a future audit can verify.

### Decision 5 — Stores and mapping engine STAY app-local

The audit's stream 1 explicitly classifies openconnector's 20+ Pinia stores and the
mapping/rule engine as intentionally domain-specific. Without this decision in the spec,
a future audit would re-flag them.

**Decision**: the new capability spec ADDS a Requirement saying these stay app-local. Future
audits cite this Requirement to skip the re-investigation.

**Why**: codifies the audit's "by design" judgement so the question doesn't re-open every
six months.

### Decision 6 — Magic-number defaults preserve current behavior

Same rule as docudesk: each constant moving to admin-config keeps its current value as
the default.

**Why**: zero behavioral change at install. Stream 4's standard discipline.

### Decision 7 — `prometheus-metrics` not rewritten

Audit (stream 2) found `prometheus-metrics/spec.md` CURRENT. This change does NOT touch
it. The new capability spec cites it under "See Also" so readers find the existing
metrics contract.

**Why**: scope discipline. Spec-only change, only fix what audit flags as broken.

## Risks / Trade-offs

| Risk | Mitigation |
| --- | --- |
| Renaming `ObjectService` may break a third-party app importing the class. | One-minor-version deprecated class alias. Apply phase emits a `@trigger_error('… renamed to SourceMappingService …', E_USER_DEPRECATED)`. |
| `RuleService::PROP_*` constants may be referenced from rule definitions persisted in DB (not in code). | Apply phase ships a migration that rewrites stored rule references from GUID to slug-based form. |
| Triple constants may have legitimate divergent values in some tenants (admin set them differently). | Audit checked: all three are PHP `const`, not `IAppConfig`. No tenant override exists today. Migration is safe. |
| Filter-vs-lifecycle review may misclassify a value. | Per-value disposition is in the spec scenario list; future audit can re-check by reading the spec. |
| `prometheus-metrics` spec drift between this change and the next OR upgrade. | Phase 6 explicitly tasks a drift check (no rewrite, just verify). |

## Migration path

1. OR ships `register-resolver-service`, `pluggable-integration-registry`,
   `i18n-source-of-truth`, `i18n-api-language-negotiation` (gates Phase 1, 9).
2. OR ships ADR-022 lifecycle + ADR-024 archival + ADR-025 notification annotation runtime
   (gates Phases 2, 3, 4).
3. nc-vue ships `multi-tenancy-context` (gates Phase 9).
4. Hydra ships `adopt-app-manifest` (gates Phase 8).
5. openconnector apply phase runs in order: 7.1 (rename) → 1 → 4 → 2 → 3 → 5 → 6 → 7.2-7.5
   → 8 → 9 → 10. Rename happens FIRST so subsequent phases edit the renamed file.

## Open Questions

- DSO message lifecycle states beyond `ontvangen`: full state list (e.g. `verwerkt`,
  `gefaald`, `geretourneerd`) needs confirmation from DSO/Omgevingsloket spec authors.
  Apply phase confirms.
- Log-level filter values (`success/warning/info/debug`): should they be a JSON-schema
  enum on the log schema, or remain as ad-hoc filter whitelist values? Spec scenario
  proposes enum; apply phase confirms.
- Retention discriminator on the log schema: does the schema have a `level` field, or do
  success and error logs live in different schemas? Audit didn't drill that deep; apply
  phase confirms before declaring `x-openregister-archival`.
- `SynchronizationService::DEFAULT_ERROR_LOG_RETENTION` is `259200000` (3 days), not
  `2592000000` (30 days) as in `JobService`/`CallService`. Preserved as-is per Decision 6
  (magic-number defaults preserve current behavior). DPO confirmation pending.

## See Also

- `openspec/specs/openconnector-or-adoption/spec.md` — capability spec for this change
  (added Requirements for all migrated and kept-app-local behaviours).
- `openspec/specs/prometheus-metrics/spec.md` — current; not rewritten by this change
  (audit stream 2 found it CURRENT).
- `openspec/changes/ibabs-notubiz-connector/` — protocol-specific integration; stays
  app-local via pluggable-integration-registry.
- `openspec/changes/dso-omgevingsloket/` — protocol-specific integration; stays app-local.
- `openspec/changes/stuf-adapter/` — protocol-specific integration; stays app-local.
