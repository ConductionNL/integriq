# ADR-001: Domain-specific Pinia stores stay app-local

## Status
Accepted (capturing existing decision)

## Date
2026-05-20

## Context

OpenConnector ships 16+ Pinia store modules under `src/store/modules/` — one per
resource family (`source.ts`, `endpoint.ts`, `mapping.ts`, `synchronization.ts`,
`contract.ts`, `rule.ts`, `job.ts`, `log.ts`, `consumer.ts`, `event.ts`,
`webhooks.ts`, `importExport.js`, `search.ts`, `navigation.js`, `settings.js`).
Each store wraps a Nextcloud REST endpoint (e.g. `apiEndpoint =
'/index.php/apps/openconnector/api/sources'` in `source.ts:8`) plus
resource-specific UX state (`viewMode`, `sourceLog`, `sourceLogs`,
`sourceConfigurationKey`).

The fleet-wide pattern (hydra ADR-022, project memory "Store pattern guidance")
is for object-oriented apps to consume OpenRegister's `createObjectStore`
exemplar rather than rolling per-resource stores. The 2026-05-03 OR-abstraction
audit (stream 1) explicitly classified openconnector's stores as intentionally
domain-specific and recommended KEEPING them, because the resources are not
generic register objects — they are integration-domain concepts (sources,
endpoints, mappings) with bespoke behaviour (test runs, logs, contracts,
synchronization triggers).

## Decision

Keep the 16+ resource-specific Pinia stores under `src/store/modules/` as-is.
Do NOT migrate them to `createObjectStore`. New connector-domain resources
follow the same per-resource store pattern.

The future direction once chain `openconnector-services-direct-or-usage` lands
is the BACKEND moves to OR's `ObjectService`. The frontend stores continue to
front the openconnector REST API, which is in turn backed by OR. The store
layer stays intentionally connector-shaped.

## Consequences

- A new developer expecting `createObjectStore` will not find it; they should
  read this ADR before refactoring.
- Each resource page can encode integration-specific UX (test mode, log
  refresh, retry, run-now) without bending a generic store.
- Cross-store interaction is explicit (e.g. `source.ts` imports
  `importExportStore` from `../store.js`); the audit accepted this tradeoff
  rather than forcing the createObjectStore generic shape.
- Future audits should cite this ADR to skip re-flagging the pattern.
- Cross-reference: hydra ADR-022 (apps consume OR abstractions) — the OR
  abstraction openconnector consumes is the BACKEND service, not the store
  layer; openconnector specialises ADR-022 for the connector-domain context.
- Cross-reference: in-flight change
  `openconnector/openspec/changes/openconnector-adopt-or-abstractions/`
  proposal.md §"Findings explicitly KEPT" — this ADR is the spec home for that
  KEEP finding.

## Evidence

- `src/store/store.js:5-33` — central registration of 14 resource stores.
- `src/store/modules/source.ts:8-10` — per-resource API endpoint constant +
  `defineStore('source', () => { ... })` Composition API form.
- `openspec/changes/openconnector-adopt-or-abstractions/proposal.md:32-37` —
  audit's explicit KEEP finding for the 20+ Pinia modules.
- `openspec/changes/openconnector-adopt-or-abstractions/design.md:86-96` —
  Decision 5 codifying the KEEP rationale.
