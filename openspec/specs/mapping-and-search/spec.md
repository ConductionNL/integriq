---
retrofit: true
status: done
---

# Mapping and Search Specification

## Purpose

Integriq transforms inbound/outbound payloads through configurable mappings and
exposes a federated catalog search helper. The mapping engine has largely moved to
OpenRegister (ADR-022) — `MappingService` now delegates to OpenRegister's
`MappingService` when present and only falls back to its own dot-array + Twig engine
when OpenRegister is unavailable. The search helper compiles request filters into
MongoDB / MySQL query fragments and fans out to peer directory instances. This spec
captures the observed behavior of the 24 mapping/search code units retroactively; the
code already exists.

**OpenSpec changes**
- [`vng-klantinteracties-adapter`](../../changes/archive/2026-07-12-vng-klantinteracties-adapter/) _(archived 2026-07-12)_ — added VNG REST query-language translation to the search compiler: double-underscore lookup operators + `partijIdentificator` nested filters onto OpenRegister search (REQ-006) and `expand=` relation embedding with bounded depth (REQ-007). Dialect-agnostic; the VNG adapter is the first consumer.

## Requirements

### Requirement: Mapping Management UI (REQ-UI-001)

Integriq MUST provide a Mappings section in its SPA where administrators can
browse, create, edit, and test mapping configurations.

#### Scenario: mappings list page mounts and shows content

- GIVEN an authenticated admin visits the integriq app
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

### Requirement: Apply a mapping recipe to an input payload (REQ-001)

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

### Requirement: Cast a mapped value to a declared type (REQ-002)

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

### Requirement: Resolve mappings through the OpenRegister object shim (REQ-003)

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

### Requirement: Test, persist, and list mappings via the controller (REQ-004)

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

### Requirement: Compile request filters into search queries and merge facets (REQ-005)

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

### Requirement: Translate VNG list-filter operators onto OpenRegister search (REQ-006)

The search compiler MUST translate VNG list-filter semantics into OpenRegister
search filters: double-underscore lookup operators (e.g. `field__icontains`,
`field__gte`) MUST map to the equivalent OpenRegister filter operators, and
nested identifier filters (e.g.
`partijIdentificator__codeSoortObjectId=bsn` combined with
`partijIdentificator__objectId=<hash>`) MUST resolve against the stored
(hash-backed) identity rather than a raw value.

@e2e exclude backend search compiler — covered by PHPUnit/Newman, no browser UI

#### Scenario: Double-underscore operator maps to a search filter
- GIVEN a request query `achternaam__icontains=moulin`
- WHEN the compiler translates it
- THEN it produces an OpenRegister case-insensitive contains filter on the mapped field

#### Scenario: partijIdentificator filter resolves against hashed identity
- GIVEN a request query `partijIdentificator__codeSoortObjectId=bsn`
- WHEN the compiler translates it
- THEN it constrains the search to BSN-typed identities and matches on the stored hash, never requiring a raw BSN in the query path

#### Scenario: Unknown operator is rejected, not silently ignored
- GIVEN a request query with an unsupported lookup operator
- WHEN the compiler translates it
- THEN it returns a clear error rather than dropping the filter and returning an over-broad result set

**Implementation:** `MappingService::translateVngFilterOperators()` +
`MappingService::translatePartijIdentificatorFilter()`, wired into
`EndpointService::handleSchemaRequest()`'s GET case behind the Endpoint's
`vngFilterTranslation` opt-in boolean (default `false`).

### Requirement: `expand=` relation embedding (REQ-007)

The search compiler MUST support VNG `expand=` semantics — embedding named
related resources inline in a list or detail response — by resolving the named
relations through the OpenRegister object shim and inlining them under the VNG
relation keys. The embedding depth MUST be bounded (documented cap) to keep the
search cost predictable.

@e2e exclude backend search compiler — covered by PHPUnit/Newman, no browser UI

#### Scenario: expand embeds a named relation inline
- GIVEN a request `GET /partijen?expand=digitaleAdressen`
- WHEN the compiler resolves the response
- THEN each partij carries its `digitaleAdressen` embedded inline rather than as bare references

#### Scenario: expand depth is bounded
- GIVEN a request that requests nested expansion beyond the documented depth cap
- WHEN the compiler resolves it
- THEN expansion stops at the cap and the response documents the truncation rather than fanning out unboundedly

**Implementation:** `MappingService::expandRelations()` — default depth cap 2
(orchestrator ruling), same opt-in flag as REQ-006.

### Requirement: One mapping is reused by id (REQ-SCB-007)

A mapping MUST be its own object, referenced by id. A synchronization, an endpoint rule and a flow step MUST be able to point at the same mapping. A change to that mapping MUST apply everywhere it is referenced.

#### Scenario: a synchronization and a flow share a mapping
- GIVEN a mapping referenced by a synchronization and by a flow's apply-mapping step
- WHEN an administrator renames one output field in the mapping
- THEN the next synchronization run and the next flow run both write the new field name
- @e2e exclude cross-reference resolution; covered by PHPUnit

### Requirement: The unset list leaves fields out (REQ-SCB-008)

A mapping MAY list fields to unset. Integriq MUST remove every listed field from the mapped output, also when the field came through a pass-through copy of the input.

#### Scenario: an internal field is not passed on
- GIVEN a pass-through mapping that unsets `interneNotitie`
- WHEN a record with `interneNotitie` is mapped
- THEN the output carries every other field and no `interneNotitie`
- @e2e exclude mapping engine; covered by PHPUnit on MappingService

## Non-Functional Requirements

- **Internationalization:** Controller error messages MUST be translatable via `IL10N` (ADR-007); Dutch and English are supported. The new REQ-006/007 filter/expand errors are not yet localised (raw English `Exception` messages) — flagged as a follow-up in the archived change's tasks.md.
- **Performance:** filter translation and bounded `expand=` MUST not add unbounded query fan-out; the depth cap is the guard.

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

### Requirement: A synchronization reads a CSV source response (REQ-MFL-001)

A synchronization whose `sourceConfig.format` is `csv` MUST parse the source
response as CSV with the configured delimiter, enclosure and header flag, and
MUST hand one array per row to the mapping. A quoted field containing a line
break MUST stay one field. The synchronization settings MUST offer the format
as a select of `json`, `jsonl`, `xml` and `csv`.

#### Scenario: a municipal code list served as CSV
- GIVEN a source answering `code;naam` with two rows, and a synchronization with format `csv` and delimiter `;`
- WHEN an integration engineer runs the synchronization test
- THEN two objects reach the mapping with keys `code` and `naam`
- e2e: tests/e2e/mapping-formats-and-lookups.spec.ts

#### Scenario: a quoted line break survives
- GIVEN a CSV row whose address field is quoted and holds a line break
- WHEN it is decoded
- THEN the row has one address value containing the line break
- @e2e exclude a parser rule; covered by PHPUnit on CsvCodec

### Requirement: A mapping declares its output format (REQ-MFL-002)

A mapping MUST accept `outputFormat` of `json`, `xml` or `csv`, defaulting to
`json`, with `outputOptions` for the XML root element and the CSV delimiter and
header. XML output MUST follow the `@root`, `@attributes` and `#text`
conventions of `xml-response`. The mapping test MUST return the rendered text
next to the array, and the mapping detail page MUST show it.

#### Scenario: an engineer previews XML output
- GIVEN the mapping `person-to-xml` with root element `persoon`
- WHEN an integration engineer enters a sample input on the mapping detail page
- THEN the preview shows an XML document with root `persoon` next to the mapped array
- e2e: tests/e2e/mapping-formats-and-lookups.spec.ts

#### Scenario: CSV output with a header row
- GIVEN a mapping with `outputFormat: csv`, delimiter `;` and header on
- WHEN it is rendered for two rows
- THEN the text has a header line and two data lines separated by `;`
- @e2e exclude a writer rule; covered by PHPUnit on MappingService::renderOutput

### Requirement: A synchronization sends the mapping's output format to an API target (REQ-MFL-003)

When a synchronization writes to an API target and the target mapping declares
`xml` or `csv`, integriq MUST send the rendered text as the request body with
`application/xml` or `text/csv` as its content type, and MUST NOT send JSON.
A mapping without `outputFormat` MUST be sent as JSON, as before.

#### Scenario: an XML API receives XML
- GIVEN a synchronization to an API target whose mapping declares `xml`
- WHEN an object is written
- THEN the call log shows a request with `Content-Type: application/xml` and an XML body
- @e2e exclude an outbound call; covered by PHPUnit on SynchronizationService with a mocked CallService

### Requirement: A mapping can look up a value in an allowed register schema (REQ-MFL-004)

A mapping template MUST be able to call `lookup(schema, filters, field, default)`.
It MUST return `field` of the single object in `schema` matching `filters`, and
`default` when none match. When more than one object matches it MUST return
`default` and record a warning on the execution trace. It MUST refuse any
schema not on the lookup allowlist, and MUST NOT make an outbound HTTP call.
Results MUST be cached for the duration of one mapping run.

#### Scenario: a gemeentecode becomes a name
- GIVEN `codelijsten/gemeenten` on the lookup allowlist holding an object with `code` `0363` and `naam` `Amsterdam`
- WHEN a mapping rule `{{ lookup('codelijsten/gemeenten', {'code': gemeente}, 'naam', '') }}` runs on input `gemeente: 0363`
- THEN the output value is `Amsterdam`
- e2e: tests/e2e/mapping-formats-and-lookups.spec.ts

#### Scenario: a schema off the allowlist is refused
- GIVEN a schema that is not on the lookup allowlist
- WHEN a mapping calls `lookup` on it
- THEN the mapping fails with a message naming the schema and no object is read
- @e2e exclude a refusal; covered by PHPUnit on MappingRuntime

#### Scenario: two matches do not pick one
- GIVEN two objects in an allowed schema matching the filters
- WHEN `lookup` runs
- THEN it returns the default and the trace step carries a warning naming the filters
- @e2e exclude a trace warning; covered by PHPUnit on MappingRuntime

### Requirement: The lookup allowlist is administered and every change is recorded (REQ-MFL-005)

Only an administrator MUST be able to add or remove a schema on the lookup
allowlist, through `/api/admin/mapping-lookups`, and every change MUST be
recorded with the administrator and the time.

#### Scenario: an administrator allows a code list
- GIVEN an administrator on the integriq admin page
- WHEN they add `codelijsten/gemeenten` to the lookup allowlist
- THEN it is listed and the change is recorded with their user id
- e2e: tests/e2e/mapping-formats-and-lookups.spec.ts

#### Scenario: a non-administrator cannot change the allowlist
- GIVEN a signed-in user who is not an administrator
- WHEN they post to `/api/admin/mapping-lookups`
- THEN the response is 403 and the allowlist is unchanged
- @e2e exclude an authorization refusal; covered by PHPUnit on the controller
