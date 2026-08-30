# Retrofit — synchronization-engine

Describes the observed behavior of 97 methods under the `synchronization-engine`
cluster as 5 new REQs. The code already exists — this change retroactively
specifies it. The cluster is the single largest in the openconnector coverage
report and is centred on `lib/Service/SynchronizationService.php` (74 methods,
5322 lines) plus the two REST controllers and the ADR-019 integration provider
that drive it.

This is a `--cluster` retrofit (new capability spec) because openconnector
currently ships only one spec (`prometheus-metrics`); the sync engine is
genuinely new spec territory. The REQ language follows the canonical
Source → Synchronization → SynchronizationContract triad of ADR-005.

## Affected code units

- `lib/Service/SynchronizationService.php` — 74 methods (orchestration, fetch,
  pagination, mapping, hashing, target write, file handling, rule pipeline,
  statistics)
- `lib/Controller/SynchronizationsController.php` — 8 methods (REST surface:
  page/contracts/logs/test/run/statistics/logsExport/deleteLog)
- `lib/Controller/SynchronizationContractsController.php` — 6 methods
  (activate/deactivate/execute/statistics/performance/export)
- `lib/Service/Integration/SynchronizationContractProvider.php` — 9 methods
  (ADR-019 IIntegrationProvider: list/getId/getLabel/getIcon/getGroup/
  getRequiredApp/getStorageStrategy/health/isEnabled)

Note: the coverage report lists the integration provider under the stale path
`lib/Service/SynchronizationContractProvider.php`; the actual file lives under
`lib/Service/Integration/`. Annotations are applied to the real file.

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, and
  failure modes from reading the code (not design intent).
- Fold the 97 methods into 5 broad behavioral REQs:
  - REQ-001 — sync orchestration & direction routing
  - REQ-002 — source object fetching & pagination
  - REQ-003 — mapping, transformation & object identity
  - REQ-004 — target write, deduplication & file handling
  - REQ-005 — sync rule pipeline & management/integration surface
- Draft REQs that match observed behavior, not aspirational behavior.
- The Notes sections surface observed-but-suspicious behavior — most notably the
  missing per-object authorization guards on the controller surface (see
  REQ-005 Notes — IDOR) and the silent error-swallowing in the event-driven and
  file-fetch paths.

Source: openspec/coverage-report.md generated 2026-05-24. See
[retrofit playbook](../../../.github/docs/claude/retrofit.md).
