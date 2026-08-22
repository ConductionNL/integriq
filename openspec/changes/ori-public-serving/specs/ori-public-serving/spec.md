# ori-public-serving Specification (delta)

---
status: proposed
---

## Purpose

integriq serves the public ORI 1.4 API (`/api/ori/v1/*`) on decidesk's
behalf: 10 anonymous, read-only Endpoint configurations that read decidesk's
OpenRegister data and project it into ORI/Popolo JSON-LD, reproducing
decidesk's current `OriController`/`OriSerializer` observable behaviour as
declarative configuration rather than a bespoke controller. decidesk retains
ownership of the underlying data and of the configuration content; this
capability describes the serving contract integriq fulfils.

## ADDED Requirements

### Requirement: Anonymous ORI resource dispatch (REQ-ORIPUB-001)

The system MUST serve `GET /api/ori/v1/{resource}` and
`GET /api/ori/v1/{resource}/{id}` for each of the 10 ORI resources
(`organizations`, `persons`, `memberships`, `events`, `agendaitems`,
`motions`, `amendments`, `voteevents`, `votes`, `reports`, `publications`) as
integriq Endpoint configurations carrying no `authentication` rule, so
the requests are dispatched without any authentication check, matching
decidesk's current `#[PublicPage]` posture.

#### Scenario: An anonymous caller reads the events list without credentials

- **GIVEN** no `Authorization` header or Nextcloud session on the request
- **WHEN** an anonymous caller sends `GET /api/ori/v1/events`
- **THEN** the request is dispatched and a 200 response is returned
- **AND** no authentication challenge (401) is issued

#### Scenario: Unknown resource slug returns 404

- **GIVEN** a request to `GET /api/ori/v1/not-a-real-resource`
- **WHEN** the path/method is resolved against the registered Endpoints
- **THEN** the response is HTTP 404 (no matching Endpoint), matching
  `endpoint-runtime` REQ-EP-001's generic "no matching endpoint" behaviour

### Requirement: Fixed per-resource filter injection (REQ-ORIPUB-002)

The system MUST apply each resource's fixed filter set (lifecycle gate for
lifecycle-bearing resources, `isPublished`+`decisionType` for the
`decision`-schema resources `motions`/`amendments`, no filter for the
lifecycle-free `persons`/`memberships` resources, RBAC-only for
`publications`) to every list request for that resource, regardless of any
filter supplied by the caller, so that unpublished/draft/wrongly-typed
objects never appear in a collection response.

#### Scenario: Draft meetings are excluded from the events list

- **GIVEN** decidesk holds both `published` and `draft` `Meeting` objects
- **WHEN** an anonymous caller requests `GET /api/ori/v1/events`
- **THEN** only `published` meetings appear in `items`

#### Scenario: motions and amendments are discriminated by decisionType on list

- **GIVEN** decidesk holds `Decision` objects with `decisionType: motion` and
  `decisionType: amendment`
- **WHEN** an anonymous caller requests `GET /api/ori/v1/motions`
- **THEN** only `decisionType: motion` objects with `isPublished: public`
  appear in `items`
- **AND** a request to `GET /api/ori/v1/amendments` returns only
  `decisionType: amendment` objects under the same `isPublished` gate

### Requirement: Field projection to ORI/Popolo JSON-LD shape (REQ-ORIPUB-003)

The system MUST project each returned object's fields onto the ORI vocabulary
(`name`, `start_date`, `end_date`, `location`, `status`, `classification`,
`text`, plus `email` for `Organization`/`Person`-typed resources only, plus
the `publications`-specific field set), using the first present source
property per target key (matching `OriSerializer::FIELD_RULES`/
`PAYLOAD_FIELD_RULES` semantics), and MUST wrap collection responses in a
`{"@context", "@type", "count", "items"}` envelope and single-item responses
in a `{"@context", "@type", ...fields}` shape — not integriq's default
`{count, results, next, previous}` pagination envelope.

#### Scenario: title maps to name when name is absent

- **GIVEN** a `Meeting` object with `title: "Raadsvergadering"` and no `name`
  property
- **WHEN** the event is projected
- **THEN** the output carries `"name": "Raadsvergadering"`

#### Scenario: email is present only for Organization/Person types

- **GIVEN** a `GovernanceBody` object (`@type: Organization`) and a `Meeting`
  object (`@type: Event`), both carrying an `email` source field
- **WHEN** each is projected
- **THEN** the `Organization` output carries `email`
- **AND** the `Event` output does not carry `email`

#### Scenario: Collection envelope shape matches ORI, not the default pagination envelope

- **GIVEN** a list request that would normally return
  `{count, results, next, previous}`
- **WHEN** it is served through an ORI Endpoint
- **THEN** the response is `{"@context": "https://argu.co/ns/core", "@type": "<resource type>", "count": <n>, "items": [...]}`

### Requirement: Single-item 404 non-disclosure across discriminator, lifecycle, and publish-window gates (REQ-ORIPUB-004)

The system MUST return HTTP 404 (never 403, never 200 with the raw object)
for `GET /api/ori/v1/{resource}/{id}` when the object at `{id}` exists but
(a) belongs to the wrong discriminated sub-type for that resource (a
`decisionType: amendment` object fetched via `/motions/{id}`), (b) fails the
resource's lifecycle gate (a non-`published` object on a lifecycle-gated
resource), or (c) — for `publications` — falls outside the
publish/depublish window, so that an anonymous caller cannot distinguish
"exists but hidden" from "does not exist." This closes the gap identified in
this change's design.md (Gap 2) via `endpoint-runtime` REQ-EP-010.

#### Scenario: Fetching an amendment via the motions path 404s

- **GIVEN** a `Decision` object with `decisionType: amendment` at id `X`
- **WHEN** a caller requests `GET /api/ori/v1/motions/X`
- **THEN** the response is HTTP 404, not the amendment object

#### Scenario: Fetching a draft meeting by id 404s

- **GIVEN** a `Meeting` object with `lifecycle: draft` at id `Y`
- **WHEN** a caller requests `GET /api/ori/v1/events/Y`
- **THEN** the response is HTTP 404

#### Scenario: Fetching a future-dated publication payload by id 404s

- **GIVEN** a `PublicationPayload` object at id `Z` with `publicationDate` in
  the future
- **WHEN** a caller requests `GET /api/ori/v1/publications/Z`
- **THEN** the response is HTTP 404

## Non-Functional Requirements

- **Performance:** dispatch reuses integriq's existing endpoint
  resolution cache (`endpoint-runtime` REQ-EP-004) — no new per-request
  OpenRegister query beyond what the default Endpoint dispatch already does.
- **Internationalization:** ORI field names and the JSON-LD vocabulary are
  fixed by the ORI/Popolo standard, not localised (matches
  `OriController`/`OriSerializer` today — no `t()`/`IL10N` wrapping of
  output field names).

## Acceptance Criteria

- [ ] Every scenario above passes against the parity test plan
      (`test-plan.md`) run against both `OriController` and the new
      integriq Endpoints, with zero response diffs.
- [ ] REQ-ORIPUB-004 (Gap 2) is closed before the companion decidesk
      retirement change cuts the public `/api/ori/v1/*` mount over —
      documented, not silently deferred.

## Notes

- Gap 1 (envelope-reshaping composition — REQ-ORIPUB-003's envelope
  scenario) is expected to be achievable through existing `mapping`-type
  after-rule composition (see design.md); if implementation proves it is
  not expressible in one or two chained rules, that is a blocking finding
  for this change, not a silent scope reduction.
- `motions`/`amendments` sharing the `decision` schema, and the
  RBAC-vs-application-logic split for the `publications` publish-window gate,
  are detailed in `contract.md`'s per-resource filter/type reference.
