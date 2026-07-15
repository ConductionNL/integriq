# mapping-and-search Specification (delta)

---
status: proposed
---

## Purpose

Remove the superseded MongoDB/MySQL search-fragment builders from REQ-005: they
were documented but never wired into `SearchService::search()`, which resolves
results through Elasticsearch and directory fan-out (issue #165, gate-52
orphaned-write-capability).

## MODIFIED Requirements

### Requirement: REQ-005 — Compile request filters into search queries and merge facets

The system MUST translate request query parameters into filter arrays and merge
results from multiple sources. `parseQueryString()` (with
`recursiveRequestQueryKey()`) parses a raw query string into a nested filter
array, honouring bracketed keys (`a[b][]`). `unsetSpecialQueryParams()` strips
every `_`-prefixed key. `search()` fans out to peer directory search endpoints,
merges hits, re-sorts by descending `_score` (`sortResultArray()`), and merges
facet aggregations (`mergeAggregations()` / `mergeFacets()`, summing counts by
`_id`), returning a paginated envelope.

The backend-specific MongoDB/MySQL query-fragment builders that previously
appeared here (`createMongoDBSearchFilter`, `createMySQLSearchConditions`,
`createMySQLSearchParams`, `createSortForMySQL`, `createSortForMongoDB`) are
removed: `search()` never invoked them, they had zero callers, and the app
resolves search through Elasticsearch and directory fan-out rather than an
inline SQL/Mongo query.

@e2e exclude backend search query compilation and facet merging — covered by PHPUnit, not browser UI

#### Scenario: Special query params are stripped from filters

- GIVEN filters containing `_limit`, `_page`, and a domain field
- WHEN `unsetSpecialQueryParams()` runs
- THEN every `_`-prefixed key is removed and the domain field remains

#### Scenario: Facet counts are summed across sources

- GIVEN two aggregation sets sharing a facet `_id`
- WHEN `mergeFacets()` runs
- THEN the merged entry's `count` is the sum of both sources' counts
