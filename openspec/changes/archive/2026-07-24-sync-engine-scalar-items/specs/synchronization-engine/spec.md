# synchronization-engine Specification Delta — sync-engine-scalar-items

This delta corrects REQ-002, which fabricated a `sourceType: "array"`
dispatch case in `getAllObjectsFromSource()` that has never existed in this
codebase (`git log -S "case 'array':"` across all history returns zero hits
for that dispatch), and which was already wrong when the 2026-05-25 retrofit
spec generated it from then-current code. `array` was also never exposed as
a selectable `sourceType` in `EditSynchronization.vue`'s `typeOptions`
(Database/API/File/Register-Schema only), so there is no product-intent
trail supporting the fabricated claim either. This is a documentation-only
correction — no dispatch is added, and no runtime behaviour changes as a
result of this delta.

This change also adds a per-item scalar-coercion guard
(`SynchronizationService::synchronizeExternToIntern()`'s per-item loop) so a
bare-scalar source item no longer dead-letters at the `array`-typed method
boundary. That is a code behaviour change, but it does not touch REQ-002's
source-fetching/pagination concern — it applies after
`getAllObjectsFromSource()` has already returned an object list, at the
per-item boundary covered by REQ-003 (identity)/REQ-008 (per-item
isolation). No spec delta is required for the coercion itself: REQ-003's
existing `getOriginId()` scenario and REQ-008's existing per-item isolation
scenario already describe the contract (a stable identity is extracted per
item; a per-item failure is isolated) at a level that is agnostic to whether
the item started out as an array or a coerced scalar.

## MODIFIED Requirements

### Requirement: Source object fetching and pagination (REQ-002)

The system SHALL fetch objects from a synchronization's source according to
`sourceType`. For `api` sources it SHALL resolve the `source` record, enforce the
source's rate-limit watermark before any call, apply Twig-templated endpoints,
and drive pagination via configurable strategies: an optimized parallel mode
(ReactPHP), a sequential fallback, and per-page single fetches, capped by a
safety limit of 50 pages. Next-page resolution SHALL support query-parameter
pagination, body-embedded next endpoints, and OData `$nextLink`. The system SHALL
fetch per-object extra/sub-resource data when configured. `array`,
`register/schema`, and `database` are recognised `sourceType` values for
which `getAllObjectsFromSource()`'s dispatch switch has no matching case;
each falls through with no objects fetched (no-op), identically to any other
unrecognised `sourceType`.

<!-- Previous behavior: this requirement claimed the system "SHALL support
     `array` (static) sources directly" and carried a scenario asserting
     array sources are read without an HTTP call. Neither was ever true —
     `getAllObjectsFromSource()`'s switch has never had a `case 'array':`
     (verified via `git log -S "case 'array':"` returning zero hits across
     all history), and `array` was never a selectable sourceType in the
     synchronization editor UI. This delta corrects the documentation to
     match observed code; it does not change `getAllObjectsFromSource()`'s
     behaviour. -->

@e2e exclude backend source-fetching internals — covered by PHPUnit/Newman, not browser UI

#### Scenario: rate-limit watermark cancels the fetch

- **GIVEN** an `api` source whose `rateLimitRemaining` is `<= 0` and whose `rateLimitReset` is in the future
- **WHEN** fetching begins
- **THEN** `checkRateLimit()` throws a `TooManyRequestsHttpException` (429) carrying `X-RateLimit-*` headers, and the synchronization is cancelled.

#### Scenario: multi-page api source is paginated

- **GIVEN** an `api` source returning multiple pages
- **WHEN** `getAllObjectsFromApi()` runs
- **THEN** pages are followed (parallel when available, sequential otherwise) until no next page is found or the 50-page safety cap is reached.

#### Scenario: OData next link drives pagination

- **GIVEN** a response body containing an OData `$nextLink`
- **WHEN** `getNextlinkFromCall()` / `getNextEndpoint()` evaluate it
- **THEN** the next endpoint is extracted and the loop continues; absence of a next link terminates pagination.

#### Scenario: extra sub-resource data is fetched and merged

- **GIVEN** a synchronization configured with `extraDataConfigs`
- **WHEN** an object is processed
- **THEN** `fetchExtraDataForObject()` / `fetchMultipleExtraData()` fetch the configured sub-resources and merge them per config (dynamic or static endpoint).

#### Scenario: an unrecognised or not-yet-dispatched sourceType yields no fetched objects

- **GIVEN** a synchronization with `sourceType` set to `array`, `register/schema`, `database`, or any other value not matched by `getAllObjectsFromSource()`'s dispatch switch
- **WHEN** `getAllObjectsFromSource()` runs
- **THEN** it returns an empty array without making any HTTP call or throwing, and no items reach the per-item processing loop.

**Notes:**

- `getAllObjectsFromSource()` has empty `register/schema` and `database`
  branches marked `@todo: implement`, and no matching case at all for
  `array` — all three silently return an empty array.
- `getAllObjectsFromApi()` carries a `TODO` noting the endpoint-templating
  function is called twice in the flow, pending refactor.
- The 50-page cap (`DEFAULT_MAX_PAGES`) is a hard safety limit against runaway
  pagination loops; it is not configurable per source.
- `getAllObjectsFromArray()` exists and is exercised, but only as a helper
  called from within `getAllObjectsFromApi()`'s response-body parsing (to
  extract an item list via `sourceConfig.resultsPosition`), which is a
  distinct concern from `sourceType` dispatch and is unaffected by this
  delta.
- A synchronization whose source legitimately returns bare scalar items
  (e.g. a JSON array of strings/numbers under `sourceType: api`) is covered
  by the per-item scalar-coercion guard described in this change's
  proposal/design — that guard operates after this requirement's fetch
  stage has already returned the item list, at the per-item boundary shared
  by REQ-003/REQ-008.
- Methods: `getObjectFromSource()`, `getAllObjectsFromSource()`,
  `getAllObjectsFromApi()`, `getAllObjectsFromArray()`, `fetchAllPages()`,
  `fetchAllPagesOptimized()`, `fetchAllPagesSequential()`, `fetchSinglePage()`,
  `fetchSinglePageData()`, `getNextPage()`, `getNextEndpoint()`,
  `getNextPageInfo()`, `getNextlinkFromCall()`, `checkRateLimit()`,
  `getRateLimitHeaders()`, `fetchExtraDataForObject()`, `fetchMultipleExtraData()`.
