# Tasks: gateway-response-cache-and-problem-errors

Kind: code. Size M. Rows `integriq:gw-cache`, `integriq:gw-problem-json`.

## Implementation tasks

### Task 1: The response cache
- **spec_ref**: `openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001`
- **files**: `lib/Service/Endpoint/EndpointResponseCache.php`, `lib/Service/EndpointService.php`, `lib/Settings/integriq_register.json` (endpoint `cache`, call_log `cacheHit`)
- **acceptance_criteria**:
  - GIVEN a cached GET endpoint WHEN the same consumer calls twice within the lifetime THEN the source is called once and the second answer carries X-Cache HIT
  - GIVEN an upstream answer with Cache-Control private WHEN called twice THEN the source is called twice
- [x] Implement
  - `lib/Service/Endpoint/EndpointResponseCache.php` keeps a source endpoint's answers in `ICacheFactory::createDistributed('integriq.endpoint.response')`, keyed on endpoint, generation, method, path, sorted query, vary headers and the resolved consumer (unless `sharedAcrossConsumers`). Only GET and HEAD are ever cached, `private`/`no-store` never, a lower upstream `max-age` shortens the lifetime (unless `ignoreUpstreamMaxAge`), answers over 1 MiB are served but not kept. `EndpointService::handleSourceRequest()` looks up after the before-rules (authentication, rate limit) and before the upstream call; a hit answers with `X-Cache: HIT` and writes a call log with `cacheHit: true` and no upstream request, a miss stores and answers `X-Cache: MISS`. Endpoint schema gained `cache`, call_log `cacheHit`.
- [x] Test (PHPUnit with a fake source and an in-memory cache)
  - `EndpointResponseCacheTest` (13) and `EndpointServiceResponseCacheTest` (4: one source call for two asks with HIT and the hit logged, private reaches the source twice, no cache says nothing, a clear reaches the source again).

### Task 2: Cache settings on the endpoint page and the clear action
- **spec_ref**: `openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001`
- **files**: the endpoint editor, `lib/Controller/EndpointsController.php`, `appinfo/routes.php`, `lib/actions.seed.json`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator on a cached endpoint WHEN they press clear cache THEN the next call reaches the source
- [x] Implement
  - `EndpointResponseCacheController::clear()` on `POST /api/endpoints/{id}/cache/clear`, gated by the action `endpoint.cache.clear` (admin by default, in `lib/actions.seed.json`), documented in `openapi.json`. A separate controller rather than `EndpointsController`, whose public router should not grow an admin dependency. The endpoint form (`EndpointFormFields.vue`) has a Response cache section (on/off, lifetime, vary headers, shared across consumers, ignore the upstream max-age) and a Clear cache button with an inline status message. Strings in `l10n/en.json` and `nl.json`, `.js` rebuilt with `npm run l10n:build`.
- [ ] Test (Playwright; PHPUnit for the generation bump)
  - PHPUnit done: `EndpointResponseCacheControllerTest` (anonymous 401, no action 403, unknown 404, a permitted clear), the generation bump in `EndpointResponseCacheTest`; vitest `endpointResponseCache.spec.js`. Left open: the Playwright test `tests/e2e/endpoint-cache.spec.ts` needs a running instance.

### Task 3: The problem document class
- **spec_ref**: `openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002`
- **files**: `lib/Http/ProblemResponse.php`, `docs/problems/*.md`
- **acceptance_criteria**:
  - GIVEN a validation failure WHEN rendered THEN the content type is application/problem+json and invalid-params lists each field
- [x] Implement
- [x] Test (PHPUnit on the class)

### Task 4: Route every gateway error through it
- **spec_ref**: `openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN each error path in EndpointService WHEN it fires THEN the answer is a problem document that still carries error and details
- [x] Implement
- [x] Test (PHPUnit covering authentication, rate limit, validation and upstream errors; Newman checks the content type)

## Verification
- [ ] `openspec validate gateway-response-cache-and-problem-errors --type change --strict` passes
- [ ] `grep -c "new JSONResponse" lib/Service/EndpointService.php` shows no error return left outside ProblemResponse
- [ ] PHPUnit and Newman run, exit codes read
