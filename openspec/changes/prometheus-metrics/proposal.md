# Prometheus Metrics Endpoint

## Problem

OpenConnector has no standardised observability surface. Operations teams running OpenConnector in container orchestration environments (Kubernetes, Docker Swarm) have no way to:

- Scrape application metrics into Prometheus / Grafana for dashboards and alerting
- Distinguish healthy from degraded deployments using liveness/readiness probes
- Track API call error rates, synchronisation failure trends, or job queue health over time

The absence of a `/api/metrics` endpoint means every deployment team must instrument monitoring externally (database polling, log scraping), duplicating work and missing app-internal state.

## Proposed Solution

Expose two new authenticated endpoints:

1. **`GET /index.php/apps/openconnector/api/metrics`** — returns Prometheus text exposition format (`text/plain; version=0.0.4`) covering application info, uptime, source counts by type, API call counters by status code, synchronisation metrics, endpoint counters, job queue metrics, and mapping/rule counts. All metrics follow the `openconnector_` prefix convention (ADR-006).

2. **`GET /index.php/apps/openconnector/api/health`** — returns JSON `{"status": "ok"|"degraded"|"error", "checks": {...}}` for liveness/readiness probes. Checks database connectivity, sources table presence, and optionally critical source reachability.

Both endpoints require Nextcloud admin authentication. All database queries use indexed `COUNT` operations on existing OpenConnector tables with zero-value fallbacks on partial failures.

The core implementation (`MetricsController`, `HealthController`, route registration) already exists covering REQ-PROM-001 through REQ-PROM-006 and basic REQ-PROM-010. Remaining work completes endpoint metrics (REQ-PROM-007), job queue metrics (REQ-PROM-008), mapping/rule metrics (REQ-PROM-009), admin auth enforcement, and critical source reachability.

## Scope

- `MetricsController`: extend with `collectEndpointMetrics()`, `collectJobMetrics()`, `collectMappingRuleMetrics()`
- `HealthController`: add optional critical source reachability checks
- Admin authentication: enforce `requireAdmin()` on both controllers
- Unit tests for all new collectors with zero-value fallback coverage

## Out of Scope

- Request duration histograms (would require middleware or `CallService` instrumentation across the request pipeline — deferred)
- `openconnector_endpoint_hits_total` per-endpoint hit counter (requires `EndpointService` instrumentation — deferred)
- Prometheus push gateway support

## Success Criteria

- `GET /api/metrics` returns valid Prometheus exposition format with all metrics defined in REQ-PROM-001 through REQ-PROM-009
- `GET /api/health` returns correct `ok`/`degraded`/`error` status for the scenarios in REQ-PROM-010
- Both endpoints return HTTP 401 for unauthenticated requests
- A single failing metric collector does not break the entire metrics endpoint (zero-value fallback)
- All database queries complete within 500ms under normal conditions
