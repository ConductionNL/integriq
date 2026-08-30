---
status: proposed
---

# OpenConnector AppHost Adoption

## Purpose

OpenConnector serves its health and metrics endpoints, per-user preferences,
admin settings panel/section, and ADR-023 action-authorization seeding
through the OpenRegister AppHost declarative engine, with output parity to
the hand-written controllers/classes it replaces, fixing the pre-existing
admin-only-health and 200-on-critical defects (ADR-006) and removing dead
`SettingsService` methods.

**Delivery scope**: the observability half (health + metrics), the
Preferences controller, and the admin Settings/Section pair + the
ADR-023 action-matrix repair step are adopted. The SPA-shell
(`UiController` → `GenericDashboardController`) and the register-import
repair step (`InitializeRegister` → `GenericInitializeSettings`) are
NOT adopted — see "Not delivered" below for why each stays bespoke.

**Cross-references**: `openregister/openspec/changes/apphost-observability-engine/specs/apphost-observability/spec.md`, `openregister/openspec/changes/apphost-boilerplate-controllers/`

---

## ADDED Requirements

### Requirement: Declarative Metrics Parity

OpenConnector SHALL serve `GET /apps/openconnector/api/metrics` through the AppHost engine from `tableCount` descriptors in `src/manifest.json`, with metric names, Prometheus types, label sets, and values identical to the pre-adoption `MetricsController` output, and the endpoint SHALL remain admin-only.

#### Scenario: Metrics output parity

- **GIVEN** a seeded instance with rows in the legacy `openconnector_*` tables
- **WHEN** `GET /apps/openconnector/api/metrics` is called by an admin
- **THEN** the response MUST be Prometheus text exposition format 0.0.4 containing `openconnector_info`, `openconnector_up`, `openconnector_sources_total{type}`, `openconnector_calls_total{status}`, `openconnector_synchronizations_total`, `openconnector_synchronization_runs_total{status}`, `openconnector_endpoints_total`, `openconnector_jobs_total`, `openconnector_job_runs_total{status}`, `openconnector_mappings_total`, `openconnector_rules_total`, with types (gauge/counter) and values matching direct table counts and null group values mapped to their label defaults (`type="rest"`, `status="unknown"`)
- @e2e exclude API-only endpoint — covered by `tests/integration/openconnector.postman_collection.json` folder "11. Observability (AppHost health/metrics)"

#### Scenario: Metrics endpoint stays admin-only

- **GIVEN** a non-admin authenticated user
- **WHEN** `GET /apps/openconnector/api/metrics` is called
- **THEN** the request MUST be rejected by the framework (no `NoAdminRequired` posture), unchanged from pre-adoption
- @e2e exclude API-only endpoint — covered by `tests/integration/openconnector.postman_collection.json` folder "11. Observability (AppHost health/metrics)"

#### Scenario: Dropped legacy table degrades to zero samples

- **GIVEN** an instance where migration `Version2Date20260520000099` has dropped a counted legacy table
- **WHEN** `GET /apps/openconnector/api/metrics` is called by an admin
- **THEN** the affected metric MUST still be emitted with a `0` sample (mirroring the pre-adoption catch-fallback) and the endpoint MUST NOT return a 500
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Declarative Health per ADR-006

OpenConnector SHALL serve `GET /apps/openconnector/api/health` through the AppHost engine from two declared checks — `database` (critical) and `orAvailable` (degraded, replacing the bespoke sources-table join probe) — and the endpoint SHALL be public and SHALL return 503 on critical failure, fixing the pre-existing admin-only and 200-on-error defects.

#### Scenario: Health is public and healthy

- **GIVEN** a healthy instance
- **WHEN** `GET /apps/openconnector/api/health` is called anonymously (no session)
- **THEN** the response MUST be HTTP 200 with `status = "ok"`, `checks.database = "ok"`, and `checks.openregister = "ok"` in the standard shape (including `app` and `version` fields)
- @e2e exclude API-only endpoint — covered by `tests/integration/openconnector.postman_collection.json` folder "11. Observability (AppHost health/metrics)"

#### Scenario: Critical database failure returns 503

- **GIVEN** an instance whose database check fails
- **WHEN** `GET /apps/openconnector/api/health` is called anonymously
- **THEN** the response MUST be HTTP 503 with `status = "error"` (ADR-006 `adr006` status-code policy; pre-adoption this wrongly returned HTTP 200)
- @e2e exclude API-only endpoint — requires deliberately breaking the DB connection; covered by the OR AppHost engine's own unit tests, not exercised live by this app's Newman suite

#### Scenario: OpenRegister unavailability only degrades

- **GIVEN** an instance where the OpenRegister ObjectService cannot be resolved
- **WHEN** `GET /apps/openconnector/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `status = "degraded"` and `checks.openregister` reporting failure, while `checks.database` remains `"ok"`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: App-Specific Residue Preserved

The app-specific `SettingsController::rebase()` action and the `SettingsService` rebase implementation SHALL remain in openconnector (they are not boilerplate), and the dead `getStats()`/`getSettings()`/`updateSettings()` methods on `SettingsService` SHALL be deleted (zero callers since the chain-C OR cutover).

#### Scenario: Rebase action unchanged after adoption

- **GIVEN** an admin user with log-retention settings configured
- **WHEN** `POST /apps/openconnector/api/settings/rebase` is called
- **THEN** the rebase MUST recompute log deletion timestamps and return the same response shape as before adoption, guarded by `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Generic Preferences Controller

OpenConnector SHALL serve `GET|PUT /apps/openconnector/api/preferences/{key}` through the AppHost `GenericPreferencesController`, preserving the `pref_` key namespace and the `[a-z0-9-]`/64-char key sanitisation so values written before adoption keep resolving.

#### Scenario: Pre-adoption preference value survives adoption

- **GIVEN** a user who stored a preference value through the pre-adoption controller
- **WHEN** `GET /apps/openconnector/api/preferences/{key}` is called for that key after adoption
- **THEN** the response MUST return the previously stored value (`pref_` namespace unchanged)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Admin Settings and Section as AppHost Stubs

OpenConnector's admin settings panel (`Settings\OpenConnectorAdmin`) and its section (`Sections\OpenConnectorAdmin`) SHALL be one-line app-namespace subclasses of the AppHost generics (`GenericAdminSettings`, `GenericSettingsSection`), the `OpenConnectorAdmin` class names SHALL remain valid for the existing `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]` references (used across ~30 controller methods) and the `appinfo/info.xml` `<settings>` block, `getAuthorizedAppConfig()` SHALL keep returning an empty map (fail-closed, full-admin-only — unchanged from pre-adoption), and the section's display name SHALL stay translated (resolved via the app's own `IL10N` before constructing the generic section, which has no l10n hook of its own).

#### Scenario: Admin settings panel still renders

- **GIVEN** an admin user
- **WHEN** the admin opens Settings → Administration → OpenConnector
- **THEN** the settings panel MUST render through the generic admin settings stub in the existing (translated) section

#### Scenario: Existing admin-only endpoints keep their exact gating

- **GIVEN** a non-admin authenticated user
- **WHEN** any controller method carrying `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]` is called (e.g. `sources#test`, `jobs#run`, LTI/EUDI/DSO-PKI admin endpoints)
- **THEN** the request MUST be rejected, identical to the pre-adoption bespoke `OpenConnectorAdmin::getAuthorizedAppConfig()` returning `[]`
- @e2e exclude API-only endpoint gating — covered by `tests/Unit/Settings/OpenConnectorAdminTest.php`

### Requirement: Action-Authorization Matrix Repair Step as AppHost Stub

OpenConnector's ADR-023 action-matrix seeding repair step (`Repair\InitializeActions`) SHALL be a one-line app-namespace subclass of the AppHost `GenericInitializeActions` generic, reading/writing the same `actions` `IAppConfig` key (under the `openconnector` app id) that the still-bespoke `ActionAuthService` enforces against, and the repair steps (`InitializeRegister` and `InitializeActions`, both referenced from `appinfo/info.xml` `<repair-steps><post-migration>`) SHALL run on install and upgrade — fixing the pre-existing defect where they were wired nowhere and never ran.

#### Scenario: Repair steps run on install

- **GIVEN** a clean install of openconnector (or `occ maintenance:repair` on an existing instance)
- **WHEN** the app's repair steps execute
- **THEN** the `openconnector` register and its schemas MUST be imported (via the bespoke `InitializeRegister`) and the seed actions MUST be present (via the AppHost-generic `InitializeActions`)
- @e2e exclude backend install-time repair behaviour — verified via occ maintenance:repair in CI, no UI surface

#### Scenario: Admin-customised action matrix survives upgrade

- **GIVEN** an admin has customised the action-authorization matrix (non-empty)
- **WHEN** `InitializeActions` runs again on a subsequent upgrade
- **THEN** the existing matrix MUST be preserved unchanged (the generic step only seeds an empty matrix)
- @e2e exclude backend repair-step behaviour — covered by `tests/Unit/Repair/InitializeActionsAppHostAdapterTest.php` and the OR AppHost engine's own `GenericInitializeActions` unit tests

---

## Not delivered (verified, with reasons — not tracked as spec requirements)

These two boilerplate-adoption candidates were re-evaluated against the
current OpenRegister `development` (where the generic classes now exist) and
found to still require staying bespoke, for reasons unrelated to class
availability:

- **SPA shell (`UiController` → `GenericDashboardController`)**: the generic
  controller renders a plain `TemplateResponse` with no CSP override hook.
  OpenConnector's SPA needs a permissive `connect-src *` CSP so it can call
  externally-configured source APIs from the browser; adopting the generic
  would silently tighten the CSP and break that. `UiController` and its
  `ui#*`/catch-all routes stay bespoke until OpenRegister's
  `GenericDashboardController` grows a CSP hook.
- **Register-import repair step (`InitializeRegister` →
  `GenericInitializeSettings`)**: `InitializeRegister::deepMergeConfig()`
  (the ADR-037 register-fragment-union algorithm) is pinned by three existing
  reflection-based unit tests (`RegisterFragmentMergeTest`,
  `EudiRegisterFragmentTest`, `HitlApprovalRegisterFragmentTest`). The
  equivalent logic in OpenRegister's `AppHostSettingsService` is `private`
  and lives in a different class; converting would either break that test
  coverage or require duplicating the algorithm as a test stub. Deferred
  rather than trading away a safety net for a pure refactor — `appinfo/info.xml`
  already wires `InitializeRegister` correctly (see "Action-Authorization
  Matrix Repair Step as AppHost Stub" above), so this is a pure code-shape
  question, not a functional gap.
