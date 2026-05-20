# Tasks: endpoint-workspace-connectors

> Sub-bullets describe the work per task. Each top-level checkbox is the unit Hydra tracks; flip when the whole task (implementation + tests) is done. ADR-032 cap respected (≤20).

## Task 1: Deduplication check (REQ-EWC-001 – REQ-EWC-010)
- **spec_ref**: `specs/endpoint-workspace-connectors/spec.md`
- **files**: `lib/Service/`, `openspec/specs/`
- **acceptance_criteria**:
  - GIVEN a search of `lib/Service/` for `recast`, `liquit`, `horizon`, `workspace` WHEN performed THEN zero matches are found (no pre-existing implementation)
  - GIVEN a search of `openspec/specs/` for workspace connector endpoints WHEN performed THEN no overlapping spec exists
  - GIVEN the existing `CallService`, `SourceMapper`, `AuthenticationService` WHEN reviewed THEN they cover `apikey` and `basic` auth — no new auth type is needed
- Grep `lib/Service/` and `openspec/specs/` for overlap; document findings (result: no overlap). Confirm `apikey` and `basic` auth types exist in `AuthenticationService`. Record deduplication finding in PR description.
- [ ] Task complete

## Task 2: RecastLiquitService — connection test (REQ-EWC-002)
- **spec_ref**: `specs/endpoint-workspace-connectors/spec.md#req-ewc-002`
- **files**: `lib/Service/RecastLiquitService.php`
- **acceptance_criteria**:
  - GIVEN an active Recast/Liquit source WHEN `testConnection()` is called THEN `{"success": true, "applicationCount": N, "latencyMs": M}` is returned
  - GIVEN an unreachable Recast/Liquit API WHEN `testConnection()` is called THEN `{"success": false, "message": "Connection failed"}` is returned; source status set to `error`
  - GIVEN HTTP 401 from Recast/Liquit WHEN `testConnection()` is called THEN `{"success": false, "message": "Authentication failed"}` is returned
- Create `RecastLiquitService` with `testConnection(Source $source): array`. Use `CallService::call()` for the GET to `{apiBaseUrl}/api/v1/applications`. Wrap in `try/catch`; return typed result array. No `$e->getMessage()` in response. Add EUPL-1.2 PHPDoc header. PHPUnit tests (≥3 methods).
- [ ] Task complete

## Task 3: RecastLiquitService — app catalogue and launch (REQ-EWC-003, REQ-EWC-004)
- **spec_ref**: `specs/endpoint-workspace-connectors/spec.md#req-ewc-003`
- **files**: `lib/Service/RecastLiquitService.php`
- **acceptance_criteria**:
  - GIVEN an active source WHEN `getApplications($source, $userId)` is called THEN an array of application objects is returned
  - GIVEN a valid `$appId` WHEN `launchApplication($source, $appId, $userId)` is called THEN `{"success": true, "launchUrl": "...", "message": "Application launch initiated"}` is returned
  - GIVEN the API returns 404 for an unknown app WHEN `launchApplication()` is called THEN `{"success": false, "message": "Application not found"}` is returned
- Add `getApplications(Source $source, string $userId): array` and `launchApplication(Source $source, string $appId, string $userId): array` to `RecastLiquitService`. Route via `CallService::call()`. Map errors to generic messages. PHPUnit tests (≥3 methods per public method).
- [ ] Task complete

## Task 4: HorizonService — connection test (REQ-EWC-006)
- **spec_ref**: `specs/endpoint-workspace-connectors/spec.md#req-ewc-006`
- **files**: `lib/Service/HorizonService.php`
- **acceptance_criteria**:
  - GIVEN an active Horizon source WHEN `testConnection()` is called THEN `{"success": true, "poolCount": N, "pools": [...], "latencyMs": M}` is returned
  - GIVEN an unreachable Horizon server WHEN `testConnection()` is called THEN `{"success": false, "message": "Connection failed"}` is returned
  - GIVEN HTTP 401 from Horizon WHEN `testConnection()` is called THEN `{"success": false, "message": "Authentication failed"}` is returned
- Create `HorizonService` with `testConnection(Source $source): array`. Call `{connectionServerUrl}/rest/inventory/v5/desktop-pools` via `CallService`. Parse pool list from response. Add EUPL-1.2 PHPDoc header. PHPUnit tests (≥3 methods).
- [ ] Task complete

## Task 5: HorizonService — pool retrieval and session launch (REQ-EWC-007, REQ-EWC-008)
- **spec_ref**: `specs/endpoint-workspace-connectors/spec.md#req-ewc-007`
- **files**: `lib/Service/HorizonService.php`
- **acceptance_criteria**:
  - GIVEN an active source WHEN `getDesktopPools($source, $userId)` is called THEN an array of pool objects is returned
  - GIVEN `launchMode = html-access` WHEN `initiateSession($source, $poolId, $userId)` is called THEN `launchUrl` is an HTTPS URL
  - GIVEN `launchMode = native-client` WHEN `initiateSession()` is called THEN `launchUrl` is a `vmware-view://` URI
  - GIVEN the pool is not entitled for the user WHEN `initiateSession()` is called THEN `{"error": "Not authorised for this desktop pool"}` is returned
- Add `getDesktopPools(Source $source, string $userId): array` and `initiateSession(Source $source, string $poolId, string $userId): array`. Use `configuration.launchMode` to determine response URL type. Use `configuration.apiVersion` (default `v5`) for path prefix. PHPUnit tests (≥3 methods per public method).
- [ ] Task complete

## Task 6: Source type validation (REQ-EWC-010)
- **spec_ref**: `specs/endpoint-workspace-connectors/spec.md#req-ewc-010`
- **files**: `lib/Service/SourcesService.php` or `lib/Controller/SourcesController.php`
- **acceptance_criteria**:
  - GIVEN a `recast-liquit` Source without `apikey` WHEN saved THEN HTTP 400 with `"API key is required for Recast/Liquit sources"` is returned before any API call
  - GIVEN a `vmware-horizon` Source without `connectionServerUrl` WHEN saved THEN HTTP 400 with `"Connection server URL is required for VMware Horizon sources"` is returned
  - GIVEN a `vmware-horizon` Source with a non-HTTPS `connectionServerUrl` WHEN saved THEN HTTP 400 with `"Horizon Connection Server URL must use HTTPS"` is returned
- Add a `validateWorkspaceSource(array $data): ?string` method (returns error message or null). Call it from the Sources save path for types `recast-liquit` and `vmware-horizon`. PHPUnit tests covering all three validation rules.
- [ ] Task complete

## Task 7: WorkspaceConnectorController — Recast/Liquit endpoints (REQ-EWC-003, REQ-EWC-004)
- **spec_ref**: `specs/endpoint-workspace-connectors/spec.md#req-ewc-003`
- **files**: `lib/Controller/WorkspaceConnectorController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an active Recast/Liquit source WHEN `GET /api/workspace/recast/apps` is called THEN HTTP 200 with app array is returned
  - GIVEN no Recast/Liquit source is configured WHEN the endpoint is called THEN HTTP 404 with `"No Recast/Liquit source configured"` is returned
  - GIVEN the source is in `error` state WHEN the endpoint is called THEN HTTP 503 with `"Workspace connector unavailable"` is returned
  - GIVEN `POST /api/workspace/recast/launch/{appId}` WHEN called THEN delegates to `RecastLiquitService::launchApplication()`
- Create `WorkspaceConnectorController`. Add `#[NoAdminRequired]` on `getRecastApps()` and `launchRecastApp()`. Resolve source from `IAppConfig`. Register routes in `appinfo/routes.php` (specific routes before wildcard). Add EUPL-1.2 PHPDoc header.
- [ ] Task complete

## Task 8: WorkspaceConnectorController — VMware Horizon endpoints (REQ-EWC-007, REQ-EWC-008)
- **spec_ref**: `specs/endpoint-workspace-connectors/spec.md#req-ewc-007`
- **files**: `lib/Controller/WorkspaceConnectorController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an active Horizon source WHEN `GET /api/workspace/horizon/pools` is called THEN HTTP 200 with pools array is returned
  - GIVEN no Horizon source is configured WHEN the endpoint is called THEN HTTP 404 with `"No Horizon source configured"` is returned
  - GIVEN `POST /api/workspace/horizon/launch/{poolId}` WHEN called THEN `{"launchUrl": "...", "protocol": "...", "tokenExpiry": "..."}` is returned
  - GIVEN the pool is not entitled for the user WHEN launch is called THEN HTTP 403 with `"Not authorised for this desktop pool"` is returned
- Add `getHorizonPools()` and `launchHorizonPool($poolId)` to `WorkspaceConnectorController`. Annotate `#[NoAdminRequired]`. Register routes in `appinfo/routes.php`. PHPUnit tests (≥3 methods).
- [ ] Task complete

## Task 9: SourcesController test-connection integration (REQ-EWC-002, REQ-EWC-006)
- **spec_ref**: `specs/endpoint-workspace-connectors/spec.md#req-ewc-002`
- **files**: `lib/Controller/SourcesController.php`, `lib/Service/RecastLiquitService.php`, `lib/Service/HorizonService.php`
- **acceptance_criteria**:
  - GIVEN a `recast-liquit` Source WHEN test-connection is triggered via `SourcesController` THEN `RecastLiquitService::testConnection()` is called and the result is returned
  - GIVEN a `vmware-horizon` Source WHEN test-connection is triggered THEN `HorizonService::testConnection()` is called
  - GIVEN any test-connection call WHEN the service throws an exception THEN HTTP 503 with a generic message is returned (not the exception message)
- Extend `SourcesController::testConnection()` dispatch to handle `recast-liquit` and `vmware-horizon` type values. Inject `RecastLiquitService` and `HorizonService` via constructor DI. PHPUnit tests verifying dispatch.
- [ ] Task complete

## Task 10: Call logging verification (REQ-EWC-009)
- **spec_ref**: `specs/endpoint-workspace-connectors/spec.md#req-ewc-009`
- **files**: `lib/Service/RecastLiquitService.php`, `lib/Service/HorizonService.php`
- **acceptance_criteria**:
  - GIVEN a Recast/Liquit app-catalogue call WHEN `CallService::call()` completes THEN a CallLog record exists with `sourceId`, `endpoint`, `method = GET`, `statusCode`, `responseTime`
  - GIVEN a Horizon pool call fails with timeout WHEN caught THEN a CallLog record with `statusCode = 0` is created
  - GIVEN an admin filters CallLog by a workspace connector source WHEN the list is retrieved THEN all catalogue, launch, and test-connection calls appear
- Verify that `CallService::call()` (already called by both services) creates CallLog records automatically. Confirm via integration test: call the endpoint, then query `GET /api/calllog?sourceId={id}` and assert records are present. No additional instrumentation needed if `CallService` already logs.
- [ ] Task complete

## Task 11: Route registration and auth annotation review (ADR-005, ADR-016)
- **spec_ref**: ADR-005, ADR-016
- **files**: `appinfo/routes.php`, `lib/Controller/WorkspaceConnectorController.php`
- **acceptance_criteria**:
  - GIVEN `appinfo/routes.php` WHEN grep'd for workspace routes THEN all four workspace routes are listed (recast/apps, recast/launch, horizon/pools, horizon/launch)
  - GIVEN each controller method WHEN inspected THEN each has exactly one valid auth attribute (`#[NoAdminRequired]`)
  - GIVEN a non-authenticated request WHEN any workspace endpoint is called THEN HTTP 401 is returned
  - GIVEN the hydra-gate-route-auth gate runs WHEN applied to the updated routes.php THEN zero failures on workspace routes
- Audit `appinfo/routes.php` to confirm all four routes are registered. Run `hydra-gate-route-auth` and `hydra-gate-semantic-auth` locally. Fix any missing annotations. No method should carry `#[PublicPage]` — these endpoints require Nextcloud authentication.
- [ ] Task complete

## Task 12: PHPUnit integration tests (ADR-008)
- **spec_ref**: ADR-008
- **files**: `tests/Unit/Service/RecastLiquitServiceTest.php`, `tests/Unit/Service/HorizonServiceTest.php`, `tests/Unit/Controller/WorkspaceConnectorControllerTest.php`
- **acceptance_criteria**:
  - GIVEN `composer check:strict` WHEN run THEN all new test files pass
  - GIVEN each service WHEN tested THEN success path, error path (unreachable), and auth-failure path are covered
  - GIVEN the controller WHEN tested THEN missing-source (404), unavailable-source (503), and happy-path (200) are covered
  - GIVEN integration tests WHEN run THEN error paths (401, 503, 404) are covered in addition to happy path (200)
- Write PHPUnit tests for `RecastLiquitService` (≥3 methods), `HorizonService` (≥3 methods), `WorkspaceConnectorController` (≥3 methods). Add Newman/Postman collection entries for the four new endpoints in `tests/integration/`. Test credentials use env variable placeholders.
- [ ] Task complete

## Task 13: SPDX headers and spec traceability (ADR-014, ADR-003)
- **spec_ref**: ADR-014, ADR-003
- **files**: `lib/Service/RecastLiquitService.php`, `lib/Service/HorizonService.php`, `lib/Controller/WorkspaceConnectorController.php`
- **acceptance_criteria**:
  - GIVEN each new PHP file WHEN grep'd for `@license` and `@copyright` THEN both tags are present
  - GIVEN each new PHP file WHEN grep'd for `@spec` THEN a tag pointing to `openspec/changes/endpoint-workspace-connectors/tasks.md#task-N` is present
  - GIVEN `scripts/run-quality.sh spdx-headers` WHEN run THEN zero failures on new files
- Verify all three new PHP files have complete PHPDoc headers with `@author`, `@copyright 2026 Conduction B.V.`, `@license EUPL-1.2`, `@link https://conduction.nl`, and `@spec` tags. Run the SPDX gate.
- [ ] Task complete

## Task 14: Smoke test — workspace connector endpoints
- **spec_ref**: ADR-008 (smoke testing)
- **files**: (no file changes — manual/CI verification)
- **acceptance_criteria**:
  - GIVEN a running Nextcloud instance WHEN `curl -u admin:admin GET .../api/workspace/recast/apps` is called with no source configured THEN HTTP 404 is returned
  - GIVEN a running Nextcloud instance WHEN `curl -u admin:admin GET .../api/workspace/horizon/pools` is called with no source configured THEN HTTP 404 is returned
  - GIVEN an unauthenticated request WHEN either endpoint is called THEN HTTP 401 is returned
  - GIVEN a `POST /api/workspace/recast/launch/nonexistent` WHEN called THEN HTTP 404 or 503 is returned (not 500)
- After implementation, run the four curl commands against a local Nextcloud + OpenConnector instance. Document results in the PR description. Verify no 500 errors on any path. Verify error messages do not contain stack traces, SQL, or internal paths.
- [ ] Task complete
