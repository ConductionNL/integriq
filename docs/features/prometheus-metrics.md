# Prometheus Metrics & Health Check

## Overview

Integriq exposes application metrics in Prometheus text exposition format and a JSON health check endpoint for container orchestration environments, served through the shared OpenRegister AppHost declarative observability engine (ADR-040) instead of a hand-written controller. The engine renders both endpoints from the `observability` block in `src/manifest.json` — that block is the source of truth for the exact check/metric contract; this page documents it for operators.

## Endpoints

### GET /api/metrics

Returns metrics in Prometheus text exposition format (`text/plain; version=0.0.4; charset=utf-8`).

**Authentication:** Requires Nextcloud admin session or API token (unchanged — metrics stay admin-only).

**Example response:**

```
# HELP integriq_info Application information
# TYPE integriq_info gauge
integriq_info{version="2.1.0",php_version="8.3.0",nextcloud_version="30.0.0"} 1
# HELP integriq_up Whether the application is up
# TYPE integriq_up gauge
integriq_up 1
# HELP integriq_sources_total Total sources by type
# TYPE integriq_sources_total gauge
integriq_sources_total{type="json"} 5
integriq_sources_total{type="soap"} 2
# HELP integriq_calls_total Total API calls by status
# TYPE integriq_calls_total counter
integriq_calls_total{status="200"} 150
integriq_calls_total{status="400"} 30
# HELP integriq_synchronizations_total Total synchronization runs
# TYPE integriq_synchronizations_total gauge
integriq_synchronizations_total 10
# HELP integriq_synchronization_runs_total Total synchronization log entries by result
# TYPE integriq_synchronization_runs_total counter
integriq_synchronization_runs_total{status="success"} 400
# HELP integriq_endpoints_total Total registered endpoints
# TYPE integriq_endpoints_total gauge
integriq_endpoints_total 15
# HELP integriq_jobs_total Total configured jobs
# TYPE integriq_jobs_total gauge
integriq_jobs_total 5
# HELP integriq_job_runs_total Total job log entries by status
# TYPE integriq_job_runs_total counter
integriq_job_runs_total{status="success"} 100
# HELP integriq_mappings_total Total configured mappings
# TYPE integriq_mappings_total gauge
integriq_mappings_total 20
# HELP integriq_rules_total Total configured rules
# TYPE integriq_rules_total gauge
integriq_rules_total 8
```

### Available Metrics

| Metric | Type | Labels | Description |
|--------|------|--------|-------------|
| `integriq_info` | gauge | version, php_version, nextcloud_version | Application version info (always 1) — engine-implicit |
| `integriq_up` | gauge | - | Application health (1=healthy, 0=degraded) — engine-implicit |
| `integriq_sources_total` | gauge | type | Sources grouped by type |
| `integriq_calls_total` | counter | status | API calls grouped by HTTP status code |
| `integriq_synchronizations_total` | gauge | - | Total configured synchronizations |
| `integriq_synchronization_runs_total` | counter | status | Sync log entries grouped by result |
| `integriq_endpoints_total` | gauge | - | Total registered endpoints |
| `integriq_jobs_total` | gauge | - | Total configured jobs |
| `integriq_job_runs_total` | counter | status | Job log entries grouped by status |
| `integriq_mappings_total` | gauge | - | Total configured mappings |
| `integriq_rules_total` | gauge | - | Total configured rules |

> **Renamed in the Integriq release.** `src/manifest.json` declares metric names
> as suffixes only (`sources_total`, `calls_total`, …); the AppHost
> `PrometheusRenderer` prepends a prefix **derived from the app id**. So the whole
> family moved from `openconnector_*` to `integriq_*` when the app id moved, and
> this cannot be held back at code level. **Update existing Grafana dashboards,
> recording rules and alert rules** to the new names before upgrading, or bridge
> the gap with a recording rule while you migrate.

### GET /api/health

Returns JSON health status for liveness/readiness probes.

**Authentication:** Public — no session or token required (ADR-006; fixed a pre-adoption defect where this endpoint was wrongly admin-only).

Two checks are declared: `database` (critical) and `openregister` (degraded-only — replaces the pre-adoption bespoke `sources_table` join probe with a generic OpenRegister-availability check; same degraded semantics).

**Example response (healthy):**

```json
{
  "status": "ok",
  "app": "integriq",
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
  "app": "integriq",
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
  "app": "integriq",
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
  - job_name: 'integriq'
    scrape_interval: 30s
    scheme: http
    basic_auth:
      username: admin
      password: <nextcloud-admin-password>
    metrics_path: /index.php/apps/integriq/api/metrics
    static_configs:
      - targets: ['nextcloud:80']
```

## Kubernetes Health Probes

Health is public, so no `Authorization` header is needed. A liveness/readiness probe should treat HTTP 503 as unhealthy (the ADR-006 policy):

```yaml
livenessProbe:
  httpGet:
    path: /index.php/apps/integriq/api/health
    port: 80
  initialDelaySeconds: 30
  periodSeconds: 60
readinessProbe:
  httpGet:
    path: /index.php/apps/integriq/api/health
    port: 80
  initialDelaySeconds: 10
  periodSeconds: 15
```

## Error Handling

Each `tableCount` metric descriptor is evaluated independently by the engine. If a source table does not exist (e.g. on an instance where the chain-C legacy-table drop migration has already run), that metric emits a zero-value sample and the endpoint still returns HTTP 200 with the remaining metrics — mirroring the pre-adoption per-collector try/catch fallback. This ensures partial availability under degraded conditions. The health endpoint is the one place a failure changes the HTTP status: a failed `database` (critical) check returns 503 per ADR-006.

## Implementation

- **Manifest**: `src/manifest.json` — `observability.health.checks[]` + `observability.metrics[]`, the source of truth for both endpoints.
- **Engine**: OpenRegister's `AppHost\Observability\*` (`ManifestLoader`, `HealthCheckExecutor`, `MetricsEngine`) and `AppHost\Controller\GenericHealthController`/`GenericMetricsController`.
- **Thin app-namespace adapters**: `lib/Controller/HealthController.php`, `lib/Controller/MetricsController.php` — resolve the engine collaborators scoped to `appName=integriq` and carry the route-level auth posture (`#[PublicPage]` on health, admin-only on metrics); no metric/health logic of their own.
- **Wiring**: `lib/AppInfo/Application.php::registerAppHostObservability()`.
- **Routes**: `appinfo/routes.php` (`health#index`, `metrics#index` — URLs unchanged).
- **Spec**: `openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md`.
- **Contract tests**: `tests/integration/integriq.postman_collection.json` (folder "11. Observability (AppHost health/metrics)"), run via `tests/integration/run-newman.sh`.
