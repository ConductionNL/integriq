# Tasks: prometheus-metrics

## Task 1: Core Metrics Controller (REQ-PROM-001 through REQ-PROM-006)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-001` through `#req-prom-006`
- **files**: `lib/Controller/MetricsController.php`
- **acceptance_criteria**:
  - GIVEN an admin user WHEN requesting GET /api/metrics THEN response is Prometheus text format
  - GIVEN database has sources, calls, syncs WHEN metrics are collected THEN each is grouped and counted
  - GIVEN a database error WHEN a collector fails THEN zero-value fallback is emitted
- [x] Implement
- [x] Test

## Task 2: Health Check Controller (REQ-PROM-010)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-010`
- **files**: `lib/Controller/HealthController.php`
- **acceptance_criteria**:
  - GIVEN database accessible WHEN health endpoint called THEN status is "ok"
  - GIVEN database down WHEN health endpoint called THEN status is "error"
- [x] Implement
- [x] Test

## Task 3: Endpoint Metrics (REQ-PROM-007)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-007`
- **files**: `lib/Controller/MetricsController.php`
- **acceptance_criteria**:
  - GIVEN endpoints exist WHEN metrics collected THEN openconnector_endpoints_total shows count
  - Note: endpoint_hits_total deferred -- requires EndpointService instrumentation
- [x] Implement (total count only)
- [x] Test

## Task 4: Job Queue Metrics (REQ-PROM-008)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-008`
- **files**: `lib/Controller/MetricsController.php`
- **acceptance_criteria**:
  - GIVEN jobs configured WHEN metrics collected THEN openconnector_jobs_total shows count
  - GIVEN job logs exist WHEN metrics collected THEN openconnector_job_runs_total grouped by status
- [x] Implement
- [x] Test

## Task 5: Mapping and Rule Metrics (REQ-PROM-009)
- **spec_ref**: `specs/prometheus-metrics/spec.md#req-prom-009`
- **files**: `lib/Controller/MetricsController.php`
- **acceptance_criteria**:
  - GIVEN mappings and rules exist WHEN metrics collected THEN totals are emitted
  - GIVEN database error WHEN counting THEN zero-value fallback emitted
- [x] Implement
- [x] Test

## Task 6: Unit Tests
- **spec_ref**: ADR-009
- **files**: `tests/Unit/Controller/MetricsControllerTest.php`, `tests/Unit/Controller/HealthControllerTest.php`
- **acceptance_criteria**:
  - Tests cover info, up, source, call, sync, endpoint, job, mapping/rule metrics
  - Tests verify zero-value fallback on database errors
- [x] Implement

## Task 7: API Documentation
- **spec_ref**: ADR-010
- **files**: `docs/features/prometheus-metrics.md`
- [x] Implement

## Verification
- [x] All tasks checked off
- [x] MetricsController exposes all required metrics
- [x] HealthController returns proper status
- [x] Unit tests written
- [x] Documentation written
