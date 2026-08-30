# Design: Prometheus Metrics

## Architecture

The Prometheus metrics feature follows a simple controller pattern:

- **MetricsController** exposes `GET /api/metrics` returning Prometheus text exposition format
- **HealthController** exposes `GET /api/health` returning JSON health status
- Both controllers use `IDBConnection` query builder for database queries
- No new entities or services needed -- metrics are computed from existing tables

## Implementation Approach

### MetricsController Extensions
Add three new collector methods to the existing MetricsController:
1. `collectEndpointMetrics()` -- counts from `openconnector_endpoints` table
2. `collectJobMetrics()` -- counts from `openconnector_jobs` and `openconnector_job_logs` tables
3. `collectMappingRuleMetrics()` -- counts from `openconnector_mappings` and `openconnector_rules` tables

Each follows the same pattern as existing collectors:
- `# HELP` and `# TYPE` header lines
- Database query with error handling
- Zero-value fallback on failure

### Error Handling
All metric collectors use try/catch with zero-value fallback. One failing collector does not break the entire endpoint.

## Dependencies
- Existing `openconnector_endpoints`, `openconnector_jobs`, `openconnector_job_logs`, `openconnector_mappings`, `openconnector_rules` tables
- No external dependencies

## Risks
- Endpoint hit tracking (REQ-PROM-007) requires a hit counter mechanism in EndpointService -- deferred as it would need instrumentation changes across the request pipeline
- For now, endpoint total count is implemented; hit counting is noted as future work
