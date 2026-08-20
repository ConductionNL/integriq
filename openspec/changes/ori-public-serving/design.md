# Design: ori-public-serving

## Architecture Overview

Today decidesk's `OriController` is a standalone HTTP surface: five
`#[PublicPage]` methods dispatch on a `{resource}` path parameter, look up a
register/schema pair from a hard-coded `RESOURCE_MAP`, call
`OCA\OpenRegister\Service\ObjectService` directly, and hand the result to
`OriSerializer` for JSON-LD field renaming before wrapping it in a
`{@context, @type, count, items}` envelope.

This change replaces that bespoke path with **10 openconnector `Endpoint`
objects** (one per ORI resource — `motions` and `amendments` both target the
decidesk `decision` schema, discriminated the same way
`OriController::DECISION_TYPE_MAP` does today), each:

- `targetType: register/schema`, `targetId: "{decideskRegisterId}/{schemaId}"`
- No `authentication` rule → served anonymously (`EndpointService`'s
  `processAuthenticationRule` only runs when a rule of type `authentication`
  is configured; its absence is not an error, it is the "no auth required"
  state — `lib/Service/EndpointService.php:2961`)
- An `inputMapping` that injects the resource's fixed filter set ahead of
  `getObjects()`'s `findAllPaginated()` call
- A `mapping`-type after-rule that reproduces `OriSerializer`'s field
  projection

```
GET /api/ori/v1/{resource}          openconnector public dispatch (REQ-EP-001)
        │                            (endpoint cache resolves path+method)
        ▼
  Endpoint(resource)
        │  inputMapping: inject lifecycle/isPublished/decisionType filters
        ▼
  handleSchemaRequest() → getObjects() → OR ObjectService::findAllPaginated()
        │  {count, results, next, previous}
        ▼
  after-rule: mapping (field projection + envelope reshape — Gap 1, below)
        ▼
  JSON-LD response
```

For `GET /api/ori/v1/{resource}/{id}` the same Endpoint's id-branch bypasses
`inputMapping`-injected filters entirely (`getObjects()`'s id-path calls
`$mapper->find($id, ...)` with no parameters) — this is Gap 2, below, and is
the change's single biggest open engineering question.

## Goals / Non-Goals

**Goals**
- Reproduce `OriController`'s observable HTTP contract (paths, methods,
  filters, field names, envelope shape, anonymous access) as openconnector
  configuration, so the companion decidesk change can retire the controller
  with zero observable difference to a public caller.
- Be honest about what the existing engine cannot do today rather than
  shipping a narrower public API silently.
- Keep decidesk owning its data and the config *content*; openconnector owns
  the serving *mechanism* it already runs for every other integration.

**Non-Goals**
- Rewriting `MappingService`/`EndpointService` wholesale — any engine
  addition proposed here is the smallest change that closes a named gap, not
  a redesign.
- Touching `OriPublicationService` (outbound push) — different direction of
  data flow, unrelated to this change.
- Deciding *for* decidesk when to cut over — that is the companion change's
  call, gated on this change's parity test plan.

## Declarative-vs-imperative decision (ADR-031)

Everything in the happy path (routing, filtering, field mapping, anonymous
auth) is expressed as `Endpoint`/`Mapping`/rule configuration — no new
PHP Service class is introduced for the 10-resource surface. Where the
existing engine cannot express current behaviour (Gaps 1 and 2), the proposed
fix is a small addition to existing engine methods
(`EndpointService::getObjects()` / `processRules()`), not a new bespoke
Service — this keeps the "engine owns serving machinery, config owns
behaviour" split intact rather than reintroducing an imperative
`OriController`-shaped class one repo over.

## Decisions

### D1 — One Endpoint per ORI resource, not one wildcard `{resource}` endpoint

`OriController` uses a single controller method with a `{resource}` path
parameter and an in-PHP dispatch table (`RESOURCE_MAP`). openconnector's
Endpoint model has no equivalent "one endpoint, N backing schemas" concept —
each Endpoint has exactly one `targetId`. The natural fit is 10 Endpoints,
each with its own path (`/api/ori/v1/organizations`, `/api/ori/v1/persons`,
…). This is a structural difference from today's implementation, not a
behavioural one: the public URL shape is unchanged, only where the dispatch
table lives (in 10 rows of `register.d` config vs. one PHP `const array`)
changes. `motions` and `amendments` are two Endpoints both targeting
`decidesk/decision`, each injecting a different fixed `decisionType` filter
via `inputMapping` — this reproduces `DECISION_TYPE_MAP` for the list path
(see Gap 2 for why the single-item path does not get this for free).

### D2 — Anonymous serving = absence of an `authentication` rule

Confirmed by reading `EndpointService::processRules()`/
`processAuthenticationRule()`: the `authentication`-type rule is opt-in.
`isset($configuration['authentication']) === false` short-circuits to
"no auth required" — there is no separate "explicitly public" flag to set.
This is exactly decidesk's `#[PublicPage]` today. The 10 ORI Endpoints
simply carry no `authentication` rule. Rate-limiting
(`AnonRateLimit(limit: 120, period: 60)` on decidesk's controller today) maps
to openconnector's existing consumer-management rate-limit machinery
(`consumer-management` spec, `REQ-CON-RL-*`) — sized in `tasks.md`, not a
gap, since that machinery already exists and already runs ahead of
authentication in the pipeline.

### D3 — Fixed filters via `inputMapping`, not a new "static filter" config surface

`OriController::buildFilters()` always adds `register`/`schema` plus,
per-resource, `lifecycle=published`, or `isPublished=public` +
`decisionType=<motion|amendment>`, or nothing (the two lifecycle-free
resources, `persons`/`memberships`). openconnector has no dedicated "static
filter" field on `Endpoint`, but `inputMapping` already runs on inbound
request parameters before `getObjects()` (`handleSchemaRequest()` line 1890)
and a `Mapping` recipe can emit fixed literal values regardless of input
(`mapping-and-search` REQ-001) — so a per-resource `inputMapping` with
`passThrough: true` plus fixed-literal keys (`lifecycle: "published"`, etc.)
reproduces `buildFilters()` exactly for the **list** path. This is the
mechanism the risk register calls "not a gap" — it works today, unmodified.

### D4 — Field projection as a `mapping`-type after-rule

`OriSerializer::applyRules()` is a target-key → ordered-source-list table
where the first present source wins (`FIELD_RULES`, `PAYLOAD_FIELD_RULES`).
This is a direct fit for `MappingService::executeMapping()`'s dot-path-copy
semantics (`mapping-and-search` REQ-001, "Direct dot-path copy takes
precedence over Twig" scenario) — a mapping key `"name": "title"` copies
`title` when present; a fallback chain (`name` from `title` else `name`) is
one extra Twig conditional (`{{ title|default(name) }}`). The
`EMAIL_TYPES`-gated conditional field (`email` only on `Organization`/
`Person`) is an `{% if %}` in the same recipe. This is squarely within the
engine's documented capability — no gap.

## Mapping-engine gap assessment

Two behaviours of the current `OriController`/`OriSerializer` cannot be
reproduced by Endpoint + Mapping configuration alone, given the engine as it
exists today (verified by reading `EndpointService::handleSchemaRequest()`,
`getObjects()`, and `processRules()`, not assumed):

### Gap 1 — No output-reshaping hook on the register/schema GET response envelope

`getObjects()` builds `{count, results, next, previous}` and
`handleSchemaRequest()`'s GET branch wraps it directly in a `JSONResponse`
(`EndpointService.php:1942-1949`) with no `outputMapping`/`mappingOutId`
applied on that path (the `mappingOutId` config referenced elsewhere in the
file, around line 3866-3881, is wired into the source/`api`-target dispatch
path, not `register/schema`). ORI needs `{@context, @type, count, items}` —
different top-level keys, plus a fixed `@context`/`@type` that has no
equivalent in the default envelope.

What **does** exist: a `mapping`-type after-rule runs against the full
response after `handleSchemaRequest()` returns
(`processRules()`/`processMappingRule()`, confirmed wired at both before- and
after-timing call sites in `handleRequest()`). Whether **one** mapping
recipe can (a) rename `results`→`items`, (b) inject fixed `@context`/`@type`
literals, and (c) per-item map each element's fields (title→name, etc.) in a
single pass is not established by reading the spec alone — `mapping-and-search`
documents "list mode" (map each element of a list input) and "root-level
output via `#`" as *separate* modes, and it is not obvious they compose
inside one recipe when the input is `{count, results: [...], next, previous}`
(a non-list top-level shape containing a list field).

**Resolution proposed**: implementation-time spike, first attempt as a single
recipe (mapping the top-level object with `results` mapped via a nested
list-mode sub-recipe under the `items` key, `count` copied straight,
`@context`/`@type` as fixed literals, `next`/`previous` dropped via
`unsetIfValue`-style directives or simply omitted from the recipe). If that
does not work, fall back to **two chained `mapping`-type after-rules**: one
does list-mode per-item field projection, the second reshapes the envelope
around the now-projected list. Either resolution stays inside existing engine
capability (no new PHP) — this is a config-composition question, not a
missing capability, but it must be verified rather than assumed, hence its
own task in `tasks.md` rather than folded silently into "just write the
mapping."

### Gap 2 — Single-item GET-by-id bypasses all filters, including the discriminator/lifecycle/publish-window gate

This is the substantive gap. `getObjects()`'s id-branch
(`EndpointService.php:1737-1754`) calls `$mapper->find($pathParams['id'],
...)` directly — no `inputMapping`-injected filters, no parameters, nothing
from D3 applies to this path. `OriController::show()` enforces three things
in PHP that this bypasses entirely:

1. **`narrowToDecisionType()`** — `/api/ori/v1/amendments/{id}` must 404 if
   the object at that id is actually a `motion` (both share the `decision`
   schema). openconnector's id-fetch has no discriminator concept.
2. **`isLifecycleBlocked()`** — a non-`published` object (draft, closed) must
   404 for anonymous callers on lifecycle-gated resources. Not an OR-RBAC
   rule today (decidesk's own comment: "M2: only enforce the lifecycle gate
   when the object actually carries a lifecycle field" — this is
   application logic, not schema-declared `x-openregister-authorization`).
3. **`isPayloadLive()`** (publications resource only) — a future-dated or
   depublished `PublicationPayload` must 404. decidesk's own code comments
   that this **is** additionally covered by OR's `x-openregister-authorization`
   RBAC on the schema (`publicationDate <= $now`), enforced at the OR storage
   layer regardless of caller — so `find()` already throws
   `DoesNotExistException` for this one case *if* that RBAC evaluates the
   same way when reached via openconnector's `ObjectService` as it does via
   decidesk's own. That "if" is Risk 3 in the proposal and needs empirical
   verification, not an assumption.

**What this means concretely**: without a fix, an anonymous caller who
enumerates or guesses a UUID for `/api/ori/v1/motions/{id}` would receive
that object even if it is actually an amendment, a draft, or (once
`isPayloadLive` is confirmed NOT covered by RBAC in this call path) a
not-yet-published publication payload. That is a real behavioural
regression versus today's `OriController`, not a cosmetic one — it must be
closed before cutover, not deferred as a "known limitation."

**Resolution proposed** (decision needed — see proposal's Open Questions):

- **Option A** (preferred, smaller blast radius): add a small, declarative
  "id-fetch guard" to `EndpointService::getObjects()`'s id-branch — when the
  Endpoint config carries the same fixed-filter set `inputMapping` already
  injects for the list path, re-check the fetched object against that filter
  set post-fetch and 404 if it does not match (mirrors
  `narrowToDecisionType`/`isLifecycleBlocked` exactly, but declaratively).
  Scoped, testable, reusable by any future Endpoint with the same shape of
  problem (any schema serving two discriminated sub-types or a lifecycle
  gate through one Endpoint).
- **Option B** (larger, more consequential, but closes the gap for
  decidesk's own remaining direct API too): push `lifecycle`/`decisionType`
  into OR-level `x-openregister-authorization` on the decidesk schemas, the
  same way `PublicationPayload`'s publish-window already works, so OR denies
  the read unconditionally regardless of caller. This is architecturally
  cleaner (RBAC lives once, at the data layer) but is decidesk schema work,
  not an openconnector engine change, and is out of this change's Impact
  section as scoped — flagged as an alternative worth a real decision before
  implementation, not decided unilaterally here.

`tasks.md` sizes Option A as the default implementation path (keeps this
change self-contained inside openconnector) and records Option B as a
recommendation to raise with decidesk's `ori-adoption` owners.

## API Design

### `GET /api/ori/v1/{resource}`
**Auth**: none (anonymous; see D2)

**Response (200):**
```json
{
  "@context": "https://argu.co/ns/core",
  "@type": "Event",
  "count": 2,
  "items": [
    { "@context": "https://argu.co/ns/core", "@type": "Event", "id": "<uuid>", "name": "Raadsvergadering", "start_date": "2026-09-01T19:00:00+00:00", "status": "published" }
  ]
}
```

### `GET /api/ori/v1/{resource}/{id}`
**Auth**: none (anonymous; see D2)

**Response (200):** single JSON-LD resource, same field shape as one `items`
entry above.

**Response (404):** `{"message": "Not found", "code": 404}` — returned both
for an unknown id and for an id that exists but fails the
discriminator/lifecycle/publish-window gate (Gap 2), matching
`OriController`'s deliberate non-disclosure behaviour (404, never 403, so an
anonymous caller cannot distinguish "unknown" from "hidden").

### `OPTIONS /api/ori/v1/{resource}` and `/api/ori/v1/{resource}/{id}`
CORS preflight — openconnector's existing `preflightedCors` (REQ-EP-001)
covers this without per-resource configuration.

## Database Changes

None. No new OpenRegister schema definitions. Two candidate small additions
to existing `EndpointService`/`MappingService` PHP methods if Gap 1/Gap 2 are
closed via Option A (see `tasks.md` for sizing) — modifications to existing
code, not new tables/columns.

## Nextcloud Integration

- Controllers: none new — reuses `EndpointsController`/dispatch.
- Services: `EndpointService`, `MappingService` (existing); two candidate
  small method additions if Gap 1/Gap 2 are closed rather than deferred.
- Mappers/Entities: reuses OpenRegister's `ObjectService`/mapper via
  `targetType: register/schema` — no new openconnector entities.
- Events/Hooks: none.

## Security Considerations

- **Anonymous by design** — matches decidesk's current `#[PublicPage]`
  posture; ORI is deliberately open data (see decidesk's own
  `OriController` docblock reasoning about why no brute-force protection is
  applied, only a volume ceiling).
- **Gap 2 is a security-relevant gap, not just a completeness gap** — an
  unresolved single-item fetch would let an anonymous caller read a draft,
  wrong-discriminator, or (pending Risk 3 verification) not-yet-published
  object by UUID. This change treats Gap 2 as a blocking item for the parity
  test plan, not an accepted limitation.
- **CORS** — reuses openconnector's existing preflight/CORS machinery;
  decidesk's current `applyCorsHeaders()` reads `overwrite.cli.url`, which
  openconnector's own CORS config should mirror (task in `tasks.md`).
- **Rate limiting** — decidesk's `AnonRateLimit(limit: 120, period: 60)` maps
  onto openconnector's consumer-management rate-limit config for these
  Endpoints (sized in `tasks.md`).

## File Structure

```
openconnector/
  openspec/changes/ori-public-serving/     # this change
  lib/Service/EndpointService.php          # candidate small addition (Gap 2, Option A)
  lib/Service/MappingService.php           # candidate small addition (Gap 1, if two-rule fallback needs a helper)
  # No new PHP classes. Config lives in register.d seed content (see Seed Data).
```

## Seed Data

Not a new schema — these are new **instances** of openconnector's existing
`endpoint` and `mapping` schemas. Representative seed content (one of the 10
resource Endpoints shown; the remaining 9 follow the same shape per D1/D3):

### Schema: `endpoint` (openconnector register)

| Field | `ori-events` | `ori-motions` | `ori-publications` |
|-------|--------------|---------------|---------------------|
| slug | `ori-events` | `ori-motions` | `ori-publications` |
| name | ORI Events | ORI Motions | ORI Publications |
| path | `/api/ori/v1/events` | `/api/ori/v1/motions` | `/api/ori/v1/publications` |
| method | GET | GET | GET |
| targetType | register/schema | register/schema | register/schema |
| targetId | `<decideskRegisterId>/<meetingSchemaId>` | `<decideskRegisterId>/<decisionSchemaId>` | `<decideskRegisterId>/<publicationPayloadSchemaId>` |
| inputMapping | injects `lifecycle: published` | injects `isPublished: public`, `decisionType: motion` | none (RBAC-gated at OR layer) |
| rules (after) | `ori-field-mapping-event` | `ori-field-mapping-decision` | `ori-field-mapping-publication` |
| authentication rule | _(none — anonymous)_ | _(none)_ | _(none)_ |

### Schema: `mapping` (openconnector register)

| Field | `ori-field-mapping-event` | `ori-field-mapping-decision` |
|-------|----------------------------|-------------------------------|
| slug | `ori-field-mapping-event` | `ori-field-mapping-decision` |
| name | ORI Event field projection | ORI Decision field projection |
| mapping recipe (excerpt) | `{"name": "{{ title }}", "start_date": "{{ scheduledDate }}", "status": "{{ lifecycle }}"}` | `{"name": "{{ title }}", "status": "{{ lifecycle }}", "classification": "{{ motionType }}"}` |

**Related items per object:** none (Endpoints/Mappings are standalone config
objects; no files/notes/tasks/contacts attach to them).

## Trade-offs

- **10 Endpoints vs. one wildcard controller** (D1): more config objects to
  maintain, but each is independently cacheable/rate-limitable and matches
  openconnector's existing Endpoint model rather than inventing a
  multi-schema dispatch concept for this one case.
- **Config-first with two named engine gaps** vs. "ship a narrower ORI API
  and call it done": the narrower option was rejected — Gap 2 in particular
  is a real security/correctness regression versus today's controller, and
  hiding it behind "config-driven, no code" framing would be dishonest about
  what the change actually delivers.
- **Option A vs. Option B for Gap 2**: Option A (declarative id-fetch guard
  in openconnector) is chosen as the default implementation path because it
  keeps this change's Impact scoped to openconnector; Option B (push into OR
  RBAC) is architecturally preferable long-term but is decidesk/OR schema
  work outside this change's boundary, and is recorded as a recommendation
  rather than silently adopted.
