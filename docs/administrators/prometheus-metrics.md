# Prometheus Metrics & Health Check

OpenConnector exposes Prometheus-compatible metrics and a health check endpoint for production monitoring, served by the shared OpenRegister AppHost observability engine (ADR-040) rather than hand-written controllers. The engine reads the `observability` block declared in `src/manifest.json`, which is the source of truth for every check and metric descriptor listed below — see `openspec/changes/adopt-apphost/specs/apphost-adoption/spec.md`.

## Endpoints

| Endpoint | Method | Auth | Format |
|---|---|---|---|
| `/api/metrics` | GET | Admin required | Prometheus text exposition |
| `/api/health` | GET | **Public** (no session required) | JSON |

Health is intentionally public per ADR-006 (uptime/liveness probes must not need credentials); metrics stay admin-only since they can reveal operational volume.

## Available Metrics

| Metric | Type | Labels | Description |
|---|---|---|---|
| `openconnector_info` | gauge | version, php_version, nextcloud_version | Application version info (always 1) — engine-implicit, not declared in the manifest |
| `openconnector_up` | gauge | — | 1 if healthy, 0 if degraded — engine-implicit, not declared in the manifest |
| `openconnector_sources_total` | gauge | type | Source count by type (rest, soap, json, xml, etc.) |
| `openconnector_calls_total` | counter | status | API call count by HTTP status code |
| `openconnector_synchronizations_total` | gauge | — | Total configured synchronizations |
| `openconnector_synchronization_runs_total` | counter | status | Sync log entries by result |
| `openconnector_endpoints_total` | gauge | — | Total registered endpoints |
| `openconnector_jobs_total` | gauge | — | Total configured background jobs |
| `openconnector_job_runs_total` | counter | status | Job log entries by status |
| `openconnector_mappings_total` | gauge | — | Total configured mappings |
| `openconnector_rules_total` | gauge | — | Total configured rules |

The 9 counted metrics are currently `tableCount` descriptors reading the legacy `openconnector_*` tables directly (pre-OR-cutover). Once openconnector finishes migrating that domain data to OpenRegister objects, each descriptor flips from `tableCount` to `objectCount` with a one-line `src/manifest.json` edit — no controller changes. On an instance where a legacy table has already been dropped, the affected metric still emits a `0` sample rather than erroring.

## Prometheus Configuration

```yaml
scrape_configs:
  - job_name: 'openconnector'
    scrape_interval: 30s
    scheme: http
    basic_auth:
      username: admin
      password: your-password
    metrics_path: /index.php/apps/openconnector/api/metrics
    static_configs:
      - targets: ['your-nextcloud-host:8080']
```

## Health Check

The health endpoint is public (no `Authorization` header required) and returns JSON with two declared checks — `database` (critical) and `openregister` (degraded-only, checking OpenRegister's `ObjectService` availability):

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

**Status values and HTTP codes (ADR-006 `adr006` status-code policy):**
- `ok` — HTTP 200. All checks passed.
- `degraded` — HTTP 200. A non-critical check (`openregister`) failed; the app still works with limitations.
- `error` — **HTTP 503.** The critical `database` check failed.

A failed check reports `"failed: <reason>"` instead of `"ok"` under its `checks` key.

Use for Kubernetes liveness/readiness probes — no credentials needed:

```yaml
livenessProbe:
  httpGet:
    path: /index.php/apps/openconnector/api/health
    port: 80
  initialDelaySeconds: 30
  periodSeconds: 60
```
