# Design — Retrofit mapping-and-search

> **Retrofit change.** Tasks describe retroactive annotation, not new implementation work.
> The code described here already exists and ships in production. No behavior changes.

## Context

`mapping-and-search` groups two adjacent concerns that share the payload-transformation
domain:

1. **Mapping** — `MappingService` (dot-array + Twig engine, now delegating to OpenRegister
   per ADR-022) and `MappingsController` (UI test/save/list endpoints).
2. **Search** — `SearchService`, a federated catalog search helper plus pure
   filter/sort/facet compilation utilities.

## Decisions

- **One capability, five REQs.** The 24 units cluster cleanly into five observable
  behaviors: mapping execution (REQ-001), value casting (REQ-002), the OR object shim
  (REQ-003), the controller surface (REQ-004), and query/facet compilation (REQ-005).
  Casting is split from execution because the cast vocabulary is large and independently
  testable.
- **Document, do not fix.** `SearchService::search()` references constructor-undefined
  properties (`elasticService`, `directoryService`, `objectService`). The REQ records the
  *intended* shape and the Notes section flags the runtime fatal. The pure helpers around
  it are sound.
- **Delegation noted, not re-specified.** `MappingService` is `@deprecated`; the canonical
  engine lives in OpenRegister. REQ-001 specifies the local fallback as observed.

## Annotation plan

Each method gets `@spec openspec/changes/retrofit-2026-05-25-mapping-and-search/tasks.md#task-N`
mapped per the REQ → task table:

| REQ | task | methods |
|---|---|---|
| REQ-001 | task-1 | executeMapping, executeMappingLocal, renderTemplateString, encodeArrayKeys |
| REQ-002 | task-2 | handleCast, areAllArrayKeysNull, coordinateStringToArray |
| REQ-003 | task-3 | getMapping, getMappings |
| REQ-004 | task-4 | MappingsController::test, saveObject, getObjects |
| REQ-005 | task-5 | search, mergeFacets, mergeAggregations, sortResultArray, createMongoDBSearchFilter, createMySQLSearchConditions, unsetSpecialQueryParams, createMySQLSearchParams, createSortForMySQL, createSortForMongoDB, parseQueryString, recursiveRequestQueryKey |
