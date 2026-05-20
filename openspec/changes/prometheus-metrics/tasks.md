# Tasks: prometheus-metrics

> Sub-bullets describe the work per task. Each top-level checkbox is the unit Hydra tracks; flip it when the whole task (implementation + tests) is complete. ADR-032 cap respected (≤20).

## Task 1: Core Metrics Controller — Sources, Calls, Sync (REQ-PROM-001 through REQ-PROM-006)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-001` – `#req-prom-006`
- **files**: `lib/Controller/MetricsController.php`
- **acceptance_criteria**:
  - GIVEN an admin user WHEN requesting `GET /api/metrics` THEN response is `text/plain; version=0.0.4` with Prometheus format lines
  - GIVEN database has sources by type WHEN metrics collected THEN `openconnector_sources_total{type=...}` grouped correctly
  - GIVEN call logs with status codes WHEN metrics collected THEN `openconnector_calls_total{status=...}` emitted per code
  - GIVEN a database error in one collector WHEN endpoint responds THEN zero-value fallback emitted, HTTP 200 returned
- [x] Task complete

## Task 2: Health Check Endpoint — Core Checks (REQ-PROM-010 scenarios 1–4)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-010`
- **files**: `lib/Controller/HealthController.php`
- **acceptance_criteria**:
  - GIVEN database accessible and sources table present WHEN health called THEN `{"status": "ok", "checks": {"database": "ok", "sources_table": "ok"}}`
  - GIVEN sources table missing WHEN health called THEN `{"status": "degraded", "checks": {"database": "ok", "sources_table": "error"}}`
  - GIVEN database down WHEN health called THEN `{"status": "error", "checks": {"database": "error"}}`
- [x] Task complete

## Task 3: Endpoint Metrics — Total Count (REQ-PROM-007)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-007`
- **files**: `lib/Controller/MetricsController.php`
- **acceptance_criteria**:
  - GIVEN endpoints exist WHEN metrics collected THEN `openconnector_endpoints_total` equals COUNT from `openconnector_endpoints` table
  - GIVEN database error WHEN collecting endpoint count THEN zero-value fallback emitted with warning logged
  - Note: `openconnector_endpoint_hits_total` deferred — requires instrumentation in `EndpointService`; emit `# HELP` and `# TYPE` lines but no samples until instrumented
- Implement `collectEndpointMetrics()` private method in `MetricsController`; add `# HELP`, `# TYPE`, and value lines; wrap in try/catch with zero-value fallback; add to `index()` output
- [ ] Task complete

## Task 4: Job Queue Metrics (REQ-PROM-008)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-008`
- **files**: `lib/Controller/MetricsController.php`
- **acceptance_criteria**:
  - GIVEN 5 jobs configured WHEN metrics collected THEN `openconnector_jobs_total 5`
  - GIVEN job logs with status "success" (100) and "error" (10) WHEN metrics collected THEN `openconnector_job_runs_total{status="success"} 100` and `openconnector_job_runs_total{status="error"} 10`
  - GIVEN no jobs configured WHEN metrics collected THEN `openconnector_jobs_total 0`
  - GIVEN no job log entries WHEN metrics collected THEN `openconnector_job_runs_total{status="success"} 0` zero-value placeholder
- Implement `collectJobMetrics()` private method; COUNT(*) from `openconnector_jobs`; GROUP BY `status` from `openconnector_job_logs`; zero-value placeholders for missing status values; wrap in try/catch; add to `index()` output
- [ ] Task complete

## Task 5: Mapping and Rule Metrics (REQ-PROM-009)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-009`
- **files**: `lib/Controller/MetricsController.php`
- **acceptance_criteria**:
  - GIVEN 20 mappings and 8 rules WHEN metrics collected THEN `openconnector_mappings_total 20` and `openconnector_rules_total 8`
  - GIVEN a mapping is deleted WHEN next scrape runs THEN `openconnector_mappings_total` reflects decreased count
  - GIVEN database access fails for mappings WHEN metrics collected THEN zero-value fallback emitted with warning logged
- Implement `collectMappingRuleMetrics()` private method; COUNT(*) from `openconnector_mappings` and COUNT(*) from `openconnector_rules`; both wrapped in try/catch with independent fallbacks; add to `index()` output
- [ ] Task complete

## Task 6: Admin Authentication Enforcement (REQ-PROM-001, REQ-PROM-010)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-001`
- **files**: `lib/Controller/MetricsController.php`, `lib/Controller/HealthController.php`
- **acceptance_criteria**:
  - GIVEN an unauthenticated user WHEN requesting `/api/metrics` THEN HTTP 401 returned, no metrics exposed
  - GIVEN an unauthenticated user WHEN requesting `/api/health` THEN HTTP 401 returned
  - GIVEN authenticated admin WHEN requesting either endpoint THEN response proceeds normally
- Add `#[AuthorizedAdminSetting(Application::APP_ID)]` to `MetricsController::index()` and `HealthController::index()` per ADR-005 and ADR-016; keep `#[NoCSRFRequired]` for API token scraping; verify `hydra-gate-semantic-auth` passes
- [ ] Task complete

## Task 7: Health Check — Critical Source Reachability (REQ-PROM-010 scenario 5)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-010`
- **files**: `lib/Controller/HealthController.php`
- **acceptance_criteria**:
  - GIVEN a source marked as critical is unreachable WHEN health called THEN status is "degraded" with `{"source_reachability": {"source_name": "unreachable"}}`
  - GIVEN all critical sources reachable WHEN health called THEN `source_reachability` check passes and does not affect overall status
  - GIVEN no sources are marked critical WHEN health called THEN `source_reachability` check is omitted from response
- Implement `checkCriticalSources()` private method; query sources with a `is_critical` or equivalent flag; perform HEAD request per critical source using Guzzle (already available via `CallService`); timeout 3s; add results to checks array; status "degraded" if any unreachable
- [ ] Task complete

## Task 8: Unit Tests
- **spec_ref**: ADR-008, ADR-009
- **files**: `tests/Unit/Controller/MetricsControllerTest.php`, `tests/Unit/Controller/HealthControllerTest.php`
- **acceptance_criteria**:
  - Tests cover all metric collectors: info, up, sources, calls, sync, endpoints, jobs, mappings, rules
  - Tests verify zero-value fallback when database throws exception in each collector
  - Tests verify health check returns correct status for ok / degraded / error scenarios
  - Tests verify unauthenticated requests are rejected
- Write/extend unit tests for `MetricsController::collectEndpointMetrics()`, `collectJobMetrics()`, `collectMappingRuleMetrics()`; mock `IDBConnection` to throw exceptions and assert zero-value fallback output; test `HealthController::checkCriticalSources()`
- [ ] Task complete

## Task 9: Deduplication Check
- **spec_ref**: ADR-001 (Deduplication Check requirement)
- **files**: n/a (research only)
- **acceptance_criteria**:
  - Confirm no existing service in OpenConnector or OpenRegister provides Prometheus metric collection
  - Document findings in design.md Reuse Analysis section
- Search `lib/Service/`, `lib/Controller/`, and OpenRegister's `HeartbeatController` / `MetricsService` for any existing Prometheus/metrics infrastructure; confirm reuse decisions documented
- [x] Task complete

## Task 10: API Documentation
- **spec_ref**: ADR-009
- **files**: `docs/features/prometheus-metrics.md`
- **acceptance_criteria**:
  - Document both endpoints with URL, method, authentication, response format, and example output
  - Include Prometheus scrape configuration example (`scrape_configs` YAML)
  - Include Kubernetes liveness/readiness probe configuration example
- Write or update `docs/features/prometheus-metrics.md` with endpoint reference, scrape config example, and probe configuration example
- [ ] Task complete

## Verification

- [x] REQ-PROM-001 through REQ-PROM-006 implemented (MetricsController, HealthController core)
- [ ] REQ-PROM-007 implemented (endpoint total count)
- [ ] REQ-PROM-008 implemented (job queue metrics)
- [ ] REQ-PROM-009 implemented (mapping/rule metrics)
- [ ] REQ-PROM-010 fully implemented (including critical source reachability)
- [ ] Admin authentication enforced on both endpoints
- [ ] Unit tests written and passing
- [ ] Documentation updated
