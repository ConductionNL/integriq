# Logging and Monitoring

## Overview

OpenConnector provides comprehensive logging for all outbound HTTP calls, synchronization runs, and job executions. Logs are queryable via the REST API and visible in the OpenConnector UI. Prometheus metrics and a JSON health endpoint are available for integration with monitoring stacks.

## Call Logging

Every HTTP request made through `CallService` to an external source is recorded in a **CallLog** entry when logging is enabled on the source.

### CallLog Fields

| Field | Description |
|-------|-------------|
| `sourceId` | The Source that was called |
| `synchronizationId` | Associated synchronization (if applicable) |
| `jobId` | Associated job (if applicable) |
| `requestMethod` | HTTP method (`GET`, `POST`, etc.) |
| `requestUrl` | Full URL including query parameters |
| `requestHeaders` | Headers sent (sensitive values redacted) |
| `requestBody` | Request body |
| `responseStatusCode` | HTTP status code |
| `responseHeaders` | Response headers |
| `responseBody` | Response body |
| `executionTime` | Duration in milliseconds |
| `created` | Timestamp |

### Accessing Call Logs

```
GET /index.php/apps/openconnector/api/logs
GET /index.php/apps/openconnector/api/logs?sourceId={id}
GET /index.php/apps/openconnector/api/logs?synchronizationId={id}
```

## Synchronization Logging

Each synchronization run writes a **SynchronizationLog** entry summarizing the outcome.

### SynchronizationLog Fields

| Field | Description |
|-------|-------------|
| `synchronizationId` | Parent synchronization |
| `result` | `success`, `warning`, or `error` |
| `objectsProcessed` | Total objects evaluated |
| `objectsCreated` | Objects newly created in target |
| `objectsUpdated` | Objects updated in target |
| `objectsDeleted` | Objects deleted in target |
| `objectsSkipped` | Objects skipped (no change) |
| `errors` | Array of per-object error details |
| `executionTime` | Run duration in milliseconds |
| `created` | Run timestamp |

## Job Logging

Job executions are logged per run. See [Jobs](jobs.md) for details.

## Prometheus Metrics

OpenConnector exposes metrics in the [Prometheus text exposition format](https://prometheus.io/docs/instrumenting/exposition_formats/) at:

```
GET /index.php/apps/openconnector/api/metrics
```

**Authentication:** Requires Nextcloud admin session or API token.

### Available Metrics

| Metric | Type | Description |
|--------|------|-------------|
| `openconnector_info` | gauge | App version info (labels: `version`, `php_version`, `nextcloud_version`) |
| `openconnector_up` | gauge | 1 if healthy, 0 if database unavailable |
| `openconnector_sources_total` | gauge | Source count by type |
| `openconnector_endpoints_total` | gauge | Total registered endpoints |
| `openconnector_mappings_total` | gauge | Total registered mappings |
| `openconnector_synchronizations_total` | gauge | Total synchronization definitions |
| `openconnector_synchronization_runs_total` | counter | Sync run count by status |
| `openconnector_calls_total` | counter | HTTP call count by status code |
| `openconnector_jobs_total` | gauge | Total job definitions |
| `openconnector_job_runs_total` | counter | Job run count by result |

### Prometheus Scrape Configuration

```yaml
scrape_configs:
  - job_name: 'openconnector'
    static_configs:
      - targets: ['your-nextcloud.example.com']
    metrics_path: '/index.php/apps/openconnector/api/metrics'
    scheme: https
    basic_auth:
      username: admin
      password: your-admin-password
    scrape_interval: 60s
```

## Health Check

```
GET /index.php/apps/openconnector/api/health
```

Returns JSON with application health status. HTTP 200 when healthy, HTTP 503 when degraded.

```json
{
  "status": "ok",
  "version": "2.1.0",
  "checks": {
    "database": "ok",
    "tables": "ok"
  }
}
```

## Log Retention

Logs are automatically purged to manage storage:

| Log Type | Default Retention |
|----------|------------------|
| CallLog | 30 days |
| SynchronizationLog (success) | 30 days |
| SynchronizationLog (error) | 90 days |
| SynchronizationContractLog (error) | 90 days |
| JobLog (success) | 30 days |
| JobLog (error) | 90 days |

Retention periods are configurable per synchronization and globally via app settings.

## Implementation

- `lib/Service/CallService.php` — HTTP call execution and log writing
- `lib/Controller/LogsController.php` — Call log query API
- `lib/Controller/MetricsController.php` — Prometheus metrics endpoint
- `lib/Controller/HealthController.php` — Health check endpoint
- `lib/Db/CallLog.php` — Call log entity
- `lib/Db/CallLogMapper.php` — Call log mapper
- `lib/Db/SynchronizationLog.php` — Sync log entity
- `lib/Db/SynchronizationLogMapper.php` — Sync log mapper
