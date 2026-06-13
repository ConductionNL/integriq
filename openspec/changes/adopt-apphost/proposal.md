---
kind: code
---

# Proposal: OpenConnector Adopts OpenRegister AppHost (Observability + Boilerplate)

## Problem

OpenConnector hand-writes the standard Conduction app plumbing that the OpenRegister AppHost now provides generically (`apphost-observability-engine` + `apphost-boilerplate-controllers`):

- `lib/Controller/HealthController.php` (116 lines) — database `SELECT 1` + a post-cutover OR-objects join probe for sources.
- `lib/Controller/MetricsController.php` (421 lines) — 11 Prometheus metrics, all `COUNT(*)`/`GROUP BY` over legacy `openconnector_*` tables, plus hand-rolled `info`/`up` gauges and exposition-format string assembly.
- `lib/Controller/PreferencesController.php` (157 lines) — the standard per-user `pref_`-namespaced key/value controller, byte-pattern-identical to the fleet skeleton.
- `lib/Controller/UiController.php` (483 lines) — 21 SPA-shell methods that all return the same `makeSpaResponse()`; this is openconnector's SPA-serving controller (the app has no `DashboardController` — UiController fills that role, with one app-specific behaviour: a permissive `connect-src *` CSP).
- `lib/Settings/OpenConnectorAdmin.php` + `lib/Sections/OpenConnectorAdmin.php` — the standard IDelegatedSettings admin panel + section pair.
- `lib/Repair/InitializeRegister.php` + `lib/Repair/InitializeActions.php` — the standard repair steps.

Beyond drift, the audit of the actual code (2026-06-12) found **four real pre-existing defects** this adoption fixes:

1. **Health endpoint is admin-only** — `HealthController::index()` carries only `@NoCSRFRequired`; by NC SecurityMiddleware default that makes `/api/health` require an admin session. ADR-006 requires health to be public. K8s/uptime probes against this endpoint get 401/403 today.
2. **Health never returns 503** — on a failed database check the controller sets `status: "error"` but returns a default `JSONResponse` (HTTP 200), violating the ADR-006 status-code contract.
3. **The repair steps are wired nowhere** — `appinfo/info.xml` has no `<repair-steps>` block and nothing else references `Repair\InitializeRegister`/`Repair\InitializeActions` (only a unit test does). Register import and action seeding never run on install/upgrade. This is the same class of bug found fleet-wide on docudesk/procest/shillinq (jobs never ran).
4. **Metrics already emit fallback zeros on drained instances** — all 9 counted tables are in migration `Version2Date20260520000099`'s `LEGACY_TABLES` drop-when-empty list (the chain-C OR cutover). Where the drop has run, every `collect*Metrics()` lands in its `catch` block and emits a hardcoded `0` sample. The "metrics" are partially dead code on current installs.

Additionally `lib/Service/SettingsService.php` (534 lines) still carries dead `getStats()`/`getSettings()`/`updateSettings()` methods whose routes were deleted in chain-C — zero callers remain.

## Proposed Change

Adopt both AppHost halves: declare observability in `src/manifest.json` (already present, `requires: openregister`), route the standard endpoints to the AppHost generic controllers via Bootstrap aliases, delete the boilerplate, and keep only the genuinely app-specific residue.

### Observability descriptors

**Health** (`statusCodePolicy: "adr006"`, the default):

| Check today | Descriptor | Notes |
|---|---|---|
| database `SELECT 1` (status→`error`, but HTTP 200 — bug) | `{ "id": "database", "type": "database" }` (critical) | Engine now returns 503 on failure per ADR-006 — intentional fix |
| `sources_table` — COUNT over `oc_openregister_objects` joined to register `openconnector` / schema `source` (degrades only) | `{ "id": "openregister", "type": "orAvailable", "severity": "degraded" }` | **Explicit simplification**: the bespoke sources-count join probe is subsumed by the generic OR-availability check. The join only ever proved "OR's tables are reachable" — `orAvailable` proves the same thing through the supported surface. Check id changes `sources_table` → `openregister`; degraded semantics preserved. |

Further intentional health deltas: endpoint becomes `#[PublicPage]` (ADR-006 fix #1 above); response gains the standard `app`/`version` fields; failed-check values become `failed: <generic message>` instead of bare `error`.

**Metrics** — all 11 current metrics map onto the engine; `openconnector_info` and `openconnector_up` become engine-implicit (today `up` is hardcoded `1` — identical to the implicit behaviour). The remaining 9 all become `tableCount` descriptors:

| Metric (Prometheus name) | Type | Source table | Descriptor shape |
|---|---|---|---|
| `openconnector_info` | gauge | — | implicit (engine) |
| `openconnector_up` | gauge | — | implicit (engine) |
| `openconnector_sources_total{type}` | gauge | `openconnector_sources` | `tableCount`, `groupBy: ["type"]`, `labelDefaults: {"type": "rest"}` (null/empty → `rest`, today's behaviour) |
| `openconnector_calls_total{status}` | counter | `openconnector_call_logs` | `tableCount`, `groupBy: ["status_code"]`, label mapped to `status`, `labelDefaults: {"status": "unknown"}` |
| `openconnector_synchronizations_total` | gauge | `openconnector_synchronizations` | `tableCount` |
| `openconnector_synchronization_runs_total{status}` | counter | `openconnector_synchronization_logs` | `tableCount`, `groupBy: ["result"]`, label mapped to `status`, `labelDefaults: {"status": "unknown"}` |
| `openconnector_endpoints_total` | gauge | `openconnector_endpoints` | `tableCount` |
| `openconnector_jobs_total` | gauge | `openconnector_jobs` | `tableCount` |
| `openconnector_job_runs_total{status}` | counter | `openconnector_job_logs` | `tableCount`, `groupBy: ["status"]`, `labelDefaults: {"status": "unknown"}` |
| `openconnector_mappings_total` | gauge | `openconnector_mappings` | `tableCount` |
| `openconnector_rules_total` | gauge | `openconnector_rules` | `tableCount` |

**These tables are pre-cutover legacy** (defect #4): every one of them sits on the chain-C drop list. That is precisely why declarative adoption is worth doing *now* — when openconnector finishes its OR cutover, each descriptor flips from `tableCount` to `objectCount` with a **one-line manifest edit** instead of a PHP rewrite of `MetricsController`. The descriptors encode the metric *contract* (name, type, labels); the storage backend becomes a configuration detail. Until the flip, the engine's missing-table handling must mirror today's catch-fallback (zero samples, never a 500) — asserted by the Newman contract collection.

Label-value normalisation (today's code lowercases `type`/`result`/`status` values) is engine-owned; any residual casing delta is documented in the parity diff.

### Boilerplate adoption — deletions enumerated

**Deleted outright** (replaced by Bootstrap aliases to AppHost generics):

- `lib/Controller/HealthController.php` → `GenericHealthController` (route `health#index`, URL `/api/health` unchanged)
- `lib/Controller/MetricsController.php` → `GenericMetricsController` (route `metrics#index`, URL `/api/metrics` unchanged; stays admin-only — already correct today)
- `lib/Controller/PreferencesController.php` → `GenericPreferencesController` (`pref_` key namespace and 64-char `[a-z0-9-]` sanitisation preserved per parity rule 3)
- `lib/Controller/UiController.php` → thin app-namespace subclass of `GenericDashboardController` overriding the protected CSP hook to keep `connect-src *` (app-specific: the connector UI calls configured external APIs from the browser). The 21 named `ui#*` SPA routes are redundant with the existing `(?!api(/|$)).*` catch-all and collapse into `Routes::standard()`'s `dashboard#page` + catch-all — **URLs unchanged**, and the stale `openconnector.dashboard.page` route name referenced by `appinfo/info.xml` `<navigation>` (currently pointing at a route that no longer exists post-chain-C) starts resolving again.

**Reduced to one-line subclass stubs** (NC requires concrete classes in the app namespace for info.xml registration / attribute references):

- `lib/Settings/OpenConnectorAdmin.php` → `extends GenericAdminSettings` (referenced by `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]` on `SettingsController::rebase()` and by info.xml `<settings>`)
- `lib/Sections/OpenConnectorAdmin.php` → `extends GenericSettingsSection`
- `lib/Repair/InitializeRegister.php` → `extends GenericInitializeSettings`
- `lib/Repair/InitializeActions.php` → `extends GenericInitializeActions`
- **Fix defect #3 in the same change**: add the `<repair-steps>` block to `appinfo/info.xml` so the stubs actually run (repair step, NOT migration — established install-order constraint).

**Stays app-specific (not boilerplate — verified against the actual code):**

- `lib/Controller/SettingsController.php` — already shrunk post-chain-C to the single app-specific `rebase()` action (log-retention recompute). Kept as-is.
- `lib/Service/SettingsService.php` — this is *not* the petstore SettingsService; it is the rebase implementation (Postgres-portable interval SQL, GH #822). Kept, **minus** the dead `getStats()`/`getSettings()`/`updateSettings()` methods (zero callers — deleted per the fix-pre-existing rule). The manual container factory for it in `Application.php` is also dropped (plain autowiring suffices once the dead methods go).
- `lib/AppInfo/Application.php` — **modified, not deleted**: adds `AppHost\Bootstrap::register($context, self::APP_ID, …)`; keeps all genuinely app-specific registrations (PDOK adapter feature-flag wiring, Berichtenbox client, OR event listeners, the `openconnector-integration` init-script listener, IntegrationRegistry boot wiring).
- Domain controllers (`SourcesController`, `EndpointsController`, `JobsController`, `MappingsController`, `RulesController`, `SynchronizationsController`, `SynchronizationContractsController`, `ConsumersController`, `EventsController`, `LogsController`, `ActionMatrixController`, `UserController`, `DSOController`, `PdokController`) — untouched.
- openconnector has **no** `DashboardController` and **no** `Listener/DeepLinkRegistrationListener` (deep links are served by the SPA routes) — nothing to delete in those slots.

Net deletion: ~1,180 lines of controller boilerplate outright (Health 116 + Metrics 421 + Preferences 157 + Ui 483) plus the four classes reduced to stubs and the dead SettingsService methods.

## Impact

- **Deleted**: 4 controllers (~1,180 lines); 4 classes → one-line stubs; ~250 lines of dead SettingsService methods.
- **Modified**: `src/manifest.json` (observability block), `appinfo/routes.php` (delegate standard routes; keep app-specific `$extra`), `lib/AppInfo/Application.php` (Bootstrap call), `appinfo/info.xml` (`<repair-steps>` fix).
- **Fixed** (pre-existing): public health posture (ADR-006), 503-on-critical (ADR-006), unwired repair steps, dead settings methods, stale navigation route name.
- **Risk**: behavioural drift vs the deployed scrape/probe contract — mitigated by the baseline-capture parity diff (task 0/3), the OR AppHost Newman contract collection, and the app's own Newman collection. Intentional deltas are enumerated above; everything else must be byte-identical.

## Dependencies

Chained on the OpenRegister changes `apphost-observability-engine` and `apphost-boilerplate-controllers` (engine + generics + `Bootstrap`/`Routes` must merge first). Label mapping for `groupBy` column → Prometheus label (`status_code`→`status`, `result`→`status`) relies on the engine's table-source label-mapping capability (same mechanism the OR self-adoption uses for `webhook_deliveries_total{status}` from a `success` column).
