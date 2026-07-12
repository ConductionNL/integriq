# Tasks — LTI 1.3 / LTI Advantage adapter (Platform + Tool roles)

> Implementation order follows the data model outward: register schema first
> (nothing else compiles/tests without it), then the service layer
> (`lib/Service/Lti/*`, ADR-008 Controller → Service → Mapper), then the
> public HTTP surface, then the tenant-wide key-management UI, then tests,
> then docs. Key generation/rotation (Phase 4) depends on `LtiKeyService`
> from Phase 2, so it is sequenced after the service layer even though the
> user-facing "key mgmt" grouping is called out separately per the brief.

## 1. Register schema (openconnector-register-schema REQ-A-001/002, lti-platform REQ-LTI-001)

- [ ] 1.1 Add `lti_platform` schema to `lib/Settings/openconnector_register.json`: `issuer`, `clientId` (our assigned id), `authLoginUrl`, `authTokenUrl`, `jwksUri`, `signingKeys[]` (`kid`, `algorithm`, `publicJwk`, `privateKeySecret`, `status`, `rotatedAt` — REQ-LTI-002 shape), timestamps
- [ ] 1.2 Add `lti_tool` schema: `clientId` (assigned to the tool), `oidcLoginUrl`, `launchUrl`, `jwksUri`, `signingKeys[]` (same shape as 1.1), timestamps
- [ ] 1.3 Add `lti_deployment` schema: `deploymentId` (LTI claim value), `ltiPlatformId`/`ltiToolId` (exactly-one relation, both nullable, validated per REQ-LTI-001 scenario 2), `launchTargetUrl`, `gradeSink` (`{targetType, targetId}`), `rosterSource` (`{targetType, targetId}`), `mappingId`
- [ ] 1.4 Register all three under `components.registers[openconnector].schemas` (16 → 19); update the `x-openregister` schema count/list comment if present
- [ ] 1.5 Add an object-level validator (or OR schema-level `oneOf`/conditional) enforcing `lti_deployment` references exactly one of `ltiPlatformId`/`ltiToolId` — reject both-set and neither-set at save time, not just at read time
- [ ] 1.6 Seed data (REQ-A-007 — mutable schemas only, log schemas excluded): confirm no default rows are required for `lti_platform`/`lti_tool`/`lti_deployment` (empty-by-default, matching every other catalogue-style mutable schema); explicitly do NOT add them to any seed file if the convention is empty
- [ ] 1.7 `openspec sync` (or manual merge) the `openconnector-register-schema` delta in this change into `openspec/specs/openconnector-register-schema/spec.md` once implemented — this also corrects that base spec's pre-existing drift (it still says "15 schemas" and omits `ris_sync_record`, verified stale against HEAD's actual 16 during this change's review)

## 2. Backend services (`lib/Service/Lti/*`, ADR-008 Controller → Service → Mapper)

- [ ] 2.1 `LtiKeyService`: generate a fresh RS256/PS256 keypair and set `active` (REQ-LTI-002); rotate (`active`→`previous`, new `active`, 7-day grace window); a scheduled sweep (NC background job, mirroring `EventRetryJob`'s cron-registration pattern) that flips `previous`→`retired` past the grace window and drops `retired` keys from the published set
- [ ] 2.2 `LtiJwksResolverService` (REQ-LTI-003): resolve `jwks_uri`→`JWKSet` via the existing `CallService`/`http-call-engine` machinery (not a bare HTTP client); cache under `ICacheFactory::createDistributed('openconnector.lti.jwks')` keyed `jwks:<registrationType>:<registrationUuid>`, 1h default TTL; `kid` lookup; on-miss refetch guarded to at most once per 60s per registration via a `jwks:refetch:<registrationUuid>` cache key; fail closed (reject) while the guard is active
- [ ] 2.3 `LtiLaunchService::validateLaunch()` (REQ-LTI-005): JWS signature verification against a REQ-LTI-003-resolved JWK; delegate `iat`/`exp`/`nbf` to the existing `AuthorizationService::validatePayload()` (no reimplementation); `aud`/`azp` match; `nonce` presence + atomic get-then-delete consume from `openconnector.lti.nonce` (new namespace, distinct from `openconnector.jti`); `deployment_id` claim resolves to a registered `lti_deployment` under the token's `lti_platform`; `message_type`/`version` (`"1.3.0"`) present and recognised; any failure is a hard 401/400 reject, no partial-trust path
- [ ] 2.4 `LtiLaunchService`: OIDC login-initiation logic (REQ-LTI-004) — validate `iss`/`client_id` against a registered `lti_platform`, generate `state`+`nonce`, persist `nonce` under `openconnector.lti.nonce` (`nonce:<registrationUuid>:<nonce>`, 10-min TTL), `state` round-tripped via `SameSite=None; Secure` cookie
- [ ] 2.5 `LtiLaunchService`: Platform-role launch initiation (REQ-LTI-006) — third-party-initiated login redirect to the `lti_tool`'s `oidc_login_url`, then an auto-submitting form POSTing an `id_token` signed with that registration's `active` key (via `LtiKeyService`) to `launch_url`; `message_type` = `LtiResourceLinkRequest` or `LtiDeepLinkingRequest`
- [ ] 2.6 `LtiLaunchService`: Deep Linking 2.0 both directions (REQ-LTI-006) — construct+sign `LtiDeepLinkingResponse` (Tool role) POSTed to `deep_link_return_url`; verify an inbound `LtiDeepLinkingResponse` identically to 2.3 (Platform role) and hand parsed content items back to the initiating consuming app
- [ ] 2.7 `LtiAgsService`: `POST /api/lti/token` RFC 7523 JWT-bearer client-credentials grant (REQ-LTI-007) — resolve issuing `lti_tool` by `iss`/`sub`, verify assertion signature via 2.2, issue a short-lived access token scoped to one `lti_deployment` + requested AGS/NRPS scope(s) only (never cross-deployment)
- [ ] 2.8 `LtiAgsService`: inbound line-item read / score-POST enforcement of the `.../lineitem` / `.../score` scopes from 2.7's tokens; translate a received score into a `nl.conduction.lti.ags.score.received` CloudEvent published via the existing `EventService::processEvent()` (unmodified) — no direct write to `lti_deployment.gradeSink`
- [ ] 2.9 `LtiAgsService`: Tool-role outbound score publish / result read (REQ-LTI-008) reusing `AuthenticationService::fetchOAuthTokens()` with `client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer` against the `lti_platform`'s `auth_token_url`, dispatched through the existing outbound HTTP call machinery (CallLog observability, no silent-drop on failure)
- [ ] 2.10 `LtiNrpsService`: inbound roster read (REQ-LTI-009, Platform role) — dispatch `lti_deployment.rosterSource` as an ADR-008 `targetType: 'register/schema'` read through the same mapper path `EndpointService::handleSchemaRequest()` uses (`lib/Service/EndpointService.php:1052`), transform via the deployment's mapping into the IMS Names/Roles JSON shape, return synchronously (no CloudEvent)
- [ ] 2.11 `LtiNrpsService`: Tool-role outbound roster pull reusing the same JWT-bearer client-credentials mechanism as 2.9, scoped to `.../contextmembership.readonly`
- [ ] 2.12 Add the `nl.conduction.lti.ags.score.received` CloudEvent type constant where the codebase's existing CloudEvent type constants live; confirm `events-cloudevents` REQ-001's existing type-matching fan-out picks it up with zero changes to `EventService`

## 3. Endpoints (`lib/Controller/LtiController.php`, `appinfo/routes.php`)

- [ ] 3.1 `LtiController` mirroring `DSOController`'s dedicated-controller shape (not the generic `Endpoint` pipeline): `login()`, `launch()`, `token()`, AGS line-item/score actions, NRPS membership action, JWKS-publish action — thin controllers delegating to the Phase 2 services
- [ ] 3.2 `appinfo/routes.php`: `GET/POST /api/lti/{deployment}/login`, `POST /api/lti/{deployment}/launch`, `POST /api/lti/token`, AGS line-item/score routes, NRPS membership route, `GET /.well-known/lti/{registrationType}/{registrationUuid}/jwks.json` — every route carries an explicit `#[PublicPage]`/`#[NoCSRFRequired]`/`#[NoAdminRequired]` posture (hydra-gate-route-auth) matching who actually calls it (external platforms/tools, unauthenticated by NC session)
- [ ] 3.3 JWKS-publish endpoint (REQ-LTI-002): returns `active` + any `previous` (grace-window) public keys as a JWKS document per registration; `retired` keys never appear
- [ ] 3.4 Reject-before-redirect behaviour on `login()` (REQ-LTI-004 scenario: unregistered `iss`/`client_id` → HTTP 400, no redirect, no nonce persisted) and hard-reject behaviour on `launch()`/`token()`/AGS/NRPS (401/400/403 per the specific failure, no partial-trust fallback anywhere in this controller)
- [ ] 3.5 Per-deployment access-token scope enforcement at the AGS/NRPS route layer (REQ-LTI-007 scenario: a token scoped to deployment A rejected with 403 against deployment B's endpoints)

## 4. Key management (tenant-wide, Beheer > Authenticatie + Adapters catalogue)

- [ ] 4.1 Admin-gated, CSRF-protected endpoints to generate/rotate a registration's signing keys (mirrors `webhook-signing` REQ-WHS-002's generate/rotate shape) — wired to `LtiKeyService` (2.1)
- [ ] 4.2 *Beheer > Authenticatie* UI: registration list (both `lti_platform` and `lti_tool`) + per-registration key lifecycle view (active `kid`, previous `kid` + grace-window countdown, rotate action) per ADR-017 Rule 3/7
- [ ] 4.3 *Adapters* catalogue card: "LTI 1.3 / LTI Advantage" entry (discovery, capability description) referencing the three REQ-LTI-001 schemas as its configuration surface; no top-level menu item added
- [ ] 4.4 *Verbindingen* new-connection wizard step for registering an `lti_platform`/`lti_tool`/`lti_deployment` (per design.md D2)
- [ ] 4.5 Own-key custody note surfaced in the UI/docs where the key material lives (plaintext-pending-encryption per ADR-007, same as every other `Source` secret) — no UI implies stronger custody than what actually exists (design.md D3)

## 5. Tests

- [ ] 5.1 PHPUnit: `LtiKeyServiceTest` — generation, rotation (active→previous→retired timing), grace-window JWKS content, published-set never contains `retired` keys
- [ ] 5.2 PHPUnit: `LtiJwksResolverServiceTest` — cache hit, cache miss + refetch, 60s refetch-guard (exactly one outbound fetch under repeated unknown-`kid` requests), per-registration cache namespacing (two registrations sharing a `jwks_uri` do not cross-poison)
- [ ] 5.3 PHPUnit: `LtiLaunchServiceTest` — valid login-initiation redirect (state+nonce present, 400 on unregistered issuer with no redirect/no persisted nonce), valid launch redirect, nonce-replay rejection (401), unregistered `deployment_id` rejection (400), `aud`/`azp` mismatch rejection, expired/premature token rejection (via the reused `AuthorizationService::validatePayload()` path)
- [ ] 5.4 PHPUnit: Deep Linking — Platform-role launch signed with the correct `lti_tool` active key, inbound `LtiDeepLinkingResponse` verified before content items are returned to the consuming app, rejected on verification failure
- [ ] 5.5 PHPUnit: `LtiAgsServiceTest` — token-endpoint client-assertion exchange (valid + invalid signature/`iss`/`sub`), deployment-scoped token issuance, cross-deployment 403, score-received CloudEvent published with correct type/payload and NOT written to `gradeSink` directly, Tool-role outbound score publish reusing `fetchOAuthTokens()`, token-endpoint failure surfaced via CallLog (not silently dropped)
- [ ] 5.6 PHPUnit: `LtiNrpsServiceTest` — roster request served via the ADR-008 `register/schema` read path, synchronous (no CloudEvent), missing/wrong-scope request rejected 403, Tool-role outbound roster pull reuses the JWT-bearer grant
- [ ] 5.7 PHPUnit: consuming-app placement contract (REQ-LTI-010) — an `lti_deployment` with no `event_subscription` still gets a CloudEvent created (zero-subscriber fan-out is not an error, per existing `events-cloudevents` behaviour)
- [ ] 5.8 Security-focused tests: algorithm-confusion attempt (token header alg doesn't match the resolved JWK's pinned alg) rejected; replayed `jti`/`nonce` rejected; a forged `id_token` with a valid-looking but wrong-`kid` signature rejected; JWKS refetch-guard prevents unbounded outbound requests under a fabricated-`kid` flood (SSRF-amplification guard from design.md D4)
- [ ] 5.9 Newman/API collection: full login→launch→AGS token→score-POST→CloudEvent-received happy path against a seeded `lti_platform`+`lti_deployment`+`event_subscription`; NRPS roster read happy path
- [ ] 5.10 Regression: existing `AuthenticationService`/`AuthorizationService`/`EventService`/`EndpointService` test suites remain green — confirms the reused mechanisms (jti replay, `validatePayload`, CloudEvent fan-out, `register/schema` targetType dispatch) are genuinely unmodified, not silently altered by this change
- [ ] 5.11 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) clean on all new/touched files
- [ ] 5.12 Hydra gates green diff-scoped (`run-hydra-gates.sh --scope-to-diff`) — spdx-headers, route-auth, route-reachability, spec-coverage (`@spec` tags on every changed method referencing the relevant REQ-LTI-*), no-admin-idor on the admin key-mgmt endpoints, unsafe-auth-resolver on the token-validation paths, forbidden-patterns, stub-scan

## 6. Docs

- [ ] 6.1 `website/docs` (or equivalent) page: LTI 1.3 / LTI Advantage adapter overview, how to register as Platform vs Tool, the two-object placement contract for consuming apps (REQ-LTI-010), key rotation operational notes (7-day grace window, what happens if a platform's JWKS cache is stale)
- [ ] 6.2 CHANGELOG entry (union-merge convention — grep for existing markers before editing, per this repo's established gotcha)
- [ ] 6.3 Cross-link from this change's docs to the scholiq `lti-tool-placement` leaf (separate repo) as the reference consumer of the contract

Acceptance criteria (plain bullets — verified by /opsx-verify):

- `lib/Settings/openconnector_register.json` declares 19 schemas (16 existing + `lti_platform`/`lti_tool`/`lti_deployment`); an `lti_deployment` referencing both or neither registration is rejected at save time
- A registered external Platform can complete OIDC login + signed launch into this instance (Tool role), landing on `lti_deployment.launchTargetUrl` with a single-use launch reference; a replayed `nonce` or unregistered `deployment_id` is rejected, never partially trusted
- This instance can launch a registered external Tool (Platform role) with an `id_token` signed by that registration's own `active` key, and complete a Deep Linking 2.0 round trip in both directions
- An AGS score POST from an authorized Tool results in exactly one `nl.conduction.lti.ags.score.received` CloudEvent, never a direct write into any consuming-app-owned register; an access token scoped to one `lti_deployment` cannot read or write another deployment's line items
- An NRPS roster request is served synchronously from the deployment's configured `register/schema` source with no CloudEvent involved
- Key rotation keeps a `previous` key valid for verification through its 7-day grace window and removes it from the published JWKS once `retired`; two registrations sharing a `jwks_uri` never share a cache entry; an unknown `kid` triggers at most one refetch per 60 seconds per registration
- A consuming app fully wires a placement with exactly one `lti_deployment` + one `event_subscription` — no other openconnector-side configuration required
- Every reused mechanism (`AuthorizationService::validatePayload`/jti-replay, `AuthenticationService::fetchOAuthTokens`, `EventService::processEvent`, `EndpointService`'s `register/schema` targetType dispatch) is provably unmodified — existing regression suites for those services stay green
