# Design: Prometheus Metrics Endpoint

## Architecture

The feature follows OpenConnector's existing Controller → Service → IDBConnection layering (ADR-008). No new services or entities are introduced — all metrics are computed at query time from existing OpenConnector tables using indexed `COUNT` queries.

### Existing Components (already implemented)

| Component | File | Status |
|-----------|------|--------|
| `MetricsController::index()` | `lib/Controller/MetricsController.php` | Implemented |
| `HealthController::index()` | `lib/Controller/HealthController.php` | Implemented |
| Route registration | `appinfo/routes.php` | Implemented |

The existing `MetricsController` exposes: `openconnector_info`, `openconnector_up`, `openconnector_sources_total` (by type), `openconnector_calls_total` (by status), `openconnector_synchronizations_total`, and `openconnector_synchronization_runs_total` (by status).

### Extensions Required

Three new private collector methods added to `MetricsController`:

```
MetricsController
├── index()                       — already exists; calls all collectors
├── collectInfoMetric()           — already exists
├── collectUpMetric()             — already exists
├── collectSourceMetrics()        — already exists
├── collectCallMetrics()          — already exists
├── collectSyncMetrics()          — already exists
├── collectEndpointMetrics()      — NEW: REQ-PROM-007
├── collectJobMetrics()           — NEW: REQ-PROM-008
└── collectMappingRuleMetrics()   — NEW: REQ-PROM-009
```

Each collector follows the identical pattern as existing collectors:

```php
private function collectEndpointMetrics(): string {
    $output = "# HELP openconnector_endpoints_total Total registered endpoints\n";
    $output .= "# TYPE openconnector_endpoints_total gauge\n";
    try {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
            ->from('openconnector_endpoints')
            ->executeQuery();
        $count = (int) $result->fetchOne();
        $output .= "openconnector_endpoints_total $count\n";
    } catch (\Exception $e) {
        $this->logger->warning('Failed to collect endpoint metrics: ' . $e->getMessage());
        $output .= "openconnector_endpoints_total 0\n";
    }
    return $output;
}
```

### Metric Definitions

| Metric Name | Type | Labels | Source Table | Column |
|-------------|------|--------|-------------|--------|
| `openconnector_info` | gauge | `version`, `php_version`, `nextcloud_version` | — | — |
| `openconnector_up` | gauge | — | `openconnector_sources` (SELECT 1) | — |
| `openconnector_sources_total` | gauge | `type` | `openconnector_sources` | `type` |
| `openconnector_calls_total` | counter | `status` | `openconnector_call_logs` | `status_code` |
| `openconnector_synchronizations_total` | gauge | — | `openconnector_synchronizations` | — |
| `openconnector_synchronization_runs_total` | counter | `status` | `openconnector_synchronization_logs` | `result` |
| `openconnector_endpoints_total` | gauge | — | `openconnector_endpoints` | — |
| `openconnector_endpoint_hits_total` | counter | `endpoint`, `method` | *deferred* | — |
| `openconnector_jobs_total` | gauge | — | `openconnector_jobs` | — |
| `openconnector_job_runs_total` | counter | `status` | `openconnector_job_logs` | `status` |
| `openconnector_mappings_total` | gauge | — | `openconnector_mappings` | — |
| `openconnector_rules_total` | gauge | — | `openconnector_rules` | — |

### Health Check Extensions

`HealthController` currently checks database connectivity (SELECT 1) and sources table presence. Extension adds optional critical source reachability:

```php
// Existing checks
'database'     => $this->checkDatabase(),    // SELECT 1
'sources_table' => $this->checkSourcesTable(), // COUNT(*)

// New optional check
'source_reachability' => $this->checkCriticalSources(), // HEAD request to configured sources
```

The overall status derives from check results:
- All checks pass → `"ok"`
- Any check fails but the app responds → `"degraded"`
- Database itself is inaccessible → `"error"`

### Admin Authentication Enforcement

Both controllers must carry `#[AuthorizedAdminSetting(Application::APP_ID)]` on their action methods (per ADR-005 and ADR-016). The `@NoCSRFRequired` annotation is kept for API token scraping. Routes are registered in `appinfo/routes.php` only.

## Reuse Analysis

Per ADR-001 (Deduplication Check):

| Capability | Source | Decision |
|-----------|--------|----------|
| Database query builder | `IDBConnection` (Nextcloud core) | Reused — all COUNT queries use the existing injected `IDBConnection` |
| Prometheus text format | None in codebase | New lightweight string builder — no library needed for simple exposition format |
| Health check pattern | OpenRegister `HeartbeatController` | Referenced as canonical pattern; not imported (OpenConnector has no hard OR dependency) |
| Error/fallback logging | `ILogger` (Nextcloud core) | Reused — existing `$this->logger->warning()` pattern |

No overlap found with ObjectService, RegisterService, SchemaService, or ConfigurationService — this feature does not store or manage objects in OpenRegister.

## Seed Data

Not applicable. This change introduces no new schemas and no new OpenRegister entities. Metrics are computed at query time from existing OpenConnector tables. Per ADR-001 seed data exception: "Changes that only modify ... non-schema backend logic do not require seed data."

## Endpoint Cardinality Control

`openconnector_endpoint_hits_total` (REQ-PROM-007) is deferred because it requires instrumentation in `EndpointService` to count hits per endpoint+method combination. When implemented, results MUST be limited to the top 100 by hit count to prevent label cardinality explosion (a Prometheus anti-pattern).

## Dependencies

- **IDBConnection** (Nextcloud core) — already injected into both controllers
- **ILogger** (Nextcloud core) — already injected
- **IAppManager** — required to read app version for `openconnector_info`
- Existing tables: `openconnector_sources`, `openconnector_call_logs`, `openconnector_synchronizations`, `openconnector_synchronization_logs`, `openconnector_endpoints`, `openconnector_jobs`, `openconnector_job_logs`, `openconnector_mappings`, `openconnector_rules`

## Risks

- **Table existence**: If a migration has not been run, some tables may be missing. Each collector wraps its query in try/catch and emits a zero-value fallback — the metrics endpoint never returns a 5xx due to missing tables.
- **Scrape frequency**: At 15-second scrape intervals, `COUNT(*)` queries run frequently. All target tables should have primary key indexes (default in Nextcloud ORM), making queries fast. Monitor query time in staging before enabling short scrape intervals in production.
- **`openconnector_endpoint_hits_total` deferral**: The spec (REQ-PROM-007) requires per-endpoint hit counters. The current implementation exposes only `openconnector_endpoints_total`. Alerting rules that depend on hit counters will not work until the deferred instrumentation is added to `EndpointService`.
