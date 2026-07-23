# Tasks: dashboard-http-datasource

## Backend
- [ ] Advertise the `dashboard-http-datasource` capability (name, version, enabled) via the app capability registry for leaf probing.
- [ ] `DashboardDatasourceService::resolve(sourceId, valueExpr, params, ttl)` — run the named source through the existing HTTP-call engine (applying stored auth), evaluate a JSONPath-lite expression, return `{value, fetchedAt, stale}`; single shared code path for controller + in-process callers.
- [ ] Resolve controller — `POST /api/datasource/{sourceId}/resolve`; authenticated user; honour the source's read-authorization (403 otherwise); ignore any caller-supplied url/host.
- [ ] `appinfo/routes.php` — register the route with its auth attribute.
- [ ] Caching — `ICache` keyed by sourceId + valueExpr + params; TTL = min(requested, source-max); stale-on-error fallback; per-source rate limit.
- [ ] Ensure the response never contains the source URL, headers, or credentials.

## Testing
- [ ] PHPUnit: successful resolve; missing-path returns null; read-only (no mutation); caller URL ignored; credentials absent from response; cache hit within TTL; stale-on-error; rate-limit; 403 on unauthorized source.
- [ ] Live smoke against a public no-auth source (e.g. endoflife.date) proving the resolve chain end-to-end without a credential.

## Docs
- [ ] Document the resolve API + capability contract for leaf-app authors; cross-reference LaunchPad `live-data-tile-widget`.

## Out of scope
- Write/POST actions against a source (read-only façade).
- Charting/time-series (single scalar only).
- WebSocket push (polling only).
