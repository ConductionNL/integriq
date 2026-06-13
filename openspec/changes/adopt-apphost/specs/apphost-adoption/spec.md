---
status: proposed
---

# OpenConnector AppHost Adoption

## Purpose

OpenConnector serves its health, metrics, preferences, and SPA-shell endpoints through the OpenRegister AppHost (declarative observability descriptors + generic boilerplate controllers), with output parity to the hand-written code it deletes, and with the four pre-existing defects (admin-only health, 200-on-critical health, unwired repair steps, dead settings methods) fixed.

**Cross-references**: `openregister/openspec/changes/apphost-observability-engine/specs/apphost-observability/spec.md`, `openregister/openspec/changes/apphost-boilerplate-controllers/`

---

## Requirements

### Requirement: Declarative Metrics Parity

OpenConnector SHALL serve `GET /apps/openconnector/api/metrics` through the AppHost engine from `tableCount` descriptors in `src/manifest.json`, with metric names, Prometheus types, label sets, and values identical to the pre-adoption `MetricsController` output, and the endpoint SHALL remain admin-only.

#### Scenario: Metrics output parity

- **GIVEN** a seeded instance with rows in the legacy `openconnector_*` tables
- **WHEN** `GET /apps/openconnector/api/metrics` is called by an admin
- **THEN** the response MUST be Prometheus text exposition format 0.0.4 containing `openconnector_info`, `openconnector_up`, `openconnector_sources_total{type}`, `openconnector_calls_total{status}`, `openconnector_synchronizations_total`, `openconnector_synchronization_runs_total{status}`, `openconnector_endpoints_total`, `openconnector_jobs_total`, `openconnector_job_runs_total{status}`, `openconnector_mappings_total`, `openconnector_rules_total`, with types (gauge/counter) and values matching direct table counts and null group values mapped to their label defaults (`type="rest"`, `status="unknown"`)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Metrics endpoint stays admin-only

- **GIVEN** a non-admin authenticated user
- **WHEN** `GET /apps/openconnector/api/metrics` is called
- **THEN** the request MUST be rejected by the framework (no `NoAdminRequired` posture), unchanged from pre-adoption
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

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
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Critical database failure returns 503

- **GIVEN** an instance whose database check fails
- **WHEN** `GET /apps/openconnector/api/health` is called anonymously
- **THEN** the response MUST be HTTP 503 with `status = "error"` (ADR-006 `adr006` status-code policy; pre-adoption this wrongly returned HTTP 200)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: OpenRegister unavailability only degrades

- **GIVEN** an instance where the OpenRegister ObjectService cannot be resolved
- **WHEN** `GET /apps/openconnector/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `status = "degraded"` and `checks.openregister` reporting failure, while `checks.database` remains `"ok"`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Generic Preferences Controller

OpenConnector SHALL serve `GET|PUT /apps/openconnector/api/preferences/{key}` through the AppHost `GenericPreferencesController`, preserving the `pref_` key namespace and the `[a-z0-9-]`/64-char key sanitisation so values written before adoption keep resolving.

#### Scenario: Pre-adoption preference value survives adoption

- **GIVEN** a user who stored a preference value through the pre-adoption controller
- **WHEN** `GET /apps/openconnector/api/preferences/{key}` is called for that key after adoption
- **THEN** the response MUST return the previously stored value (`pref_` namespace unchanged)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: SPA Shell via Generic Dashboard Controller

OpenConnector SHALL serve its SPA shell (root, deep links, and the non-`api` catch-all) through a thin app-namespace subclass of `GenericDashboardController` that preserves the app-specific permissive `connect-src *` Content-Security-Policy, replacing the 21-method `UiController`; all SPA URLs SHALL be unchanged and the `appinfo/info.xml` navigation route SHALL resolve.

#### Scenario: Deep link serves the SPA shell with the app CSP

- **GIVEN** an authenticated user
- **WHEN** the user navigates directly to `/apps/openconnector/sources` (or any non-`api` sub-path)
- **THEN** the SPA shell MUST render and the client-side router MUST take over on the requested section, and the response CSP MUST allow `connect-src *`

### Requirement: Admin Settings and Repair Steps as AppHost Stubs

OpenConnector's admin settings panel/section and its register/action initialisation SHALL be one-line app-namespace subclasses of the AppHost generics (`GenericAdminSettings`, `GenericSettingsSection`, `GenericInitializeSettings`, `GenericInitializeActions`), the `OpenConnectorAdmin` class name SHALL remain valid for the existing `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]` reference, and the repair steps SHALL be registered in `appinfo/info.xml` `<repair-steps>` (fixing the pre-existing bug where they were wired nowhere and never ran).

#### Scenario: Repair steps run on install

- **GIVEN** a clean install of openconnector (or `occ maintenance:repair` on an existing instance)
- **WHEN** the app's repair steps execute
- **THEN** the `openconnector` register and its schemas MUST be imported and the seed actions MUST be present (pre-adoption: neither ever ran because the steps were not registered)
- @e2e exclude backend install-time repair behaviour — verified via occ maintenance:repair in CI, no UI surface

#### Scenario: Admin settings panel still renders

- **GIVEN** an admin user
- **WHEN** the admin opens Settings → Administration → OpenConnector
- **THEN** the settings panel MUST render through the generic admin settings stub in the existing section

### Requirement: App-Specific Residue Preserved

The app-specific `SettingsController::rebase()` action and `SettingsService` rebase implementation SHALL remain in openconnector (they are not boilerplate), and the dead `getStats()`/`getSettings()`/`updateSettings()` methods on `SettingsService` SHALL be deleted.

#### Scenario: Rebase action unchanged after adoption

- **GIVEN** an admin user with log-retention settings configured
- **WHEN** `POST /apps/openconnector/api/settings/rebase` is called
- **THEN** the rebase MUST recompute log deletion timestamps and return the same response shape as before adoption, guarded by `#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection
