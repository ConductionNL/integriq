# Contract: ori-public-serving

## Consumers

- `decidesk`: declares/owns the Endpoint + Mapping configuration content
  (register.d seed) that this contract describes; today's `OriController`
  remains the served implementation until the companion decidesk retirement
  change confirms parity against this contract and cuts over.
- External anonymous callers (VNG/ORI harvesters, national raadsinformatie
  aggregators, third-party open-data consumers) — the actual public
  consumers of `/api/ori/v1/*`, unchanged from today.

## Endpoints

All 10 resources (`organizations`, `persons`, `memberships`, `events`,
`agendaitems`, `motions`, `amendments`, `voteevents`, `votes`, `reports`,
`publications`) share the same shape; `events` is shown as the
representative example, with resource-specific filter/type notes below.

### `GET /api/ori/v1/{resource}`
**Auth**: none (anonymous — no `authentication` rule on the Endpoint, see
design.md D2)

**Request:** no body. Optional pagination query params (`_limit`, `_page`)
per integriq's standard `findAllPaginated()` contract.

**Response (200):**
```json
{
  "@context": "https://argu.co/ns/core",
  "@type": "Event",
  "count": 2,
  "items": [
    {
      "@context": "https://argu.co/ns/core",
      "@type": "Event",
      "id": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
      "name": "Raadsvergadering 1 september",
      "start_date": "2026-09-01T19:00:00+00:00",
      "location": "Raadszaal",
      "status": "published",
      "classification": "council-meeting"
    }
  ]
}
```

**Errors:**
| Code | Condition |
|------|-----------|
| 404  | Unknown `{resource}` slug (no matching Endpoint path) |
| 429  | Anonymous rate limit exceeded (120 req/60s, mirroring decidesk's current `AnonRateLimit`) |
| 500  | Misconfigured Endpoint (`targetId` unparseable) |

### `GET /api/ori/v1/{resource}/{id}`
**Auth**: none (anonymous)

**Response (200):** a single item shaped like one `items` entry above.

**Response (404):** `{"message": "Not found", "code": 404}` — returned
uniformly for (a) unknown id, (b) an id whose object fails the
resource's discriminator/lifecycle/publish-window gate (design.md Gap 2).
**Never 403** — the contract explicitly forbids distinguishing "hidden" from
"unknown" to an anonymous caller, matching `OriController::show()` today.

**Errors:**
| Code | Condition |
|------|-----------|
| 404  | See above |
| 429  | Anonymous rate limit exceeded |

### `OPTIONS /api/ori/v1/{resource}` and `/api/ori/v1/{resource}/{id}`
**Auth**: none

**Response (200):** empty body, `Access-Control-Allow-*` headers per
integriq's existing `preflightedCors` (REQ-EP-001) — no per-resource
configuration needed.

## Per-resource filter and type reference

| Resource | Schema | Fixed filter | ORI `@type` | Discriminator |
|---|---|---|---|---|
| `organizations` | `governance-body` | `lifecycle=published` | `Organization` | — |
| `persons` | `person` | _(none — no lifecycle field)_ | `Person` | — |
| `memberships` | `membership` | _(none — no lifecycle field)_ | `Membership` | — |
| `events` | `meeting` | `lifecycle=published` | `Event` | — |
| `agendaitems` | `agenda-item` | `lifecycle=published` | `AgendaItem` | — |
| `motions` | `decision` | `isPublished=public`, `decisionType=motion` | `Motion` | `decisionType` (Gap 2 on single-item) |
| `amendments` | `decision` | `isPublished=public`, `decisionType=amendment` | `Amendment` | `decisionType` (Gap 2 on single-item) |
| `voteevents` | `voting-round` | `lifecycle=published` | `VoteEvent` | — |
| `votes` | `vote` | `lifecycle=published` | `Vote` | — |
| `reports` | `minutes` | `lifecycle=published` | `Report` | — |
| `publications` | `publication-payload` | _(RBAC publish-window, OR-enforced)_ | self-declared via `oriType` | publish-window (Gap 2, Risk 3 — verification pending) |

`Organization`/`Person` resources additionally carry an `email` field (see
design.md D4, `EMAIL_TYPES`) — `memberships`/`events`/etc. do not.

## Error Codes

| Code | Meaning | Condition |
|------|---------|-----------|
| 404  | Not found | Unknown resource slug, unknown id, or an id whose object fails the discriminator/lifecycle/publish-window gate |
| 429  | Rate limited | Anonymous rate ceiling exceeded (120 req/60s list+item, 240 req/60s preflight, matching today's values) |
| 500  | Misconfiguration | Endpoint `targetId` malformed |

## Versioning

`v1` in the path (`/api/ori/v1/*`) mirrors decidesk's current URL scheme —
ORI is versioned independently of integriq's own API version, matching
`OriController`'s documented rationale (ORI's spec version, not decidesk's or
integriq's release version, drives the path segment).

## Breaking Change Policy

The companion decidesk retirement change MUST NOT cut the public
`/api/ori/v1/*` mount over to these integriq Endpoints until the parity
test plan (`test-plan.md`) passes with zero diffs against `OriController`'s
current responses, including the two named gaps (Gap 1 envelope shape, Gap 2
single-item guard) being closed — not merely documented. Any future
behavioural change to these Endpoints (new resource, changed field mapping)
follows integriq's existing Endpoint-versioning convention (new path
segment or new Endpoint object; no in-place breaking edits to a published
Endpoint).

## SLA

Matches integriq's existing endpoint-runtime cache-backed dispatch
(`endpoint-runtime` REQ-EP-004: request-lifetime + 1-hour-TTL distributed
cache) — no new SLA target introduced; ORI resources are cached exactly like
every other Endpoint.
