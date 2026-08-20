# Test Plan: ori-public-serving

Deploy the 10 openconnector ORI Endpoints under a **non-production path
prefix during validation** (e.g. `/api/ori-parity/v1/*`, only re-pointed to
the real `/api/ori/v1/*` mount by the companion decidesk retirement change
once every TC below is green) so decidesk's live `OriController` keeps
serving the public path throughout this change's validation window. Every
parity TC below runs the same request against both implementations and
diffs the response.

## Test Cases

### TC-1: Anonymous list request needs no credentials
- **spec_ref**: `openspec/changes/ori-public-serving/specs/ori-public-serving/spec.md#requirement-anonymous-ori-resource-dispatch-req-oripub-001`
- **type**: api
- **preconditions**: No `Authorization` header, no Nextcloud session cookie
- **steps**: `GET /api/ori-parity/v1/events` with no auth
- **expected result**: HTTP 200, no 401 challenge
- **test command**: `/test-api`

### TC-2: Unknown resource slug 404s
- **spec_ref**: `openspec/changes/ori-public-serving/specs/ori-public-serving/spec.md#requirement-anonymous-ori-resource-dispatch-req-oripub-001`
- **type**: api
- **preconditions**: none
- **steps**: `GET /api/ori-parity/v1/not-a-real-resource`
- **expected result**: HTTP 404
- **test command**: `/test-api`

### TC-3: Parity — collection response byte-for-byte diff, all 10 resources
- **spec_ref**: `openspec/changes/ori-public-serving/specs/ori-public-serving/spec.md#requirement-fixed-per-resource-filter-injection-req-oripub-002` and `#requirement-field-projection-to-ori-popolo-json-ld-shape-req-oripub-003`
- **type**: regression
- **preconditions**: Same decidesk dataset seeded once, read by both implementations (no writes between the two calls)
- **steps**: for each of `organizations`, `persons`, `memberships`, `events`, `agendaitems`, `motions`, `amendments`, `voteevents`, `votes`, `reports`, `publications`: call `GET /api/ori/v1/{resource}` on decidesk's live controller and `GET /api/ori-parity/v1/{resource}` on the new Endpoint, normalise volatile fields (none expected — ORI ids/dates are stable per object), diff the two JSON bodies
- **expected result**: identical `@context`, `@type`, `count`, and `items` (field-for-field, order-insensitive) on all 10 resources
- **test command**: `/test-api` (scripted diff, not manual)

### TC-4: Draft/unpublished objects excluded from every gated collection
- **spec_ref**: `#requirement-fixed-per-resource-filter-injection-req-oripub-002`
- **type**: api
- **preconditions**: decidesk dataset includes at least one `draft` `Meeting`, one non-`public` `Decision`
- **steps**: `GET /api/ori-parity/v1/events` and `GET /api/ori-parity/v1/motions`
- **expected result**: the draft meeting and non-public decision are absent from `items`, matching `OriController`'s current filtering
- **test command**: `/test-api`

### TC-5: motions/amendments discriminator on the list path
- **spec_ref**: `#requirement-fixed-per-resource-filter-injection-req-oripub-002`
- **type**: api
- **preconditions**: decidesk dataset includes both `decisionType: motion` and `decisionType: amendment` published `Decision` objects
- **steps**: `GET /api/ori-parity/v1/motions`, `GET /api/ori-parity/v1/amendments`
- **expected result**: each resource returns only its own `decisionType`, zero cross-contamination
- **test command**: `/test-api`

### TC-6: Field projection — fallback chain and conditional email field
- **spec_ref**: `#requirement-field-projection-to-ori-popolo-json-ld-shape-req-oripub-003`
- **type**: api
- **preconditions**: a `Meeting` with `title` set and no `name`; a `GovernanceBody` with `email` set
- **steps**: `GET /api/ori-parity/v1/events/{id}`, `GET /api/ori-parity/v1/organizations/{id}`
- **expected result**: `name` resolves from `title`; `email` present on the organization, absent when the same field is checked on a `Meeting`/`Event`-typed response
- **test command**: `/test-api`

### TC-7: Collection envelope shape matches ORI, not the default pagination envelope
- **spec_ref**: `#requirement-field-projection-to-ori-popolo-json-ld-shape-req-oripub-003`
- **type**: api
- **preconditions**: none
- **steps**: `GET /api/ori-parity/v1/events`
- **expected result**: top-level keys are exactly `@context`, `@type`, `count`, `items` — no `results`/`next`/`previous` leak through from the default envelope (this is the acceptance test for design.md Gap 1)
- **test command**: `/test-api`

### TC-8: Single-item discriminator gate — wrong sub-type 404s
- **spec_ref**: `openspec/changes/ori-public-serving/specs/ori-public-serving/spec.md#requirement-single-item-404-non-disclosure-across-discriminator-lifecycle-and-publish-window-gates-req-oripub-004` and `openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-declarative-id-fetch-guard-for-single-object-get-req-ep-010`
- **type**: security
- **preconditions**: a published `Decision` object with `decisionType: amendment` at id `X`
- **steps**: `GET /api/ori-parity/v1/motions/X`
- **expected result**: HTTP 404 — this is the acceptance test for design.md Gap 2/Risk 1, and MUST fail before the guard (REQ-EP-010) is implemented, proving the test actually exercises the gap rather than passing vacuously
- **test command**: `/test-security`

### TC-9: Single-item lifecycle gate — draft object 404s
- **spec_ref**: `#requirement-single-item-404-non-disclosure-across-discriminator-lifecycle-and-publish-window-gates-req-oripub-004`
- **type**: security
- **preconditions**: a `Meeting` object with `lifecycle: draft` at id `Y`
- **steps**: `GET /api/ori-parity/v1/events/Y`
- **expected result**: HTTP 404, matching `OriController::show()`'s `isLifecycleBlocked()` behaviour today
- **test command**: `/test-security`

### TC-10: Single-item publish-window gate — future-dated publication 404s
- **spec_ref**: `#requirement-single-item-404-non-disclosure-across-discriminator-lifecycle-and-publish-window-gates-req-oripub-004`
- **type**: security
- **preconditions**: a `PublicationPayload` object at id `Z` with `publicationDate` in the future
- **steps**: `GET /api/ori-parity/v1/publications/Z`
- **expected result**: HTTP 404. Run this test TWICE — once calling `OriController`'s existing `/api/ori/v1/publications/Z` (control, expected to already 404 via `isPayloadLive()`), once via the new Endpoint. If the new Endpoint returns 200 while the control returns 404, that is empirical proof that Risk 3 (RBAC propagation through openconnector's `ObjectService`) does NOT hold and the publish-window gate needs its own explicit fix, not just REQ-EP-010's discriminator/lifecycle coverage
- **test command**: `/test-security`

### TC-11: 404 non-disclosure — no 403 anywhere on the ORI surface
- **spec_ref**: `#requirement-single-item-404-non-disclosure-across-discriminator-lifecycle-and-publish-window-gates-req-oripub-004`
- **type**: security
- **preconditions**: TC-8, TC-9, TC-10 fixtures
- **steps**: repeat TC-8/9/10 and assert on the exact status code
- **expected result**: every case returns 404, never 403 — an anonymous caller must not be able to distinguish "hidden" from "unknown" by status code
- **test command**: `/test-security`

### TC-12: Anonymous rate limit parity
- **spec_ref**: `openspec/changes/ori-public-serving/contract.md#error-codes`
- **type**: performance
- **preconditions**: none
- **steps**: send 121 requests to `GET /api/ori-parity/v1/events` within 60 seconds
- **expected result**: the 121st request returns 429, matching decidesk's current `AnonRateLimit(limit: 120, period: 60)`
- **test command**: `/test-performance`

### TC-13: CORS preflight parity
- **spec_ref**: `openspec/changes/ori-public-serving/contract.md#endpoints`
- **type**: api
- **preconditions**: none
- **steps**: `OPTIONS /api/ori-parity/v1/events` and `OPTIONS /api/ori-parity/v1/events/{id}`
- **expected result**: HTTP 200 with `Access-Control-Allow-*` headers equivalent to `OriController::applyCorsHeaders()` today
- **test command**: `/test-api`

### TC-14: notubiz-ibabs-griffie-koppeling overlap does not regress existing RIS polling
- **spec_ref**: `openspec/changes/ori-public-serving/proposal.md#impact`
- **type**: regression
- **preconditions**: `RISPollJob`/`NotuBizConnectorService`/`IBabsConnectorService` running as today
- **steps**: run the existing RIS poll job after this change's Endpoints are deployed (validation prefix)
- **expected result**: no interaction/regression — this change adds new Endpoints, does not touch the existing connector services; confirms the two proposals are genuinely independent surfaces despite the naming overlap
- **test command**: `/test-regression`

## Coverage Summary

| Requirement | Covered by | Status |
|---|---|---|
| REQ-ORIPUB-001 (anonymous dispatch) | TC-1, TC-2 | covered |
| REQ-ORIPUB-002 (fixed filter injection) | TC-3, TC-4, TC-5 | covered |
| REQ-ORIPUB-003 (field projection + envelope) | TC-3, TC-6, TC-7 | covered |
| REQ-ORIPUB-004 (single-item 404 non-disclosure) | TC-8, TC-9, TC-10, TC-11 | covered — TC-8/9 MUST fail before REQ-EP-010 ships (proves the test is real) |
| REQ-EP-010 (declarative id-fetch guard) | TC-8, TC-9 | covered |
| Rate limiting (design.md D2) | TC-12 | covered |
| CORS (design.md, Security Considerations) | TC-13 | covered |
| notubiz-ibabs-griffie-koppeling non-regression | TC-14 | covered |

## Out of Scope

- `OriPublicationService` (outbound push to external ORI endpoints) — not
  touched by this change, no test cases here; see proposal.md Out of Scope.
- Gap 1's implementation mechanism (single mapping recipe vs. two chained
  after-rules) is not itself tested — only its observable output (TC-7). How
  the mapping is composed internally is an implementation detail the test
  plan is deliberately indifferent to.
- Load/scale testing beyond the rate-limit ceiling (TC-12) — no new
  performance characteristics are introduced versus openconnector's existing
  endpoint-runtime cache behaviour (`endpoint-runtime` REQ-EP-004), so no
  dedicated load test is added.
