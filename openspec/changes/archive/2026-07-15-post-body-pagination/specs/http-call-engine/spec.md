# http-call-engine — Delta: POST-body sources and body-based pagination

## Purpose

Fixes a method-resolution ordering bug so a Source's own `configuration.
listMethod`/`createMethod`/`updateMethod`/`destroyMethod`/`readMethod`
override actually takes effect (it was previously invisible to
`decideMethod()`, silently defaulting to the caller's default method), and
adds body-based pagination (`sourceConfig.paginationIn: "body"`) for
POST-body sources — like TED's v3 search — whose pagination cursor lives in
the JSON request body rather than the query string.

@e2e exclude backend outbound call engine + pagination internals (no browser UI) — covered by PHPUnit

## ADDED Requirements

### Requirement: POST body sources and body-based pagination (REQ-010)

`CallService::call()` MUST resolve the effective HTTP method
(`decideMethod()`) and strip the CRUD-override keys (`createMethod`/
`updateMethod`/`destroyMethod`/`listMethod`/`readMethod`) AFTER merging the
source's own `configuration` (`mergeSourceConfiguration()`), so that a
Source-level method override takes effect for a call with no explicit
call-time `method`/`config` override — matching how `SynchronizationService`
invokes `call()` for every list/fetch call. A source's static
`configuration.body` (a JSON string template) MUST be sent as the outbound
request body unchanged. When `Synchronization.sourceConfig.paginationIn` is
`"body"` (default: `"query"`, unchanged from the pre-existing behaviour),
`CallService::normaliseRequestConfig()` MUST substitute the current page
value into the request body at the `paginationQuery` dot-path instead of the
query string: it MUST decode `config['body']` as JSON (starting from an
empty object when the body is absent or not valid JSON, rather than
dropping the page value), set the page value at that path, and re-encode.
Because the source's static body template is re-merged fresh on every call
(never accumulated across pages), this substitution MUST NOT compound
across successive page fetches. A synchronization with no `paginationIn`
MUST continue substituting the page value into the query string exactly as
before.

#### Scenario: a Source-level method override promotes a default-GET call to POST

- **GIVEN** a Source whose `configuration.listMethod` is `"POST"`
- **WHEN** `call(source, endpoint)` runs with no explicit `method`/`config`
  override (the shape `SynchronizationService::callSourceObject()` always
  uses)
- **THEN** the dispatched method SHALL be `"POST"`
- **AND** the persisted `call_log.request.method` SHALL be `"POST"`
- **AND** `call_log.request` SHALL NOT carry a `listMethod` key (stripped
  before persistence)

#### Scenario: the source's static body template is sent as the request body

- **GIVEN** the same POST-method Source, with `configuration.body` set to a
  static JSON string template
- **WHEN** `call(...)` runs
- **THEN** the dispatched request body SHALL equal that JSON string
  byte-for-byte

#### Scenario: a source without a method override keeps dispatching its default method

- **GIVEN** a Source with no `createMethod`/`updateMethod`/`destroyMethod`/
  `listMethod`/`readMethod` in its `configuration`
- **WHEN** `call(source, endpoint)` runs with no explicit `method` override
- **THEN** the dispatched method SHALL be the caller's default (`"GET"` for
  a list/fetch call), unchanged from pre-existing behaviour

#### Scenario: body-based pagination substitutes the page value across successive pages

- **GIVEN** a synchronization with `sourceConfig.paginationIn: "body"` and
  `sourceConfig.paginationQuery: "page"`, and a Source whose
  `configuration.body` is a static JSON template containing `"page": 1`
  among other fields
- **WHEN** three consecutive page fetches run (pages 1, 2, 3)
- **THEN** each dispatched request body SHALL decode to the same template
  with `page` set to that request's page number
- **AND** every other field in the template SHALL be unchanged across all
  three requests

#### Scenario: body-based pagination without a static body template still sets the page key

- **GIVEN** `sourceConfig.paginationIn: "body"` but the Source has no
  `configuration.body`
- **WHEN** a page fetch runs
- **THEN** the dispatched request body SHALL decode to `{"page": N}` (N
  being the current page), rather than silently dropping the pagination
  directive

#### Scenario: query-string pagination is unaffected when paginationIn is omitted

- **GIVEN** a synchronization with no `sourceConfig.paginationIn`
- **WHEN** a page fetch runs
- **THEN** the page value SHALL be substituted into the query string at
  `paginationQuery`, exactly as before this change
- **AND** no `body` key SHALL be introduced by the pagination step
- @e2e exclude backend regression — covered by PHPUnit

**Notes:**

- This closes a latent ordering bug, not just an additive feature:
  `decideMethod()` previously ran BEFORE `mergeSourceConfiguration()` (see
  REQ-001's documented Phase 2 vs Phase 7), so a Source's own method
  override was invisible unless the CALLER separately passed a matching
  `method`/`config` override at call time. `SynchronizationService` never
  did that, so this override effectively never worked end-to-end for a
  synchronization-driven fetch before this change.
- The override-key `unset()` moved together with `decideMethod()`; this
  also fixes a secondary latent leak where a source-only method override
  was never stripped from the config before persistence.
- `paginationIn` lives on `Synchronization.sourceConfig`, alongside the
  pre-existing `paginationQuery`/`maxPages`/`resultsPosition` — a
  per-synchronization pagination-strategy concern, not a Source-level
  setting.
- Applies identically on the brokered-credential dispatch path
  (`source-broker-credentials`), since both changes run inside
  `CallService::call()` before the Guzzle-vs-broker dispatch branch.
- Methods touched: `CallService::call()` (phase reorder only, no signature
  change), new `CallService::applyBodyPagination()`,
  `SynchronizationService::getNextPage()` (adds `paginationIn` to the
  `pagination` sub-array it already builds).
