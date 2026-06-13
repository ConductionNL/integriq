# Tasks: OpenConnector Adopts OpenRegister AppHost

## 0. Baseline

- [ ] 0.1 Capture baseline on a seeded dev instance: `curl /apps/openconnector/api/health` JSON (as admin — the endpoint is wrongly admin-only today) + `/apps/openconnector/api/metrics` Prometheus text; store both as fixtures for the parity diff. Also record HTTP status codes (health currently 200-always) so the intentional ADR-006 deltas are diffed knowingly, not discovered.
- [ ] 0.2 Note instance state for the metrics fixture: whether the legacy `openconnector_*` tables still exist (pre-drop) or `Version2Date20260520000099` already dropped them (post-drop, all counted metrics emit fallback `0`). Capture one fixture per state if both environments are available.

## 1. Manifest observability block

- [ ] 1.1 Add the `observability` block to `src/manifest.json`:

```jsonc
"observability": {
  "health": {
    "statusCodePolicy": "adr006",
    "checks": [
      { "id": "database",     "type": "database" },
      { "id": "openregister", "type": "orAvailable", "severity": "degraded" }
    ]
  },
  "metrics": [
    { "name": "sources_total", "type": "gauge", "help": "Total sources by type",
      "source": { "kind": "tableCount", "table": "openconnector_sources",
                  "groupBy": ["type"], "labelDefaults": { "type": "rest" } } },
    { "name": "calls_total", "type": "counter", "help": "Total API calls by status",
      "source": { "kind": "tableCount", "table": "openconnector_call_logs",
                  "groupBy": ["status_code"], "labelMap": { "status_code": "status" },
                  "labelDefaults": { "status": "unknown" } } },
    { "name": "synchronizations_total", "type": "gauge", "help": "Total synchronization runs",
      "source": { "kind": "tableCount", "table": "openconnector_synchronizations" } },
    { "name": "synchronization_runs_total", "type": "counter", "help": "Total synchronization log entries by result",
      "source": { "kind": "tableCount", "table": "openconnector_synchronization_logs",
                  "groupBy": ["result"], "labelMap": { "result": "status" },
                  "labelDefaults": { "status": "unknown" } } },
    { "name": "endpoints_total", "type": "gauge", "help": "Total registered endpoints",
      "source": { "kind": "tableCount", "table": "openconnector_endpoints" } },
    { "name": "jobs_total", "type": "gauge", "help": "Total configured jobs",
      "source": { "kind": "tableCount", "table": "openconnector_jobs" } },
    { "name": "job_runs_total", "type": "counter", "help": "Total job log entries by status",
      "source": { "kind": "tableCount", "table": "openconnector_job_logs",
                  "groupBy": ["status"], "labelDefaults": { "status": "unknown" } } },
    { "name": "mappings_total", "type": "gauge", "help": "Total configured mappings",
      "source": { "kind": "tableCount", "table": "openconnector_mappings" } },
    { "name": "rules_total", "type": "gauge", "help": "Total configured rules",
      "source": { "kind": "tableCount", "table": "openconnector_rules" } }
  ]
}
```

  (`openconnector_info` / `openconnector_up` are engine-implicit — never declared. All 9 descriptors are `tableCount` on chain-C legacy tables; the post-cutover flip to `objectCount` is a follow-up one-line manifest edit per descriptor.)
- [ ] 1.2 Validate via ManifestService diagnostics (no errors); confirm the `labelMap` capability for `status_code`→`status` and `result`→`status` is honoured by the engine (same mechanism as OR's own `webhook_deliveries_total{status}` from the `success` column).

## 2. Bootstrap/Routes wiring and deletions

- [ ] 2.1 `lib/AppInfo/Application.php`: add `AppHost\Bootstrap::register($context, self::APP_ID, …)`; drop the manual `SettingsService` container factory (autowiring suffices); keep all app-specific registrations (PDOK feature-flag adapters, Berichtenbox client, OR event listeners, `openconnector-integration` init-script listener, IntegrationRegistry boot wiring) untouched.
- [ ] 2.2 `appinfo/routes.php`: delegate the standard routes (dashboard page + catch-all, health, metrics, preferences) to `AppHost\Routes::standard($extra)`; keep all app-specific routes (sources, endpoints, jobs, mappings, rules, synchronizations, consumers, events, logs, action-matrix, user, `settings#rebase`) in `$extra`. Collapse the 21 named `ui#*` SPA routes into the standard catch-all — URLs unchanged; the info.xml `<navigation>` route `openconnector.dashboard.page` resolves again.
- [ ] 2.3 Delete `lib/Controller/HealthController.php`, `lib/Controller/MetricsController.php`, `lib/Controller/PreferencesController.php`; alias their controller names to the AppHost generics via Bootstrap.
- [ ] 2.4 Replace `lib/Controller/UiController.php` with a thin app-namespace subclass of `GenericDashboardController` overriding the protected CSP hook to preserve `connect-src *` (app-specific residue).
- [ ] 2.5 Reduce to one-line subclass stubs: `lib/Settings/OpenConnectorAdmin.php` (`extends GenericAdminSettings` — keep the class name: `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]` on `SettingsController::rebase()` and info.xml `<settings>` reference it), `lib/Sections/OpenConnectorAdmin.php` (`extends GenericSettingsSection`), `lib/Repair/InitializeRegister.php` (`extends GenericInitializeSettings`), `lib/Repair/InitializeActions.php` (`extends GenericInitializeActions`).
- [ ] 2.6 Fix the pre-existing unwired-repair-steps bug: add the `<repair-steps>` block to `appinfo/info.xml` pointing at the two stubs so register import + action seeding actually run on install/upgrade (repair step, NOT migration).
- [ ] 2.7 Delete the dead `getStats()`/`getSettings()`/`updateSettings()` methods from `lib/Service/SettingsService.php` (zero callers since chain-C); keep `rebase()` + its portability helpers.
- [ ] 2.8 Sweep references: unit tests for the deleted controllers, `tests/Unit/Repair/RegisterFragmentMergeTest.php` (now targets the stub/generic), psalm/phpstan baselines, `@spec` tags.

## 3. Parity verification

- [ ] 3.1 Diff `/api/metrics` output vs the 0.1 fixture: metric names, TYPE lines (gauge vs counter per the proposal table), label sets, and values identical. Document-and-accept only the enumerated intentional deltas; verify label-value casing (today lowercased) matches.
- [ ] 3.2 Diff `/api/health` vs fixture: shape parity plus the four intentional deltas — now `#[PublicPage]` (anonymous 200), 503 on database failure (ADR-006), check id `sources_table`→`openregister`, additive `app`/`version` fields.
- [ ] 3.3 Verify missing-table behaviour: on a post-drop instance every `tableCount` metric still emits a `0` sample (mirrors today's catch-fallback), never a 500.
- [ ] 3.4 Run the OR AppHost Newman contract collection against openconnector's endpoints; extend `tests/integration/openconnector.postman_collection.json` with health (anonymous, 200/503 policy) + metrics (admin-only, exposition format, all 11 metric names) requests and run via `tests/integration/run-newman.sh`.
- [ ] 3.5 Existing e2e suite green. **Verification caveat**: openconnector's Playwright setup has a known not-headless quirk (gate-19 rollout follow-up: the suite was wrongly all-excluded and does not run headless) — run it in the mode that actually executes, and do not treat a vacuous all-excluded pass as parity evidence; the Newman collections carry the API-contract burden.
- [ ] 3.6 Verify the SPA still serves on `/`, a deep link (e.g. `/sources`), and an unknown sub-path via the catch-all, with the `connect-src *` CSP header intact; verify the app navigation entry resolves (stale `openconnector.dashboard.page` route fixed).
- [ ] 3.7 Verify per-user preferences roundtrip via the generic controller against a key written by the old controller (`pref_` namespace preserved — no orphaned values).
- [ ] 3.8 On a clean install (or `occ maintenance:repair`), verify the register import and action seeding now run (defect #3 fixed): `openconnector` register/schemas present, seed actions present.

## 4. Docs

- [ ] 4.1 Update openconnector docs: observability page now points at the manifest `observability` block as the source of truth; note the admin-only metrics / public health posture, the legacy-table descriptors, and the planned one-line `tableCount`→`objectCount` flip when the OR cutover completes.

## 5. Quality gates

- [ ] 5.1 `composer check:strict` green (PHPCS, PHPMD, Psalm, PHPStan — fix any pre-existing issues encountered in touched files, don't baseline-suppress new code).
- [ ] 5.2 All 18 hydra gates green (`scripts/run-hydra-gates.sh`), including gate-16 spec coverage on changed methods and gate-19 e2e coverage on the spec scenarios.
- [ ] 5.3 Gate-22 manifest validation green (`src/manifest.json` incl. the new `observability` block validates against the canonical schema).
