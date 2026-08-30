---
status: implemented
---

# Prometheus Metrics Endpoint

## Purpose

Expose application metrics in Prometheus text exposition format at `GET /api/metrics` for monitoring, alerting, and operational dashboards. Provide a health check endpoint at `GET /api/health` for liveness/readiness probes in container orchestration environments.

## Requirements

### REQ-PROM-001: Metrics Endpoint

The app MUST expose `GET /index.php/apps/openconnector/api/metrics` returning `text/plain; version=0.0.4; charset=utf-8`. The endpoint MUST require admin authentication (Nextcloud admin session or API token). All metrics MUST follow the Prometheus text exposition format with `# HELP`, `# TYPE`, and metric lines.

**Scenarios:**

1. **GIVEN** an authenticated Nextcloud admin user **WHEN** they request `GET /index.php/apps/openconnector/api/metrics` **THEN** the response has status 200, content-type `text/plain; version=0.0.4; charset=utf-8`, and the body contains valid Prometheus exposition format lines.

2. **GIVEN** an unauthenticated user **WHEN** they request the metrics endpoint **THEN** the response is HTTP 401 Unauthorized and no metrics data is exposed.

3. **GIVEN** a monitoring system (e.g., Prometheus scraper) with a valid API token **WHEN** it scrapes the metrics endpoint at its configured interval **THEN** fresh metrics are returned reflecting current application state, not cached values.

4. **GIVEN** the metrics endpoint is called **AND** a database query for one metric category fails **WHEN** the remaining metric categories succeed **THEN** the failing metric emits a zero-value fallback and the endpoint still returns HTTP 200 with partial metrics (degraded but not broken).

5. **GIVEN** the metrics endpoint is called frequently (every 15 seconds) **WHEN** each scrape runs the database queries **THEN** query execution completes within 500ms using indexed COUNT queries on the existing OpenConnector tables.

### REQ-PROM-002: Application Info Gauge

The app MUST expose an `openconnector_info` gauge metric with labels `version` (app version), `php_version`, and `nextcloud_version`. The value is always 1. This enables Prometheus queries like `openconnector_info{version="2.1.0"}` to track which version is deployed.

**Scenarios:**

1. **GIVEN** OpenConnector version 2.1.0 is installed on Nextcloud 30.0.0 running PHP 8.3.0 **WHEN** the metrics endpoint is called **THEN** the output includes `openconnector_info{version="2.1.0",php_version="8.3.0",nextcloud_version="30.0.0"} 1`.

2. **GIVEN** the app is upgraded from 2.1.0 to 2.2.0 **WHEN** the metrics endpoint is called after upgrade **THEN** the version label reflects "2.2.0" on the next scrape.

3. **GIVEN** the app version cannot be determined **WHEN** the metrics endpoint is called **THEN** the version label defaults to "0.0.0" rather than omitting the metric.

### REQ-PROM-003: Application Up Gauge

The app MUST expose an `openconnector_up` gauge metric. The value is 1 if the app is healthy (database accessible, core tables exist), 0 if degraded (database errors, missing tables).

**Scenarios:**

1. **GIVEN** the application is running normally with database connectivity **WHEN** the metrics endpoint is called **THEN** `openconnector_up` is 1.

2. **GIVEN** the database connection is lost **WHEN** the metrics endpoint is called **THEN** `openconnector_up` is 0 (the endpoint itself may still respond if the framework can serve the request).

3. **GIVEN** the sources table is missing (migration not run) **WHEN** the metrics endpoint is called **THEN** `openconnector_up` is 0 and the health check details explain the missing table.

### REQ-PROM-004: Sources Gauge by Type

The app MUST expose `openconnector_sources_total` as a gauge with label `type` (rest/soap/graphql/json/xml/ftp/sftp). The value is the current count of configured sources per type, queried from the `openconnector_sources` table grouped by `type` column.

**Scenarios:**

1. **GIVEN** there are 5 sources of type "json", 2 of type "soap", and 1 of type "xml" **WHEN** the metrics endpoint is called **THEN** the output includes `openconnector_sources_total{type="json"} 5`, `openconnector_sources_total{type="soap"} 2`, and `openconnector_sources_total{type="xml"} 1`.

2. **GIVEN** no sources are configured **WHEN** the metrics endpoint is called **THEN** the output includes `openconnector_sources_total{type="rest"} 0` as a zero-value placeholder.

3. **GIVEN** a source has a NULL type value in the database **WHEN** the metrics endpoint is called **THEN** it is counted under the default label "rest" (existing MetricsController behavior).

### REQ-PROM-005: Call Counter by Status

The app MUST expose `openconnector_calls_total` as a counter with label `status` (HTTP status code). The value is the total number of API calls logged in the `openconnector_call_logs` table, grouped by `status_code`. This enables monitoring of error rates and API call volumes.

**Scenarios:**

1. **GIVEN** 150 calls with status 200, 30 calls with status 400, and 5 calls with status 500 are logged **WHEN** the metrics endpoint is called **THEN** the output includes `openconnector_calls_total{status="200"} 150`, `openconnector_calls_total{status="400"} 30`, and `openconnector_calls_total{status="500"} 5`.

2. **GIVEN** no calls have been logged **WHEN** the metrics endpoint is called **THEN** the output includes `openconnector_calls_total{status="200"} 0` as a zero-value placeholder.

3. **GIVEN** a new call is logged with status 429 (rate limited) **WHEN** the next metrics scrape runs **THEN** `openconnector_calls_total{status="429"}` appears with count 1.

### REQ-PROM-006: Synchronization Metrics

The app MUST expose synchronization metrics: `openconnector_synchronizations_total` (gauge, total configured synchronizations) and `openconnector_synchronization_runs_total` (counter with label `status`, total sync log entries grouped by result). These enable monitoring of sync health and failure rates.

**Scenarios:**

1. **GIVEN** 10 synchronizations are configured **AND** 500 sync log entries exist (400 success, 80 partial, 20 error) **WHEN** the metrics endpoint is called **THEN** the output includes `openconnector_synchronizations_total 10`, `openconnector_synchronization_runs_total{status="success"} 400`, `openconnector_synchronization_runs_total{status="partial"} 80`, and `openconnector_synchronization_runs_total{status="error"} 20`.

2. **GIVEN** no sync log entries exist **WHEN** the metrics endpoint is called **THEN** the output includes `openconnector_synchronization_runs_total{status="success"} 0` as a zero-value placeholder.

3. **GIVEN** a sync run fails due to a source being disabled **WHEN** the sync log records the failure **THEN** the next scrape increments `openconnector_synchronization_runs_total{status="error"}`.

### REQ-PROM-007: Endpoint Metrics

The app MUST expose `openconnector_endpoints_total` (gauge) counting the total number of registered endpoints, and `openconnector_endpoint_hits_total` (counter with labels `endpoint`, `method`) tracking request counts per endpoint. This enables monitoring of which endpoints are most active.

**Scenarios:**

1. **GIVEN** 15 endpoints are registered **AND** endpoint "/api/objects" has received 200 GET and 50 POST requests **WHEN** the metrics endpoint is called **THEN** the output includes `openconnector_endpoints_total 15`, `openconnector_endpoint_hits_total{endpoint="/api/objects",method="GET"} 200`, and `openconnector_endpoint_hits_total{endpoint="/api/objects",method="POST"} 50`.

2. **GIVEN** an endpoint is created but never called **WHEN** the metrics endpoint is called **THEN** it appears in `openconnector_endpoints_total` but not in `openconnector_endpoint_hits_total` (no zero-value emission per endpoint).

3. **GIVEN** the endpoint metrics query would return more than 100 distinct endpoint/method combinations **WHEN** the metrics endpoint is called **THEN** results are limited to the top 100 by hit count to prevent metric cardinality explosion.

### REQ-PROM-008: Job Queue Metrics

The app MUST expose `openconnector_jobs_total` (gauge) counting configured jobs, and `openconnector_job_runs_total` (counter with label `status`) counting job execution log entries. This enables monitoring of background job health.

**Scenarios:**

1. **GIVEN** 5 jobs are configured **AND** job logs show 100 success runs and 10 error runs **WHEN** the metrics endpoint is called **THEN** the output includes `openconnector_jobs_total 5`, `openconnector_job_runs_total{status="success"} 100`, and `openconnector_job_runs_total{status="error"} 10`.

2. **GIVEN** a job has been stuck (no recent runs) for over 1 hour **WHEN** the metrics endpoint is called **THEN** the job appears in `openconnector_jobs_total` but its last run timestamp is available via the health check for alerting.

3. **GIVEN** no jobs are configured **WHEN** the metrics endpoint is called **THEN** `openconnector_jobs_total 0` is emitted.

### REQ-PROM-009: Mapping and Rule Metrics

The app MUST expose `openconnector_mappings_total` (gauge) and `openconnector_rules_total` (gauge) counting configured mappings and rules respectively. These are lightweight counters providing operational overview.

**Scenarios:**

1. **GIVEN** 20 mappings and 8 rules are configured **WHEN** the metrics endpoint is called **THEN** the output includes `openconnector_mappings_total 20` and `openconnector_rules_total 8`.

2. **GIVEN** a mapping is deleted **WHEN** the next metrics scrape runs **THEN** `openconnector_mappings_total` reflects the decreased count.

3. **GIVEN** database access fails for the mapping count **WHEN** the metrics endpoint collects this metric **THEN** a zero-value fallback is emitted with a warning logged.

### REQ-PROM-010: Health Check Endpoint

The app MUST expose `GET /index.php/apps/openconnector/api/health` returning JSON `{"status": "ok"|"degraded"|"error", "checks": {...}}`. Checks include: database connectivity (SELECT 1), source table accessibility (COUNT from sources table), and optionally source endpoint reachability for critical sources. The health endpoint requires admin authentication.

**Scenarios:**

1. **GIVEN** the database is accessible and the sources table exists **WHEN** the health endpoint is called **THEN** the response is `{"status": "ok", "checks": {"database": "ok", "sources_table": "ok"}}`.

2. **GIVEN** the database is accessible but the sources table is missing **WHEN** the health endpoint is called **THEN** the response is `{"status": "degraded", "checks": {"database": "ok", "sources_table": "error"}}`.

3. **GIVEN** the database connection fails entirely **WHEN** the health endpoint is called **THEN** the response is `{"status": "error", "checks": {"database": "error"}}`.

4. **GIVEN** a Kubernetes readiness probe is configured to use the health endpoint **WHEN** the status is "error" **THEN** Kubernetes marks the pod as not ready and stops routing traffic to it.

5. **GIVEN** the health check includes a critical source reachability check **AND** the source is unreachable **WHEN** the health endpoint is called **THEN** status is "degraded" (not "error", since the app itself works) with `{"source_reachability": {"source_name": "unreachable"}}`.

## Data Model

No new data model entities are required. Metrics are computed at query time from existing OpenConnector tables:
- `openconnector_sources` (type column for source counts)
- `openconnector_call_logs` (status_code column for call counts)
- `openconnector_synchronizations` (total count)
- `openconnector_synchronization_logs` (result column for sync run counts)
- `openconnector_endpoints` (total count)
- `openconnector_jobs` (total count)
- `openconnector_job_logs` (status for job run counts)
- `openconnector_mappings` (total count)
- `openconnector_rules` (total count)

## Current Implementation Status

### Implemented
- **MetricsController** (`lib/Controller/MetricsController.php`): Fully implemented with `index()` method returning Prometheus text format. Exposes `openconnector_info`, `openconnector_up`, `openconnector_sources_total` (by type), `openconnector_calls_total` (by status), `openconnector_synchronizations_total`, and `openconnector_synchronization_runs_total` (by status). Uses IDBConnection query builder for all database queries with proper error handling and zero-value fallbacks.
- **HealthController** (`lib/Controller/HealthController.php`): Fully implemented with `index()` method returning JSON health status. Checks database connectivity (SELECT 1) and sources table accessibility (COUNT from sources). Returns `{"status": "ok"|"degraded"|"error", "checks": {...}}`.
- **Route registration**: Both endpoints are registered and accessible at their respective paths.

### Not implemented
- **Endpoint metrics** (REQ-PROM-007): No endpoint hit tracking. Would require adding a counter mechanism to EndpointService.
- **Job queue metrics** (REQ-PROM-008): No job run counting from job_logs table.
- **Mapping/rule metrics** (REQ-PROM-009): No mapping or rule count metrics.
- **Request duration histogram** (from original spec): No latency tracking -- would require middleware or CallService instrumentation.
- **Critical source reachability** in health check: Only database and table checks are implemented.
- **Admin authentication enforcement**: The `@NoCSRFRequired` annotation is present but explicit admin-only access control is not enforced beyond standard Nextcloud route authentication.

## Standards & References

- **Prometheus text exposition format**: https://prometheus.io/docs/instrumenting/exposition_formats/
- **OpenMetrics specification**: https://openmetrics.io/
- **Nextcloud server monitoring patterns**: Nextcloud's own `status.php` and OCS monitoring endpoints.
- **OpenRegister MetricsService and HeartbeatController**: Reference implementation in the sibling app OpenRegister.

## Specificity Assessment

Highly specific -- metric names, types, and labels are fully defined. The core implementation already exists in MetricsController and HealthController. Remaining work is incremental: adding endpoint/job/mapping counters and optionally request duration histograms.
