# endpoint-runtime Specification (Delta)

## ADDED Requirements

### Requirement: Declarative id-fetch guard for single-object GET (REQ-EP-010)

When an Endpoint declares a fixed filter set (the set its `inputMapping`
injects for collection requests), the system MUST re-check the object that
`getObjects()`'s id-branch fetched against that set after `$mapper->find()`
resolves, and MUST return HTTP 404, not the object, when it does not match,
so a single-item GET enforces the same declarative gates as its collection
sibling. The guard is opt-in per Endpoint and generic, not ORI-specific.

#### Scenario: A fetched object matching the fixed filter set is returned normally

- **GIVEN** an Endpoint whose fixed filter set is `{lifecycle: "published"}`
- **AND** the object at the requested id has `lifecycle: "published"`
- **WHEN** `getObjects()`'s id-branch resolves the object
- **THEN** the object is returned as today, unchanged
- @e2e exclude backend dispatch guard — covered by Newman/PHPUnit, not browser UI

#### Scenario: A fetched object failing the fixed filter set 404s instead of returning

- **GIVEN** an Endpoint whose fixed filter set is `{lifecycle: "published"}`
- **AND** the object at the requested id has `lifecycle: "draft"`
- **WHEN** `getObjects()`'s id-branch resolves the object
- **THEN** the response is HTTP 404, not the draft object
- @e2e exclude backend dispatch guard — covered by Newman/PHPUnit, not browser UI

#### Scenario: An Endpoint with no declared fixed filter set is unaffected

- **GIVEN** an Endpoint with no fixed filter set configured (today's default
  for every existing Endpoint)
- **WHEN** `getObjects()`'s id-branch resolves an object
- **THEN** behaviour is unchanged from pre-change — the object is returned
  as long as it exists, exactly as today
- @e2e exclude backend dispatch guard — covered by Newman/PHPUnit, not browser UI

#### Scenario: The guard checks the object's own field, not a request parameter

- **GIVEN** an Endpoint whose fixed filter set includes a discriminator field
  (e.g. `decisionType: "motion"`)
- **WHEN** the fetched object's own `decisionType` field is `"amendment"`
- **THEN** the response is HTTP 404, regardless of what (if anything) the
  caller supplied in the request
- @e2e exclude backend dispatch guard — covered by Newman/PHPUnit, not browser UI

### Requirement: An endpoint may name its target register and schema by slug (REQ-EP-011)

An Endpoint's `targetId` MAY name its register and schema by slug
(`decidiq/meeting`) as well as by id (`20/111`). The system MUST resolve the
register by id or slug, and the schema by id or slug AMONG THE REGISTER'S OWN
SCHEMAS, because a schema slug such as `person` exists in more than one
register. A target that names no register, or a schema the register does not
hold, MUST NOT resolve to any other register's schema.

#### Scenario: A target named by slug reaches the register's own schema

- **GIVEN** decidiq's register holds a schema with slug `meeting`
- **AND** an Endpoint with `targetId: "decidiq/meeting"`
- **WHEN** a request reaches the endpoint
- **THEN** it reads decidiq's `meeting` schema
- @e2e exclude backend dispatch resolution, covered by PHPUnit (OriPublicEndpointsTest), not browser UI

#### Scenario: A schema outside the register does not resolve

- **GIVEN** an Endpoint with `targetId: "decidiq/vote"` and decidiq's register holds no `vote` schema
- **WHEN** the target is resolved
- **THEN** it is not found, even when another register holds a `vote` schema
- @e2e exclude backend dispatch resolution, covered by PHPUnit (OriPublicEndpointsTest), not browser UI

#### Scenario: A target of two ids is unchanged

- **GIVEN** an Endpoint with `targetId: "20/111"`
- **WHEN** the target is resolved
- **THEN** it is register 20 and schema 111, used as it is, with no lookup
- @e2e exclude backend dispatch resolution, covered by PHPUnit (OriPublicEndpointsTest), not browser UI

### Requirement: An endpoint's fixed filters narrow its collection, and no path skips them (REQ-EP-012)

An Endpoint's `fixedFilters` (REQ-EP-010) MUST also narrow its collection
GET: each fixed filter is added to the query and wins over any value the
caller sent for the same field. An Endpoint that declares `fixedFilters` MUST
NOT be served by the fast path for simple public endpoints, which answers a
single object without the id-fetch guard.

#### Scenario: The collection is narrowed over the caller's own filter

- **GIVEN** an Endpoint whose `fixedFilters` are `{lifecycle: "published"}`
- **WHEN** a caller requests the collection with `lifecycle=draft`
- **THEN** the query asks for `lifecycle` `published`, so one declaration gates the list and the single objects in it and the two cannot drift apart
- @e2e exclude backend dispatch guard, covered by PHPUnit (OriPublicEndpointsTest, EndpointsControllerTest), not browser UI

#### Scenario: A public endpoint with fixed filters is not served by the fast path

- **GIVEN** an Endpoint with `isPublic: true`, no rules and `fixedFilters` `{decisionType: "motion"}`
- **WHEN** a GET reaches it
- **THEN** it is handled by the full endpoint pipeline, where the id-fetch guard runs
- @e2e exclude backend dispatch guard, covered by PHPUnit (OriPublicEndpointsTest, EndpointsControllerTest), not browser UI

### Requirement: A public endpoint may declare its own anonymous rate limit (REQ-EP-013)

An Endpoint MAY declare `anonymousRateLimit` (`{requestsPerWindow,
windowSeconds}`). When a request resolves no consumer (the endpoint has no
authentication rule, or the rule authenticates no consumer), the system MUST
count the request per endpoint and per client address, and MUST answer HTTP
429 once the count passes `requestsPerWindow` within the window. An Endpoint
that declares it MUST NOT be served by the fast path for simple public
endpoints, which runs no rate limit.

#### Scenario: The 121st anonymous request in a minute is refused

- **GIVEN** an Endpoint with `anonymousRateLimit` `{requestsPerWindow: 120, windowSeconds: 60}`
- **WHEN** one client address sends 121 requests within the minute
- **THEN** the first 120 are served and the 121st answers HTTP 429
- **AND** another client address, or another endpoint, is counted separately
- **AND** an identified consumer is held to its own consumer or tier limit (REQ-CON-RL-002) instead
- @e2e exclude backend throttle, covered by PHPUnit (OriPublicEndpointsTest) and TC-12 in the parity run, not browser UI

#### Scenario: An endpoint without the setting is not throttled

- **GIVEN** an Endpoint without `anonymousRateLimit`
- **WHEN** an unidentified caller sends a request
- **THEN** the endpoint applies no rate limit of its own: an Endpoint without `anonymousRateLimit` is unchanged
- @e2e exclude backend throttle, covered by PHPUnit (OriPublicEndpointsTest) and TC-12 in the parity run, not browser UI

### Requirement: An endpoint may declare its own CORS policy (REQ-EP-014)

An Endpoint MAY declare `cors` (`{allowedOrigin, allowedMethods,
allowedHeaders}`). `allowedOrigin` is `self` (the instance's own origin:
scheme, host and port of `overwrite.cli.url`, or `*` when that is not set),
`*`, or one origin. When an Endpoint declares it, the preflight (`OPTIONS`)
for a path that resolves to it and every answer it serves MUST carry that
origin, those methods and those headers (each with the default its scenario
shows), and MUST NOT allow credentials.

#### Scenario: The ORI endpoints answer decidiq's CORS values

- **GIVEN** an ORI Endpoint with `cors` `{allowedOrigin: self, allowedMethods: [GET, OPTIONS]}`
- **AND** `overwrite.cli.url` is `https://raad.example.nl/index.php`
- **WHEN** a browser on another origin sends the preflight for that endpoint's path
- **THEN** the answer allows origin `https://raad.example.nl`, methods `GET, OPTIONS` and headers `Authorization, Content-Type, X-Requested-With`
- **AND** `Access-Control-Allow-Credentials` is `false`
- **AND** the headers are the default `Authorization, Content-Type, X-Requested-With` because the policy names none; methods left out default to `GET, OPTIONS`
- **AND** the GET it then sends carries the same headers
- @e2e exclude HTTP header contract, covered by PHPUnit (EndpointsControllerTest, EndpointCorsPolicyTest) and TC-13 in the parity run, not browser UI

#### Scenario: An endpoint without the setting keeps the default preflight

- **GIVEN** an Endpoint without `cors`, or a path no single endpoint matches
- **WHEN** a browser sends the preflight for that path
- **THEN** the existing preflight answers: the caller's origin is echoed and credentials are not allowed, as before
- @e2e exclude HTTP header contract, covered by PHPUnit (EndpointsControllerTest, EndpointCorsPolicyTest) and TC-13 in the parity run, not browser UI

#### Scenario: A malformed policy is refused

- **GIVEN** a `cors` policy that names a credentials setting, or lists its methods as one string
- **WHEN** the Endpoint is saved
- **THEN** the schema refuses the policy
- @e2e exclude HTTP header contract, covered by PHPUnit (EndpointsControllerTest, EndpointCorsPolicyTest) and TC-13 in the parity run, not browser UI
