# dashboard-http-datasource Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- `dashboard-http-datasource` (this change)

## Purpose

Expose a governed, read-only "resolve one value from a configured HTTP
source" façade so a dashboard/widget host can render live data on tiles
without ever performing third-party HTTP itself. Credentials, host
allow-listing, rate-limiting and response caching stay inside OpenConnector,
built on the existing `source-management`, `http-call-engine`,
`authentication-twig`, and encrypted secret-store capabilities (none
modified). LaunchPad's `live-data-tile-widget` is the first consumer and
reintegrates this as a leaf via a capability probe.

## ADDED Requirements

### Requirement: dashboard-http-datasource capability is advertised for leaf probing

OpenConnector SHALL advertise a `dashboard-http-datasource` capability
(name, semantic version, enabled flag) through the app capability registry
so that a leaf app can detect its presence at runtime and degrade cleanly
when it is absent.

#### Scenario: Capability present
- GIVEN OpenConnector is installed with this change applied
- WHEN a leaf app queries the capability registry for `dashboard-http-datasource`
- THEN it SHALL receive the capability with an enabled flag set to true and a version string

#### Scenario: Capability absent
- GIVEN OpenConnector is NOT installed
- WHEN a leaf app probes for the capability
- THEN the probe SHALL report absence and the leaf app SHALL NOT attempt any OpenConnector call

### Requirement: Resolve endpoint returns a single value from a named source

OpenConnector SHALL expose `POST /api/datasource/{sourceId}/resolve`
accepting `{ valueExpr, params?, ttl? }`, which runs the named `source`
through the existing HTTP-call engine and returns `{ value, fetchedAt,
stale }`.

#### Scenario: Successful resolve
- GIVEN a configured, enabled `source` the current user may read
- WHEN the user POSTs `{ "valueExpr": "$.data.open_count" }` to its resolve endpoint
- THEN OpenConnector SHALL fetch the source (applying its configured auth from the encrypted store), evaluate the JSONPath-lite expression against the response body, and return `{ value: <resolved>, fetchedAt: <iso8601>, stale: false }`

#### Scenario: Value expression finds nothing
- GIVEN a source whose response does not contain the expression's path
- WHEN the value is resolved
- THEN the response SHALL be `{ value: null, fetchedAt: <iso8601>, stale: false }` and SHALL NOT error

#### Scenario: Read-only guarantee
- GIVEN any resolve request
- WHEN it is processed
- THEN OpenConnector SHALL only perform the source's read/GET operation and SHALL NOT mutate the source, its synchronizations, or any object

### Requirement: Egress is constrained to the source, never the caller

OpenConnector SHALL derive the target host/URL exclusively from the stored
`source` configuration and SHALL NOT accept an arbitrary URL or host from
the caller.

#### Scenario: Caller cannot inject a URL
- GIVEN a resolve request whose body contains a `url` or `host` field
- WHEN it is processed
- THEN OpenConnector SHALL ignore any caller-supplied URL/host and use only the stored source location

#### Scenario: Credentials never returned
- GIVEN a source configured with an API key or bearer token in the encrypted store
- WHEN a value is resolved
- THEN the response SHALL contain only the resolved value and metadata, and SHALL NOT include the source URL, headers, or any credential

### Requirement: Responses are cached with stale-on-error fallback

OpenConnector SHALL cache resolved values in `ICache` keyed by source id +
value expression + params, with TTL = min(requested ttl, source-configured
maximum), and SHALL serve a stale value when a refresh fails.

#### Scenario: Cache hit within TTL
- GIVEN a value resolved 60 seconds ago with ttl 300
- WHEN the same resolve request arrives again
- THEN OpenConnector SHALL return the cached value with `stale: false` and SHALL NOT perform a new upstream fetch

#### Scenario: Stale-on-error
- GIVEN a previously cached value whose upstream is now unreachable or returns non-2xx
- WHEN a refresh is attempted
- THEN OpenConnector SHALL return the last-known value with `stale: true`
- AND WHEN no cached value exists THEN it SHALL return `{ value: null, stale: true }`

#### Scenario: Per-source rate limit
- GIVEN a source configured with a per-source rate limit
- WHEN resolve calls exceed that rate within the window
- THEN excess calls SHALL be served from cache or rejected with a rate-limit response, and SHALL NOT hit the upstream

### Requirement: Caller authorization honours the source's read access

OpenConnector SHALL require an authenticated Nextcloud user and SHALL honour
the source's own read-authorization.

#### Scenario: Unauthorized source
- GIVEN a source the current user may not read
- WHEN the user calls its resolve endpoint
- THEN OpenConnector SHALL return 403 and SHALL NOT perform the fetch

#### Scenario: Unauthenticated caller
- GIVEN no authenticated Nextcloud session
- WHEN the resolve endpoint is called
- THEN OpenConnector SHALL reject the request per standard controller auth
