---
retrofit: true
---

# Endpoint Runtime Dispatch

## Purpose

OpenConnector exposes configurable endpoints under `/api/endpoint/{path}`. At
request time the runtime resolves the path to a registered endpoint, applies
CORS, optionally short-circuits "simple" schema CRUD, and otherwise runs the
full request pipeline — condition checks, before/after rule processing, and
dispatch to either an OpenRegister register/schema (object CRUD) or an external
Source (proxy via CallService). This capability describes the **observed
behaviour** of the endpoint dispatch + caching + request/response normalisation
layer (`EndpointsController`, `EndpointService` dispatch methods, and
`EndpointCacheService`) as it exists today. It is a retrofit spec: the code
already exists, and these REQs document it. Target resolution follows the
polymorphic `targetType` / `targetId` contract in ADR-008. Rule processing
itself is specified separately under the `rule-pipeline` capability.

## ADDED Requirements
### Requirement: Generic Path Dispatch and CORS (REQ-EP-001)

The system MUST expose a public generic dispatcher at
`/api/endpoint/{_path}` that resolves the request path and HTTP method to a
single registered endpoint via the endpoint cache, returning HTTP 404 when no
endpoint matches and HTTP 409 when the path/method is ambiguous (multiple
matches). On a match it MUST route to the simple fast-path or the full
`EndpointService` pipeline, convert the response to XML when the `Accept` header
requests `application/xml`, and apply CORS via `corsAfterController`. The system
MUST also provide a CORS preflight (`OPTIONS`) response carrying the configured
allowed methods, headers, max-age, and `Access-Control-Allow-Credentials: false`.

**Scenarios:**

1. **GIVEN** a request to `/api/endpoint/{path}` whose path+method match exactly
   one registered endpoint **WHEN** `handlePath` runs **THEN** the request is
   routed to that endpoint and a CORS-decorated response is returned.

2. **GIVEN** a request whose path+method match no endpoint **WHEN** `handlePath`
   runs **THEN** the response is HTTP 404 with a localised "No matching endpoint"
   message.

3. **GIVEN** a request whose path+method match more than one endpoint **WHEN**
   the cache service raises the ambiguity exception **THEN** `handlePath` returns
   HTTP 409 with the conflicting endpoint names.

4. **GIVEN** a matched JSON response and a request `Accept: application/xml`
   header **WHEN** `handlePath` finishes **THEN** the response is converted to an
   `XMLResponse` preserving status and headers.

5. **GIVEN** an `OPTIONS` preflight request **WHEN** `preflightedCors` runs
   **THEN** the response carries the configured Allow-Methods / Allow-Headers /
   Max-Age and `Access-Control-Allow-Credentials: false`.

**Notes:**
- `getPathParameters` (controller) and `buildPaginationUrl` are helpers of this
  dispatch path; the latter builds absolute next/previous URLs from the request
  protocol+host.
- `handlePath` carries `@NoAdminRequired @NoCSRFRequired @PublicPage` docblock
  annotations; the endpoint's own authentication is enforced by an
  `authentication` rule in the rule pipeline, not by the controller.

### Requirement: Simple-Endpoint Fast Path (REQ-EP-002)

The system MUST detect "simple" endpoints — those targeting a single
register/schema with no rules, conditions, input/output mappings, or
configurations and using a standard HTTP method — and serve them directly via
the OpenRegister mapper, bypassing the full rule pipeline. The fast path MUST
parse the composite `targetId` (`"{registerId}/{schemaId}"`), validate both
parts are numeric (HTTP 500 on misconfiguration), and map GET (single/collection
with pagination), POST (201), PUT, PATCH, and DELETE (204) to the corresponding
mapper operations, returning HTTP 405 for unsupported methods.

**Scenarios:**

1. **GIVEN** an endpoint with no rules/conditions/mappings/configurations,
   `targetType` `register/schema`, and a standard method **WHEN** `isSimpleEndpoint`
   is evaluated **THEN** it returns true and the request bypasses `EndpointService`.

2. **GIVEN** a simple GET collection request **WHEN** `handleSimpleSchemaRequest`
   runs **THEN** objects are returned paginated with `count`, `results`, and
   `next`/`previous` links where applicable.

3. **GIVEN** a simple POST request **WHEN** the rule runs **THEN** the object is
   created via the mapper and returned with HTTP 201.

4. **GIVEN** a simple endpoint with an empty or non-numeric `targetId` **WHEN**
   the fast path runs **THEN** it logs the misconfiguration and returns HTTP 500
   with a descriptive error.

5. **GIVEN** a simple PUT/PATCH/DELETE request without an `id` path parameter
   **WHEN** the fast path runs **THEN** it returns HTTP 400 requiring an id.

### Requirement: Full Pipeline and Target Dispatch (REQ-EP-003)

For non-simple endpoints the system MUST run the full request pipeline:
evaluate endpoint `conditions` (returning HTTP 400 with offending fields when
they fail), build a `FlowToken` request envelope, process "before" rules,
dispatch on `targetType` — `register/schema` to OpenRegister object CRUD
(`handleSchemaRequest` / `getObjects`) or `api` to an external Source proxied
through `CallService` (`handleSourceRequest`) — then process "after" rules and
return the response with a method-appropriate status code (POST→201,
DELETE→204, otherwise 200, or a configured `defaultStatusCode`). An endpoint
with neither a schema nor a source target MUST raise an error.

**Scenarios:**

1. **GIVEN** an endpoint with `targetType` `register/schema` **WHEN**
   `handleRequest` runs **THEN** before-rules run, the schema CRUD operation is
   dispatched via the OpenRegister mapper, after-rules run, and the response
   carries the method-appropriate status code.

2. **GIVEN** an endpoint with `targetType` `api` **WHEN** `handleRequest` runs
   **THEN** the request is proxied to the referenced Source via `CallService`
   and the source's response body and status code are returned.

3. **GIVEN** an endpoint whose `conditions` fail for the incoming request
   **WHEN** `checkConditions` runs **THEN** `handleRequest` returns HTTP 400 with
   the list of offending fields and no target dispatch occurs.

4. **GIVEN** an endpoint with neither schema nor source target **WHEN**
   `handleRequest` runs **THEN** an exception "Endpoint must specify either a
   schema or source connection" is thrown.

5. **GIVEN** a GET request for a single object id that does not exist **WHEN**
   `getObjects` runs **THEN** it sets status 404 and returns a "not found"
   payload.

**Notes:**
- **Information disclosure (flagged):** the `handleRequest` top-level
  `catch (Exception)` returns the full `$e->getTrace()` in the HTTP 400 response
  body. Stack traces in client-facing responses leak internal paths and call
  structure (OWASP A05:2021). Documented as observed; recommended for follow-up.
- `handleSchemaRequest` maps OpenRegister `ValidationException` /
  `CustomValidationException` to the mapper's validation-response handler;
  other exceptions re-throw to the `handleRequest` catch.
- `getRuleById` (lookup helper used by the dispatch + rule path) returns null on
  any lookup exception (logged), silently dropping unresolvable rule ids.
- `generateEndpointUrl` resolves the public URL of an object by finding a GET
  endpoint whose `targetId` matches the object's `register/schema`.

### Requirement: Endpoint Resolution Cache (REQ-EP-004)

The system MUST cache the registered endpoint set to avoid an OpenRegister
query on every request. Resolution (`findByPathRegex`) MUST filter cached
endpoints by compiled `endpointRegex` and method; on a cache miss it MUST
refresh once and retry (single retry, no infinite recursion). The cache MUST be
layered: a request-lifetime in-memory copy plus a distributed cache with a
1-hour TTL, with a fall-back to a direct OpenRegister query when the cache
errors. The system MUST provide explicit `refreshCache`, `clearCache` (invoked
on endpoint create/update/delete), regex compilation (`createEndpointRegex`),
and a `getCacheStats` diagnostics method.

**Scenarios:**

1. **GIVEN** a populated cache **WHEN** `findByPathRegex` is called **THEN** the
   matching endpoint is resolved from cache without an OpenRegister query.

2. **GIVEN** an empty cache and a path that should match **WHEN** `findByPathRegex`
   finds no match on the first pass **THEN** it refreshes the cache once and
   retries exactly once before returning null.

3. **GIVEN** a path that matches more than one cached endpoint **WHEN**
   `findByPathRegex` runs **THEN** it throws an ambiguous-routing exception
   listing the matching endpoint names.

4. **GIVEN** the distributed cache raises an error **WHEN** `getAllEndpoints`
   runs **THEN** it logs a warning and falls back to a direct OpenRegister query
   via `fetchEndpointsFromOr`.

5. **GIVEN** endpoints are modified **WHEN** `clearCache` is called **THEN** the
   in-memory and distributed caches are dropped so the next resolution reloads
   fresh data.

**Notes:**
- `createEndpointRegex` duplicates `EndpointMapper::createEndpointRegex` by
  design (the docblock notes this is "to maintain cache service independence").
  Documented as observed duplication.

### Requirement: Request and Response Normalisation (REQ-EP-005)

The system MUST normalise inbound and outbound payloads. Inbound: parse raw
`php://input` (`getRawContent`), decode JSON, XML (when the content-type or a
content sniff via `looksLikeXml` indicates XML), or multipart form data
(`parseContent`); normalise request headers including optional proxy headers
(`getHeaders`). Outbound: map upstream validation errors into an RFC-7807-shaped
problem response (`transformError` + `parseMessage`); rewrite internal
OpenRegister UUID references to public endpoint URLs on output
(`replaceInternalReferences` / `replaceUuidsInArray`) and rewrite incoming URLs
back to internal UUIDs on input (`rewriteExternalReferences`); normalise the
OpenRegister `extend` syntax (`reduceExtendKeys`).

**Scenarios:**

1. **GIVEN** a request body of JSON, XML, or multipart form data **WHEN**
   `parseContent` runs **THEN** the body is decoded to the corresponding
   structured array (falling back to request params when decoding fails).

2. **GIVEN** an upstream "Validation failed" error with a missing-property
   message **WHEN** `transformError` / `parseMessage` run **THEN** the response
   is a problem document with `type`, `code`, `status`, `instance`, `detail`,
   and an `invalidParams` array.

3. **GIVEN** a serialised object containing internal UUID references **WHEN**
   `replaceInternalReferences` runs **THEN** the UUIDs are replaced with public
   endpoint URLs in the output.

4. **GIVEN** an inbound payload containing public object URLs **WHEN**
   `rewriteExternalReferences` runs **THEN** the URLs are rewritten to internal
   OpenRegister UUIDs before persistence.

5. **GIVEN** a request with an `extend` parameter **WHEN** `reduceExtendKeys`
   runs **THEN** the extend syntax is normalised for the OpenRegister mapper.

**Notes:**
- `getHeaders` filters `HTTP_`-prefixed server keys and conditionally excludes
  `X-Forwarded*` / `X-Real-IP` / `X-Original-Uri` proxy headers unless
  proxy-header mode is requested.
