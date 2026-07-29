# Dashboard HTTP datasource — resolving one value from a source

A read-only façade for dashboard/widget hosts that need to show **live data on
a tile** without performing third-party HTTP themselves. The consuming app
names a pre-configured OpenConnector `source` plus a value expression and gets
back a resolved, cached value. Credentials, host allow-listing, rate-limiting
and caching all stay inside OpenConnector.

LaunchPad's `live-data-tile-widget` is the first consumer.

## Why a leaf app should use this instead of its own HTTP client

Integration transports belong in OpenConnector; leaf apps consume the result.
Calling out directly from a leaf app would put egress control and secrets in
the wrong place and duplicate the source engine. This endpoint gives you the
value and nothing else — the response never contains the source URL, request
headers or any credential.

## Probing for the capability

The capability is advertised so a leaf app can detect it and degrade cleanly
when OpenConnector is absent:

```json
{
  "capabilities": {
    "dashboard_http_datasource": { "name": "…", "version": "…", "enabled": true }
  }
}
```

Probe at runtime and fall back to your own behaviour when it is missing.
**Do not statically import OpenConnector PHP classes** from a leaf app.

## Resolving a value

```http
POST /apps/openconnector/api/datasource/{sourceId}/resolve
Content-Type: application/json

{ "valueExpr": "$.data.open_count", "ttl": 300 }
```

Response:

```json
{ "value": 42, "fetchedAt": "2026-07-24T09:15:00+00:00", "stale": false }
```

| Field | Meaning |
|-------|---------|
| `value` | The resolved value, or `null` when the expression matched nothing. |
| `fetchedAt` | When the underlying reading was obtained. |
| `stale` | `true` when a refresh failed and a previous reading is being served. |

### Value expressions

A deliberately small JSONPath-lite dialect, evaluated in PHP — no arbitrary
code:

- `$.a.b.c` — nested object traversal
- `$.a[0].b` — array index

A path that matches nothing yields `value: null`; it is not an error.

## Guarantees you can rely on

- **Read-only.** Only the source's read/GET operation runs. The source, its
  synchronizations and any objects are never mutated.
- **Egress is the source's, never the caller's.** A `url` or `host` in your
  request body is ignored — the target comes only from the stored source.
- **No credential leakage.** The response carries the value and metadata only.
- **Authorization is the source's.** The caller must be an authenticated
  Nextcloud user and must be permitted to read that source, else `403`.
- **Cached, with stale-on-error.** TTL is `min(requested, source maximum)`. If
  a refresh fails, the last known value is returned with `stale: true`; with no
  cached value at all you get `value: null, stale: true`.
- **Rate-limited per source**, so a dashboard with many tiles cannot hammer an
  upstream.

## Handling `stale` in a widget

Treat `stale: true` as "show the value, but tell the user it may be old" — a
subdued badge or timestamp. Do not hide the value: on a wall-mounted or
service-desk dashboard, a slightly old number is far more useful than an empty
tile.

## Not in scope

- Write/POST actions against a source — this façade is read-only.
- Charts or time-series — a single scalar is returned; the consumer formats it.
- WebSocket push — polling only.
