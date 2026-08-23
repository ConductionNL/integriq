# Retrofit — endpoint-runtime

Describes the observed behaviour of 32 methods under the `endpoint-runtime`
cluster as 5 new REQs. Code already exists — this change retroactively
specifies it. Source: `openspec/coverage-report.md` generated 2026-05-24,
Bucket 2b cluster `endpoint-runtime` (no capability owner).

## Motivation

The endpoint dispatch / caching / normalisation layer is core platform
behaviour with no spec coverage. It is the request entry point for every
configured Integriq endpoint and needs a foundational capability spec
under `openspec/specs/`. This is the second of two shared-`EndpointService`
clusters; the rule-evaluation half is covered separately by the `rule-pipeline`
cluster. Target dispatch follows ADR-008 (`targetType` / `targetId` polymorphic
resolution).

## Affected code units

`lib/Controller/EndpointsController.php`:
- `handlePath()`, `preflightedCors()`, `logs()`, `getPathParameters()`, `buildPaginationUrl()` — REQ-EP-001
- `isSimpleEndpoint()`, `handleSimpleSchemaRequest()` — REQ-EP-002

`lib/Service/EndpointService.php` (dispatch + normalisation methods):
- `handleRequest()`, `handleSchemaRequest()`, `handleSourceRequest()`, `getObjects()`, `checkConditions()`, `generateEndpointUrl()`, `getPathParameters()`, `getRuleById()` — REQ-EP-003
- `parseContent()`, `looksLikeXml()`, `getRawContent()`, `getHeaders()`, `parseMessage()`, `transformError()`, `replaceInternalReferences()`, `rewriteExternalReferences()`, `replaceUuidsInArray()`, `reduceExtendKeys()` — REQ-EP-005

`lib/Service/EndpointCacheService.php`:
- `findByPathRegex()`, `getAllEndpoints()`, `fetchEndpointsFromOr()`, `refreshCache()`, `clearCache()`, `createEndpointRegex()`, `getCacheStats()` — REQ-EP-004

(Rule-evaluation methods in `EndpointService.php` are annotated under the
sibling `rule-pipeline` cluster, per the coverage report's cluster assignment.)

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes.
- Draft REQs that match behaviour (not aspirational).
- Notes sections surface observed-but-suspicious behaviour:
  - `handleRequest` returns the full exception stack trace in the HTTP 400 body — information disclosure (REQ-EP-003).
  - `getRuleById` silently drops unresolvable rule ids (REQ-EP-003).
  - `createEndpointRegex` is a by-design duplicate of `EndpointMapper::createEndpointRegex` (REQ-EP-004).
  - `logs()` returns an empty paginated result as a placeholder until call-log wiring lands (REQ-EP-001).

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
