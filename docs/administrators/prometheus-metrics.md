# Prometheus Metrics & Health Check

OpenConnector exposes Prometheus-compatible metrics and a health check endpoint for production monitoring.

## Endpoints

| Endpoint | Method | Auth | Format |
|---|---|---|---|
| `/api/metrics` | GET | Admin required | Prometheus text exposition |
| `/api/health` | GET | Admin required | JSON |

## Available Metrics

| Metric | Type | Labels | Description |
|---|---|---|---|
| `openconnector_info` | gauge | version, php_version, nextcloud_version | Application version info (always 1) |
| `openconnector_up` | gauge | — | 1 if healthy, 0 if degraded |
| `openconnector_sources_total` | gauge | type | Source count by type (rest, soap, json, xml, etc.) |
| `openconnector_calls_total` | counter | status | API call count by HTTP status code |
| `openconnector_synchronizations_total` | gauge | — | Total configured synchronizations |
| `openconnector_synchronization_runs_total` | counter | status | Sync log entries by result |
| `openconnector_endpoints_total` | gauge | — | Total registered endpoints |
| `openconnector_jobs_total` | gauge | — | Total configured background jobs |
| `openconnector_job_runs_total` | counter | status | Job log entries by status |
| `openconnector_mappings_total` | gauge | — | Total configured mappings |
| `openconnector_rules_total` | gauge | — | Total configured rules |

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

The health endpoint returns JSON:

```json
{
  "status": "ok",
  "checks": {
    "database": "ok",
    "sources_table": "ok"
  }
}
```

**Status values:**
- `ok` — All checks passed
- `degraded` — Some checks failed (app works but with limitations)
- `error` — Critical check failed (database unreachable)

Use for Kubernetes liveness/readiness probes:

```yaml
livenessProbe:
  httpGet:
    path: /index.php/apps/openconnector/api/health
    port: 80
    httpHeaders:
      - name: Authorization
        value: Basic YWRtaW46YWRtaW4=
  initialDelaySeconds: 30
  periodSeconds: 60
```
