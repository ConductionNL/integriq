# Prometheus Metrics & Health Check

## Overview

OpenConnector exposes application metrics in Prometheus text exposition format and a JSON health check endpoint for container orchestration environments, served through the shared OpenRegister AppHost declarative observability engine (ADR-040) instead of a hand-written controller. The engine renders both endpoints from the `observability` block in `src/manifest.json` — that block is the source of truth for the exact check/metric contract; this page documents it for operators.

## Endpoints

### GET /api/metrics

Returns metrics in Prometheus text exposition format (`text/plain; version=0.0.4; charset=utf-8`).

**Authentication:** Requires Nextcloud admin session or API token (unchanged — metrics stay admin-only).

**Example response:**

```
# HELP openconnector_info Application information
# TYPE openconnector_info gauge
openconnector_info{version="2.1.0",php_version="8.3.0",nextcloud_version="30.0.0"} 1
# HELP openconnector_up Whether the application is up
# TYPE openconnector_up gauge
openconnector_up 1
# HELP openconnector_sources_total Total sources by type
# TYPE openconnector_sources_total gauge
openconnector_sources_total{type="json"} 5
openconnector_sources_total{type="soap"} 2
# HELP openconnector_calls_total Total API calls by status
# TYPE openconnector_calls_total counter
openconnector_calls_total{status="200"} 150
openconnector_calls_total{status="400"} 30
# HELP openconnector_synchronizations_total Total synchronization runs
# TYPE openconnector_synchronizations_total gauge
openconnector_synchronizations_total 10
# HELP openconnector_synchronization_runs_total Total synchronization log entries by result
# TYPE openconnector_synchronization_runs_total counter
openconnector_synchronization_runs_total{status="success"} 400
# HELP openconnector_endpoints_total Total registered endpoints
# TYPE openconnector_endpoints_total gauge
openconnector_endpoints_total 15
# HELP openconnector_jobs_total Total configured jobs
# TYPE openconnector_jobs_total gauge
openconnector_jobs_total 5
# HELP openconnector_job_runs_total Total job log entries by status
# TYPE openconnector_job_runs_total counter
openconnector_job_runs_total{status="success"} 100
# HELP openconnector_mappings_total Total configured mappings
# TYPE openconnector_mappings_total gauge
openconnector_mappings_total 20
# HELP openconnector_rules_total Total configured rules
# TYPE openconnector_rules_total gauge
openconnector_rules_total 8
```

### Available Metrics

| Metric | Type | Labels | Description |
|--------|------|--------|-------------|
| `openconnector_info` | gauge | version, php_version, nextcloud_version | Application version info (always 1) — engine-implicit |
| `openconnector_up` | gauge | - | Application health (1=healthy, 0=degraded) — engine-implicit |
| `openconnector_sources_total` | gauge | type | Sources grouped by type |
| `openconnector_calls_total` | counter | status | API calls grouped by HTTP status code |
| `openconnector_synchronizations_total` | gauge | - | Total configured synchronizations |
| `openconnector_synchronization_runs_total` | counter | status | Sync log entries grouped by result |
| `openconnector_endpoints_total` | gauge | - | Total registered endpoints |
| `openconnector_jobs_total` | gauge | - | Total configured jobs |
| `openconnector_job_runs_total` | counter | status | Job log entries grouped by status |
| `openconnector_mappings_total` | gauge | - | Total configured mappings |
| `openconnector_rules_total` | gauge | - | Total configured rules |

### GET /api/health

Returns JSON health status for liveness/readiness probes.

**Authentication:** Public — no session or token required (ADR-006; fixed a pre-adoption defect where this endpoint was wrongly admin-only).

Two checks are declared: `database` (critical) and `openregister` (degraded-only — replaces the pre-adoption bespoke `sources_table` join probe with a generic OpenRegister-availability check; same degraded semantics).

**Example response (healthy):**

```json
{
  "status": "ok",
  "app": "openconnector",
  "version": "0.2.21",
  "checks": {
    "database": "ok",
    "openregister": "ok"
  }
}
```

**Example response (degraded — HTTP 200):**

```json
{
  "status": "degraded",
  "app": "openconnector",
  "version": "0.2.21",
  "checks": {
    "database": "ok",
    "openregister": "failed: OpenRegister ObjectService unavailable"
  }
}
```

**Example response (error — HTTP 503):**

```json
{
  "status": "error",
  "app": "openconnector",
  "version": "0.2.21",
  "checks": {
    "database": "failed: could not connect",
    "openregister": "ok"
  }
}
```

**Status values (ADR-006 `adr006` status-code policy):**
- `ok` -- HTTP 200. All checks pass.
- `degraded` -- HTTP 200. A non-critical check failed; application works but some components are unavailable.
- `error` -- **HTTP 503.** The critical `database` check failed (pre-adoption this wrongly returned HTTP 200 — fixed by the engine).

## Prometheus Configuration

Add to your `prometheus.yml`:

```yaml
scrape_configs:
  - job_name: 'openconnector'
    scrape_interval: 30s
    scheme: http
    basic_auth:
      username: admin
      password: <nextcloud-admin-password>
    metrics_path: /index.php/apps/openconnector/api/metrics
    static_configs:
      - targets: ['nextcloud:80']
```

## Kubernetes Health Probes

Health is public, so no `Authorization` header is needed. A liveness/readiness probe should treat HTTP 503 as unhealthy (the ADR-006 policy):

```yaml
livenessProbe:
  httpGet:
    path: /index.php/apps/openconnector/api/health
    port: 80
  initialDelaySeconds: 30
  periodSeconds: 60
readinessProbe:
  httpGet:
    path: /index.php/apps/openconnector/api/health
    port: 80
  initialDelaySeconds: 10
  periodSeconds: 15
```

## Error Handling

Each `tableCount` metric descriptor is evaluated independently by the engine. If a source table does not exist (e.g. on an instance where the chain-C legacy-table drop migration has already run), that metric emits a zero-value sample and the endpoint still returns HTTP 200 with the remaining metrics — mirroring the pre-adoption per-collector try/catch fallback. This ensures partial availability under degraded conditions. The health endpoint is the one place a failure changes the HTTP status: a failed `database` (critical) check returns 503 per ADR-006.

## Implementation

- **Manifest**: `src/manifest.json` — `observability.health.checks[]` + `observability.metrics[]`, the source of truth for both endpoints.
- **Engine**: OpenRegister's `AppHost\Observability\*` (`ManifestLoader`, `HealthCheckExecutor`, `MetricsEngine`) and `AppHost\Controller\GenericHealthController`/`GenericMetricsController`.
- **Thin app-namespace adapters**: `lib/Controller/HealthController.php`, `lib/Controller/MetricsController.php` — resolve the engine collaborators scoped to `appName=openconnector` and carry the route-level auth posture (`#[PublicPage]` on health, admin-only on metrics); no metric/health logic of their own.
- **Wiring**: `lib/AppInfo/Application.php::registerAppHostObservability()`.
- **Routes**: `appinfo/routes.php` (`health#index`, `metrics#index` — URLs unchanged).
- **Spec**: `openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md`.
- **Contract tests**: `tests/integration/openconnector.postman_collection.json` (folder "11. Observability (AppHost health/metrics)"), run via `tests/integration/run-newman.sh`.
