# Design — consumer-apikey-enforcement

## Investigate-first finding (verified at HEAD, origin/development `8de4610c`)

The audit flagged that the openconnector public-REST-API-via-endpoint path
declares *"consumer apiKey/JWT auth"* on `targetType: register/schema` endpoints,
but the apiKey control "enforces nothing today". Tracing the real request path:

1. `EndpointsController` → `EndpointService::handleRequest()` →
   `doHandleRequest()`. Authentication is **not** a first-class step: it happens
   only inside `processRules(timing: 'before')`, which iterates the endpoint's
   `rules` array and dispatches rules of `type: authentication` to
   `processAuthenticationRule()`.
2. `processRules()` opens with `if (empty($rules) === true) { return $data; }` —
   **an endpoint with no authentication rule performs no authentication at all**
   (fail-open by omission; legitimately public endpoints rely on this).
3. `processAuthenticationRule()` reads `configuration.authentication.type`. For
   `apikey` it calls `AuthorizationService::authorizeApiKey($header, $keys)`
   where `$keys` is `configuration.authentication.keys` — an inline map on the
   **rule**. A missing header short-circuits to HTTP 403; a non-matching key
   throws → HTTP 401. So the *rule-inline* apiKey path **does** fail closed.
4. **The gap:** `authorizeApiKey()` only ever compared against those rule-inline
   keys. It never read any `consumer` record. Grep confirms the only reader of a
   `consumer` from a request is `authorizeJwt()` → `findIssuer()` (by `iss`).
   `Consumer.authorizationType` and `Consumer.authorizationConfiguration.apiKey`
   had **zero enforcement callers**. `getResolvedConsumer()`'s own docblock
   admitted the apikey path resolves no consumer.

**Verdict: gap is REAL (partially enforced / Consumer-model apiKey inert).** The
JWT consumer path is enforced; the rule-inline apiKey path is enforced; but the
`REQ-CON-001` promise — *resolve the Consumer record and check the presented key
against the Consumer's configured apiKey* — was dead code. A Consumer configured
`authorizationType: apiKey` with a real `apiKey` credential authenticated nobody,
and (because no consumer was resolved) received no inbound rate-limit/quota
either.

## Decision

Make `authorizeApiKey()` Consumer-backed, additively and fail-closed, reusing
existing seams (no hand-rolled crypto):

- Keep the rule-inline `keys` check first (backward compatible; first match
  wins, short-circuits before any store query).
- Add a second resolution step: load `consumer` records via the already-injected
  `orObjectService->findAll(register: openconnector, schema: consumer)`; select
  the first whose `authorizationType` is `apiKey` (case-insensitive — the schema
  documents `apiKey`, the rule switch uses `apikey`) and whose
  `authorizationConfiguration.apiKey` matches under `hash_equals()`.
- On match: set `resolvedConsumer` (feeds `REQ-CON-RL-002` rate-limiting) and,
  when `userId` is present, set the session user (mirrors the JWT path; a
  consumer without a `userId` is still authenticated — the consumer is the
  identity).
- On no match (including an empty presented key): throw
  `AuthenticationException` → HTTP 401. Fail-closed.

### Why not a global fail-closed default on every endpoint

An endpoint with no authentication rule is intentionally public in this model
(the register/schema kernel serves openly by design when no rule is attached).
Flipping that to deny-by-default would break every existing public endpoint and
is a separate architectural decision, not this security fix. This change closes
the specific declared-but-unenforced control (Consumer apiKey) without changing
the default posture of unprotected endpoints.

## Seed Data

None. This change adds no schema fields and no seed objects — it wires
enforcement onto the **existing** `consumer` schema fields (`authorizationType`,
`authorizationConfiguration.apiKey`, `userId`) already present in
`lib/Settings/openconnector_register.json`. `x-openregister-seed` for the
`consumer` schema stays `[]`.

## ADR-031 (notification dialect)

Not applicable to this change — no object-notification dispatch and no
`lib/Settings/*register*.json` notification block is added or modified. The
canonical `x-openregister-notifications` dialect is untouched; gate-18
(notification-dialect) has nothing to flag here.

## Testing

`tests/Unit/Service/AuthorizationServiceApiKeyTest.php` proves the security
contract at the `authorizeApiKey()` seam:

- valid Consumer apiKey → authenticates, resolves the consumer, sets the user;
- **missing key → HTTP-401-equivalent `AuthenticationException`, store not even
  queried, no consumer resolved** (the bad path);
- wrong key (while a valid consumer key exists) → rejected, no user set, no
  consumer resolved;
- a `jwt` consumer carrying the same secret is never matched by the apiKey path
  (authorizationType gate);
- rule-inline keys still authenticate and short-circuit before any store query;
- a matching consumer without a `userId` still authenticates (consumer identity).

Rejection is asserted through the exception the endpoint runtime already maps to
HTTP 401 in `processAuthenticationRule()`.
