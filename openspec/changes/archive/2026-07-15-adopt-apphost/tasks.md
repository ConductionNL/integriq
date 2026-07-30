# Tasks: OpenConnector Adopts OpenRegister AppHost

> **Delivery scope (2026-06-16, branch `build/adopt-apphost-2026-06-16`):**
> Only the **observability half** is implemented. The OpenRegister observability
> engine (`AppHost\Observability\*` + `AppHost\Controller\GenericHealth/Metrics`)
> is shipped on OR `development` and verified resolvable cross-app. The
> **boilerplate half** (`AppHost\Bootstrap`/`Routes`, `GenericPreferencesController`,
> `GenericDashboardController`, `GenericAdminSettings`/`GenericSettingsSection`,
> `GenericInitializeSettings`/`GenericInitializeActions`) does **not yet exist** in
> OR (`apphost-boilerplate-controllers` is still `proposed`). Sections 2.4–2.6 and the
> Preferences/SPA/Settings deletions are therefore DEFERRED — adopting absent classes
> would 500 every aliased route. They stay bespoke until the OR boilerplate engine merges.
>
> **2026-07-15 re-verification against HEAD** (this pass): this note above is now
> STALE — a lot happened on `development` since 2026-06-16 that this file was never
> updated to reflect. Re-verified every checkbox against the live `wip/build-adopt-apphost`
> worktree (based on current `origin/development`):
> - The OR `apphost-boilerplate-controllers` engine **shipped** (PR history:
>   `ec76f47e` "Adopt AppHost GenericPreferencesController" already merged 2026-06-16,
>   PLUS OR's own `development` now ships the FULL boilerplate half —
>   `GenericDashboardController`, `GenericAdminSettings`/`GenericSettingsSection`,
>   `GenericInitializeSettings`/`GenericInitializeActions`, `Bootstrap`/`Routes` all
>   exist in `lib/AppHost/`). Preferences was already adopted (2.3's "PreferencesController
>   DEFERRED" note below is stale). This pass adopts the admin-settings/section pair
>   and the ADR-023 action-matrix repair step (2.5); Dashboard/UiController (2.4) stays
>   correctly deferred for a *different*, still-valid reason (no CSP override hook
>   upstream); InitializeRegister stays bespoke (existing fragment-merge test coverage
>   pinned to its private method — see 2.5 below).
> - Gate-22 (manifest validation, task 5.3) is **no longer upstream-blocked**: the
>   pinned `@conduction/nextcloud-vue` schema (currently resolves to beta.212,
>   schema v2.19.0) now declares the `observability` property. `npm run check:manifest`
>   passes with 0 Ajv errors as of this pass.
> - Section 3 (parity verification) and 5.1/5.2 (quality gates) baseline figures in
>   this file (362→364 tests, 23 hydra gates) are also stale — the app has grown to
>   1407 PHPUnit tests baseline (1417 after this pass's new tests) and hydra gate
>   count has grown fleet-wide since. Re-verified functionally rather than by exact
>   historical numbers.

## 0. Baseline

- [x] 0.1 Capture baseline on a seeded dev instance: `curl /apps/openconnector/api/health` JSON (as admin — the endpoint is wrongly admin-only today) + `/apps/openconnector/api/metrics` Prometheus text; store both as fixtures for the parity diff. Also record HTTP status codes (health currently 200-always) so the intentional ADR-006 deltas are diffed knowingly, not discovered.
- [x] 0.2 Note instance state for the metrics fixture: whether the legacy `openconnector_*` tables still exist (pre-drop) or `Version2Date20260520000099` already dropped them (post-drop, all counted metrics emit fallback `0`). Capture one fixture per state if both environments are available.

## 1. Manifest observability block

- [x] 1.1 Add the `observability` block to `src/manifest.json`:

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
- [x] 1.2 Validate via ManifestService diagnostics (no errors); confirm the `labelMap` capability for `status_code`→`status` and `result`→`status` is honoured by the engine (same mechanism as OR's own `webhook_deliveries_total{status}` from the `success` column).

## 2. Bootstrap/Routes wiring and deletions

- [x] 2.1 `lib/AppInfo/Application.php`: add `registerAppHostObservability()` (hand-rolled, since `AppHost\Bootstrap` does not exist yet) — registers controller-name aliases `OCA\OpenConnector\Controller\HealthController`/`MetricsController` that build the OR `GenericHealth`/`GenericMetrics` controllers with `appName=openconnector`, resolving the engine collaborators (`ManifestLoader`/`HealthCheckExecutor`/`MetricsEngine`) from OR's registered app container. Manual `SettingsService` factory KEPT (harmless; removing it is unnecessary risk). All app-specific registrations (PDOK adapters, Berichtenbox, OR event listeners, init-script listener, IntegrationRegistry) untouched.
- [x] 2.2 `appinfo/routes.php`: `metrics#index`/`health#index` route names + URLs (`/api/metrics`, `/api/health`) UNCHANGED; they now resolve to the aliased generics via the 2.1 factories. (Collapsing the SPA `ui#*` routes / `Routes::standard()` DEFERRED with the boilerplate half — `AppHost\Routes` does not exist.)
- [x] 2.3 Delete `lib/Controller/HealthController.php`, `lib/Controller/MetricsController.php`. **Already at HEAD, note update**: both were converted to thin subclasses of the OR generics (not deleted outright — an equivalent, arguably better outcome: URL/route-name parity plus a concrete class for the `#[PublicPage]`/admin-only attributes). PreferencesController's "DEFERRED" note is now STALE — it was adopted in a follow-up PR (`ec76f47e`, merged 2026-06-16 as PR #55, "Adopt AppHost GenericPreferencesController") that landed after this file was last edited; `lib/Controller/PreferencesController.php` no longer exists, `/api/preferences/{key}` resolves to `OCA\OpenConnector\AppHost\Controller\GenericPreferencesController` via `Application::registerAppHostBoilerplate()`.
- [x] 2.4 **DEFERRED** (verified still-current reason, re-checked 2026-07-15): `GenericDashboardController` now EXISTS in OR `development` (`lib/AppHost/Controller/GenericDashboardController.php`), but it renders a plain `TemplateResponse` with **no CSP override hook** — adopting it would silently tighten the CSP and break openconnector's SPA's outbound calls to externally-configured source APIs (the app-specific `connect-src *` requirement). Confirmed by reading the current class source: no `csp`/`Csp` reference anywhere. Correctly stays deferred; the class existing does not remove the behavioural blocker. Bespoke `UiController` + its CSP kept.
- [x] 2.5 **Partially adopted 2026-07-15** (`GenericAdminSettings`/`GenericSettingsSection`/`GenericInitializeSettings`/`GenericInitializeActions` now all exist in OR `development`, unblocking this task): `lib/Settings/OpenConnectorAdmin.php` and `lib/Sections/OpenConnectorAdmin.php` are now one-line subclasses of `GenericAdminSettings`/`GenericSettingsSection`, wired via `Application::registerAppHostAdminSettings()` with the pre-adoption metadata pinned (section id `openconnector`, priority 10/97, icon `app-dark.svg`, translated name via `IL10N::t()` resolved in the factory since the generic has no l10n hook of its own). `getAuthorizedAppConfig()` returns `[]`, byte-identical to the bespoke original — verified by a new unit test (`tests/Unit/Settings/OpenConnectorAdminTest.php`) since this class gates ~30 `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]` controller methods across the app. `lib/Repair/InitializeActions.php` is now a one-line subclass of `GenericInitializeActions` (reads/writes the identical `actions` IAppConfig key the bespoke `ActionAuthService` enforces against). `lib/Repair/InitializeRegister.php` stays bespoke, NOT adopted: its `deepMergeConfig()` ADR-037 fragment-union algorithm is pinned by 3 existing reflection-based unit tests (`RegisterFragmentMergeTest`, `EudiRegisterFragmentTest`, `HitlApprovalRegisterFragmentTest`) that call `InitializeRegister::deepMergeConfig()` directly; OR's equivalent logic lives in a *different*, `private` method on `AppHostSettingsService`, so converting would either break that coverage or require duplicating the algorithm as a stub — verified by actually attempting the conversion and running the baseline suite (it broke exactly those 3 tests with 4 errors), then reverting.
- [x] 2.6 **Already fixed at HEAD, independently of this change** — verified FALSE at HEAD, not deferred: `appinfo/info.xml` already has `<repair-steps><post-migration><step>InitializeRegister</step><step>InitializeActions</step>…</post-migration></repair-steps>` (confirmed via `git log`: wired in commit `b6504739`, "Tue Jul 14 08:07:35 2026", well before this pass). Defect #3 from the proposal (repair steps wired nowhere) is fixed; this note in tasks.md was simply never updated after that landed. Nothing to do here.
- [x] 2.7 Delete the dead `getStats()`/`getSettings()`/`updateSettings()` methods from `lib/Service/SettingsService.php` (zero callers since chain-C); keep `rebase()` + its portability helpers.
- [x] 2.8 Sweep references: deleted `tests/Unit/Controller/HealthControllerTest.php` + `MetricsControllerTest.php`; dropped the `MetricsController.php` entry from `phpmd.baseline.xml`. (No psalm/phpstan baseline entries referenced the deleted files.)

## 3. Parity verification

- [x] 3.1 Diff `/api/metrics` output vs the 0.1 fixture: metric names, TYPE lines (gauge vs counter per the proposal table), label sets, and values identical. Document-and-accept only the enumerated intentional deltas; verify label-value casing (today lowercased) matches.
- [x] 3.2 Diff `/api/health` vs fixture: shape parity plus the four intentional deltas — now `#[PublicPage]` (anonymous 200), 503 on database failure (ADR-006), check id `sources_table`→`openregister`, additive `app`/`version` fields.
- [x] 3.3 Verify missing-table behaviour: on a post-drop instance every `tableCount` metric still emits a `0` sample (mirrors today's catch-fallback), never a 500.
- [x] 3.4 Extended `tests/integration/openconnector.postman_collection.json` with a new folder "11. Observability (AppHost health/metrics)": `health#index` anonymous (asserts 200, `status`/`checks.database`/`checks.openregister`/`app`/`version`), `metrics#index` admin (asserts 200 + exposition text contains all 11 `openconnector_*` metric names), `metrics#index` no-auth (asserts 401). `tests/integration/README-newman.md` coverage table + total count updated (now 58 requests / 79 assertions — also picked up the pre-existing undocumented "10. DSO STAM" folder while touching this table). **Not run against a live instance** in this pass (no seeded Nextcloud instance was available in this environment) — the collection is JSON-valid and request/assertion shapes were verified by inspection against `GenericHealthController`/`GenericMetricsController`'s actual response contract in the OR source; running `tests/integration/run-newman.sh` against a seeded dev instance is the remaining live-verification step. Running the *OR AppHost Newman contract collection itself* against openconnector (the other half of this task) was not attempted — out of scope for a per-app worktree with no access to OR's own collection or a live instance.
- [ ] 3.5 Existing e2e suite green. **Unfinishable in this pass — no live/headless browser instance available** in this worktree's environment. **Verification caveat** (unchanged from original): openconnector's Playwright setup has a known not-headless quirk (gate-19 rollout follow-up: the suite was wrongly all-excluded and does not run headless) — run it in the mode that actually executes, and do not treat a vacuous all-excluded pass as parity evidence; the Newman collections carry the API-contract burden.
- [ ] 3.6 Verify the SPA still serves on `/`, a deep link (e.g. `/sources`), and an unknown sub-path via the catch-all, with the `connect-src *` CSP header intact; verify the app navigation entry resolves. **Unfinishable in this pass — requires a live/seeded Nextcloud instance**, not available in this worktree's environment. Statically verified instead: `UiController` (unconverted, per 2.4) still owns every `ui#*`/catch-all route in `appinfo/routes.php` and its `makeSpaResponse()` CSP hook is untouched by this pass's changes.
- [ ] 3.7 Verify per-user preferences roundtrip via the generic controller against a key written by the old controller (`pref_` namespace preserved — no orphaned values). **Unfinishable in this pass — requires a live instance with pre-existing preference rows**; the PreferencesController adoption itself predates this pass (2.3). Not re-verified live here.
- [ ] 3.8 On a clean install (or `occ maintenance:repair`), verify the register import and action seeding now run (defect #3 fixed): `openconnector` register/schemas present, seed actions present. **Unfinishable in this pass — requires a live instance to run `occ maintenance:repair` against**. Defect #3 itself is confirmed fixed at the info.xml level (2.6); this task is specifically about observing the *runtime effect*, which needs a live instance this worktree does not have.

## 4. Docs

- [x] 4.1 Updated both openconnector observability docs (`docs/administrators/prometheus-metrics.md`, `docs/features/prometheus-metrics.md`): endpoints table now shows health as public/metrics as admin-only; health JSON examples show the `app`/`version` fields, the `openregister` check (replacing `sources_table`), the ADR-006 status-code policy (200/200/503 for ok/degraded/error) with a 503 example added; metrics table notes `info`/`up` are engine-implicit and the `tableCount`→`objectCount` flip is a planned one-line manifest edit; Kubernetes probe examples drop the now-unnecessary `Authorization` header on the health probe; "Implementation" section in the features doc repoints from the deleted-per-proposal (but actually-kept-as-thin-adapters) controllers to the manifest `observability` block as the source of truth, the OR engine classes, and the new Newman contract-test folder (3.4).

## 5. Quality gates

- [x] 5.1 **Re-verified 2026-07-15** (the "362/364 tests" baseline above is stale — the app has grown substantially since 2026-06-16). Baseline before this pass's 2.5 changes: **1407 tests, 3977 assertions, 1 skipped, 0 failures** (`docker run oc-phpunit-83:local vendor/bin/phpunit -c phpunit-unit.xml`). After this pass's changes (AdminSettings/Section/InitializeActions adoption + 10 new unit tests + Newman/docs changes): **1417 tests, 3987 assertions, 1 skipped, 0 failures** — no new failures, +10 tests all green. `php -l` clean on every touched/new PHP file. `composer check:no-legacy-types` PASS, `composer check:routes` PASS (161 routes). Static analysis re-run scoped to every touched/new `lib/` file: `phpcs` 0 errors (only pre-existing warnings, confirmed pre-existing by diffing against the HEAD copy of the same file), `phpmd` 0 new violations (2 pre-existing findings in `Application.php` confirmed via the same before/after diff), `psalm --no-cache` 0 errors (added 5 missing `UndefinedClass` allowlist entries to `psalm.xml` for the new OR AppHost generics + the pre-existing `IMetricsProvider` gap; added `@psalm-suppress TooManyArguments` docblocks on the 3 new/changed factory closures — same class of Psalm limitation already tolerated for `HealthController`/`MetricsController`), `phpstan` 0 errors on touched files (added `excludePaths` + matching `ignoreErrors` regex entries to `phpstan.neon` for the 3 new "extends unknown class" cases, mirroring the exact existing `HealthController`/`MetricsController` pattern). Full-repo `phpstan`/`phpmd`/`phpcs` runs show pre-existing, unrelated debt elsewhere in the app (confirmed not touched by this change).
- [~] 5.2 **Hydra gates not run in this pass** — `run-hydra-gates.sh` requires the hydra tooling context (not available standalone in this per-app worktree in this environment) and this task's LOCAL CHECKS section scopes local verification to the `oc-phpunit-83:local` docker image (phpunit/composer/npm), not the hydra gate runner. The equivalent manual checks were done instead: route-auth (2.5's new classes carry the same auth posture as their bespoke predecessors, verified by unit test), route-reachability (`check:routes` PASS), spec-coverage (new/changed methods carry `@spec` tags to this change's canonical spec), no-legacy-types (PASS). Left unticked rather than falsely claiming the gate script ran.
- [x] 5.3 **Gate-22 manifest validation — RESOLVED, no longer upstream-blocked.** Re-verified 2026-07-15: the pinned `@conduction/nextcloud-vue` dependency (`^1.0.0-beta.212` in `package.json`/`package-lock.json`) now ships `src/schemas/app-manifest-v2.schema.json` at schema version **2.19.0**, which **does** declare the `observability` property (verified by extracting the actual npm tarball for `1.0.0-beta.212`). `npm ci && npm run check:manifest` now passes for real: `Ajv validation: PASS (0 errors)` against the live installed schema — not a patched/hypothetical one. The `nextcloud-vue-observability-schema.patch` file in this change folder is now **superseded/historical** — the schema extension it proposed shipped upstream under a different, later beta than the one this task originally checked against; no patch needs to land, no follow-up PR is required. `npm run check:specs` (json-strict + manifest + register) also PASS.
