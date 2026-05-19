# Prometheus Metrics & Health Check

## Overview

OpenConnector exposes application metrics in Prometheus text exposition format and a JSON health check endpoint for container orchestration environments.

## Endpoints

### GET /api/metrics

Returns metrics in Prometheus text exposition format (`text/plain; version=0.0.4; charset=utf-8`).

**Authentication:** Requires Nextcloud admin session or API token.

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
| `openconnector_info` | gauge | version, php_version, nextcloud_version | Application version info (always 1) |
| `openconnector_up` | gauge | - | Application health (1=healthy, 0=degraded) |
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

**Authentication:** Requires Nextcloud admin session or API token.

**Example response (healthy):**

```json
{
  "status": "ok",
  "checks": {
    "database": "ok",
    "sources_table": "ok"
  }
}
```

**Example response (degraded):**

```json
{
  "status": "degraded",
  "checks": {
    "database": "ok",
    "sources_table": "error"
  }
}
```

**Status values:**
- `ok` -- all checks pass
- `degraded` -- application works but some components are unavailable
- `error` -- critical failure (e.g., database inaccessible)

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

```yaml
livenessProbe:
  httpGet:
    path: /index.php/apps/openconnector/api/health
    port: 80
    httpHeaders:
      - name: Authorization
        value: "Basic <base64-encoded-credentials>"
  initialDelaySeconds: 30
  periodSeconds: 60
readinessProbe:
  httpGet:
    path: /index.php/apps/openconnector/api/health
    port: 80
    httpHeaders:
      - name: Authorization
        value: "Basic <base64-encoded-credentials>"
  initialDelaySeconds: 10
  periodSeconds: 15
```

## Error Handling

All metric collectors use independent try/catch blocks. If one collector fails (e.g., a table does not exist), it emits a zero-value fallback and the endpoint still returns HTTP 200 with the remaining metrics. This ensures partial availability under degraded conditions.

## Implementation

- **MetricsController**: `lib/Controller/MetricsController.php`
- **HealthController**: `lib/Controller/HealthController.php`
- **Routes**: `appinfo/routes.php` (lines 19-20)
- **Tests**: `tests/Unit/Controller/MetricsControllerTest.php`, `tests/Unit/Controller/HealthControllerTest.php`
