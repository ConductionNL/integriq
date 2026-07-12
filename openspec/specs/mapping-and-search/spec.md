---
retrofit: true
status: in-progress
---

# Mapping and Search Specification

## Purpose

OpenConnector transforms inbound/outbound payloads through configurable mappings and
exposes a federated catalog search helper. The mapping engine has largely moved to
OpenRegister (ADR-022) — `MappingService` now delegates to OpenRegister's
`MappingService` when present and only falls back to its own dot-array + Twig engine
when OpenRegister is unavailable. The search helper compiles request filters into
MongoDB / MySQL query fragments and fans out to peer directory instances. This spec
captures the observed behavior of the 24 mapping/search code units retroactively; the
code already exists.

**OpenSpec changes**
- `vng-klantinteracties-adapter` (active) — adds VNG REST query-language translation to the search compiler: double-underscore lookup operators + `partijIdentificator` nested filters onto OpenRegister search (REQ-006) and `expand=` relation embedding with bounded depth (REQ-007). Dialect-agnostic; the VNG adapter is the first consumer. Normative requirements live in the change's delta spec and merge here on archive.

## Requirements

### REQ-UI-001: Mapping Management UI

OpenConnector MUST provide a Mappings section in its SPA where administrators can
browse, create, edit, and test mapping configurations.

#### Scenario: mappings list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Mappings section via the sidebar or direct URL
- THEN the Mappings index page renders inside the app-content area with content visible

#### Scenario: Add Mapping button opens the creation modal

- GIVEN the Mappings index page is loaded
- WHEN the user clicks the "Add Mapping" button
- THEN a modal/dialog opens containing the mapping creation form

#### Scenario: Mapping detail page renders for an existing mapping

- GIVEN at least one mapping exists in the system
- WHEN the user clicks on a mapping row or card in the Mappings list
- THEN the mapping detail view renders without error

### REQ-001: Apply a mapping recipe to an input payload

The system MUST transform an input array into an output array according to a mapping
recipe. `executeMapping()` is the public entry point: it normalises an `ObjectEntity`
into an OpenRegister `Mapping`, and — when OpenRegister's `MappingService` was resolved
at construction — delegates the transformation to it; otherwise it runs the local
`executeMappingLocal()` engine. The local engine builds a dot-notation array
(optionally seeded with the input when `passThrough` is true), resolves each mapping
key either by copying an existing dot-path value or by rendering the value as a Twig
template (`renderTemplateString()`), applies `unset` directives, applies `cast`
directives via `handleCast()`, and supports list mode (per-element mapping with extra
`listInput`/`value` injection) and root-level output via the `#` key. Array keys
containing `.` are encoded to `&#46;` during processing (`encodeArrayKeys()`) and
decoded back afterward.

@e2e exclude backend mapping engine internals — covered by PHPUnit, not browser UI

#### Scenario: Twig-rendered field mapping

- GIVEN a mapping recipe `{ "fullName": "{{ name }}" }` and input `{ "name": "John" }`
- WHEN `executeMapping()` runs with OpenRegister unavailable
- THEN the output is `{ "fullName": "John" }`

#### Scenario: Direct dot-path copy takes precedence over Twig

- GIVEN a recipe value that exactly matches an existing input dot-path
- WHEN the local engine processes that key
- THEN the input value is copied verbatim and the value is NOT rendered as a Twig template

#### Scenario: List mode maps each element

- GIVEN `list` is true and the input is an array of items
- WHEN `executeMapping()` runs
- THEN each item is mapped individually and returned as a list preserving keys

#### Scenario: Twig render failure surfaces a contextual error

- GIVEN a mapping value that throws during Twig rendering
- WHEN the local engine renders it
- THEN an `Exception` is thrown naming the mapping, the key, and the failing value

### REQ-002: Cast a mapped value to a declared type

The system MUST coerce a mapped value according to its `cast` directive. `handleCast()`
supports scalar casts (`string`, `int`/`integer`, `float`, `bool`/`boolean` and their
nullable `?bool` variants, `array`), encoding casts (`url`, `rawurl`, their decode
variants, `html`/`htmlDecode`, `base64`/`base64Decode`, `json`/`jsonToArray`, `utf8`
transliteration), domain casts (`date`, `coordinateStringToArray`, `moneyStringToInt`,
`intToMoneyString`), and conditional casts (`nullStringToNull`, `keyCantBeValue`,
`unsetIfValue==X`, `setNullIfValue==X`, `countValue:path`). A key deleted by a cast
(e.g. `unsetIfValue`, `keyCantBeValue`) MUST NOT be re-set. `coordinateStringToArray()`
parses a space-separated coordinate string into a point array. `areAllArrayKeysNull()`
is the recursive predicate used by the `unsetIfValue`/`setNullIfValue` empty-array
checks.

@e2e exclude backend cast directive internals — covered by PHPUnit, not browser UI

#### Scenario: Boolean cast normalises truthy strings

- GIVEN a value of `"yes"`, `"true"`, or `1` with a `bool` cast
- WHEN `handleCast()` runs
- THEN the value becomes boolean `true`; any other value becomes `false`

#### Scenario: unsetIfValue removes a matching key

- GIVEN a `unsetIfValue==X` cast and the mapped value equals `X`
- WHEN `handleCast()` runs
- THEN the key is deleted from the output and not re-set

#### Scenario: Unknown cast leaves the value unchanged

- GIVEN a `cast` directive that matches no known case
- WHEN `handleCast()` runs
- THEN the value passes through unmodified (default branch)

### REQ-003: Resolve mappings through the OpenRegister object shim

The system MUST expose mapping entities only through the service layer, never via direct
storage access. `getMapping()` resolves a single mapping by id from the `openconnector`
register / `mapping` schema via OpenRegister's `ObjectService::find()`. `getMappings()`
lists every mapping via `ObjectService::findAll()` and unwraps the `results` envelope
when present.

@e2e exclude backend OR object shim internals — covered by PHPUnit, not browser UI

#### Scenario: Resolve a single mapping by id

- GIVEN a mapping id that exists in the `openconnector` register
- WHEN `getMapping()` is called
- THEN the corresponding `ObjectEntity` is returned

#### Scenario: List mappings unwraps the results envelope

- GIVEN OpenRegister returns `{ "results": [ ... ] }`
- WHEN `getMappings()` is called
- THEN the inner `results` array is returned

### REQ-004: Test, persist, and list mappings via the controller

The system MUST provide `@NoAdminRequired @NoCSRFRequired` endpoints for the mapping UI.
`test()` requires `inputObject` and `mapping` in the request (throwing
`InvalidArgumentException` otherwise), hydrates an `ObjectEntity` from the supplied
mapping payload, runs `executeMapping()`, and — when a `schema` id and `validation` flag
are supplied and OpenRegister is installed — validates the result against the schema,
returning `{ resultObject, isValid, validationErrors }`. Mapping execution errors return
HTTP 400; a missing schema returns 404; a missing OpenRegister returns 412. `saveObject()`
persists the supplied `object` to the `openconnector` register / `mapping` schema
(register and schema overridable), returning 412 when OpenRegister is absent and 400 when
`object` is missing. `getObjects()` reports OpenRegister availability and lists available
registers via `RegisterMapper::findAll()`.

@e2e exclude backend mapping controller HTTP surface — covered by PHPUnit/Newman, not browser UI

#### Scenario: Test endpoint rejects missing required fields

- GIVEN a request without `inputObject` or without `mapping`
- WHEN `test()` runs
- THEN an `InvalidArgumentException` is thrown

#### Scenario: Test endpoint validates against a schema when requested

- GIVEN a valid `inputObject`, `mapping`, a resolvable `schema` id, and `validation` true
- WHEN `test()` runs with OpenRegister installed
- THEN the response includes the mapped `resultObject`, `isValid`, and any `validationErrors`

#### Scenario: Persist requires OpenRegister and an object field

- GIVEN OpenRegister is not installed, OR the `object` field is missing
- WHEN `saveObject()` runs
- THEN it returns HTTP 412 (no OpenRegister) or HTTP 400 (missing object) respectively

### REQ-005: Compile request filters into search queries and merge facets

The system MUST translate request query parameters into backend-specific query fragments
and merge results from multiple sources. `parseQueryString()` (with
`recursiveRequestQueryKey()`) parses a raw query string into a nested filter array,
honouring bracketed keys (`a[b][]`). `createMongoDBSearchFilter()` builds a `$or` regex
clause from `_search` across the supplied fields and rewrites `IS NULL`/`IS NOT NULL`
sentinels into `$eq`/`$ne`. `createMySQLSearchConditions()`/`createMySQLSearchParams()`
build `LOWER(field) LIKE :search` conditions and the bound parameter.
`createSortForMySQL()`/`createSortForMongoDB()` translate the `_order` directive into
`ASC`/`DESC` or `1`/`-1`. `unsetSpecialQueryParams()` strips every `_`-prefixed key.
`search()` fans out to peer directory search endpoints, merges hits, re-sorts by
descending `_score` (`sortResultArray()`), and merges facet aggregations
(`mergeAggregations()` / `mergeFacets()`, summing counts by `_id`), returning a paginated
envelope.

@e2e exclude backend search query compilation and facet merging — covered by PHPUnit, not browser UI

#### Scenario: Free-text search compiles to a regex OR clause

- GIVEN filters containing `_search` and a list of searchable fields
- WHEN `createMongoDBSearchFilter()` runs
- THEN a `$or` array of `{ field: { $regex, $options: 'i' } }` is produced and `_search` is removed

#### Scenario: Special query params are stripped from filters

- GIVEN filters containing `_limit`, `_page`, and a domain field
- WHEN `unsetSpecialQueryParams()` runs
- THEN every `_`-prefixed key is removed and the domain field remains

#### Scenario: Facet counts are summed across sources

- GIVEN two aggregation sets sharing a facet `_id`
- WHEN `mergeFacets()` runs
- THEN the merged entry's `count` is the sum of both sources' counts

## Non-Functional Requirements

- **Internationalization:** Controller error messages MUST be translatable via `IL10N` (ADR-007); Dutch and English are supported.

## Acceptance Criteria

- [x] Mapping delegation to OpenRegister with local fallback is exercised
- [x] All documented cast directives behave as observed
- [x] Mapping controller endpoints return the documented status codes
- [x] Filter compilation and facet merging behave as observed

## Notes

- **Delegation/deprecation:** `MappingService` is marked `@deprecated` — the engine has
  moved to OpenRegister (ADR-022). The local engine is a fallback only. New work should
  target the OpenRegister mapping engine, not extend this fallback.
- **Observed-but-suspicious — `SearchService::search()` references undefined properties.**
  `search()` calls `$this->elasticService`, `$this->directoryService`, and
  `$this->objectService`, none of which are injected by the constructor (only
  `IURLGenerator` is). The class carries `@SuppressWarnings(PHPMD.UndefinedVariable)`.
  As written, `search()` will fatal at runtime the moment `elasticConfig['location']` is
  non-empty or the directory branch is reached. This REQ documents the *intended* behavior
  observed from the code shape; the method appears to be dead/broken plumbing carried over
  from a federated-catalog feature. Flagged for follow-up — do not treat REQ-005's
  `search()` scenarios as a guarantee of a working runtime path until the missing
  dependencies are wired. The pure helper methods (filter/sort/facet compilation) are
  sound and independently testable.
- **Security — mapping endpoints accept arbitrary user payloads.** `test()` and
  `saveObject()` are `@NoAdminRequired`; any authenticated user can execute an arbitrary
  Twig mapping against arbitrary input (`test()`) or persist a mapping object
  (`saveObject()`). Twig rendering of user-supplied templates is sandboxed by the mapping
  Twig environment's extension set, but the surface is worth a dedicated review: confirm
  no `MappingExtension`/`AuthenticationExtension` function exposes SSRF, file read, or
  credential access to an unprivileged caller. Not an IDOR (no arbitrary stored-object id
  is dereferenced), but the authorization posture (any authed user) should be confirmed
  against ADR-023 action-level authorization.
- **`date` cast is a passthrough of PHP `date()`** — `case 'date': $value = date($value)`
  treats the mapped *value* as a PHP date format string, not a date to reformat. Observed
  behavior; likely surprising to mapping authors. Documented as-is.
