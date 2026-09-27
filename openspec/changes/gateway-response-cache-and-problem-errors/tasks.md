# Tasks: gateway-response-cache-and-problem-errors

Kind: code. Size M. Rows `integriq:gw-cache`, `integriq:gw-problem-json`.

## Implementation tasks

### Task 1: The response cache
- **spec_ref**: `openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001`
- **files**: `lib/Service/Endpoint/EndpointResponseCache.php`, `lib/Service/EndpointService.php`, `lib/Settings/integriq_register.json` (endpoint `cache`, call_log `cacheHit`)
- **acceptance_criteria**:
  - GIVEN a cached GET endpoint WHEN the same consumer calls twice within the lifetime THEN the source is called once and the second answer carries X-Cache HIT
  - GIVEN an upstream answer with Cache-Control private WHEN called twice THEN the source is called twice
- [ ] Implement
- [ ] Test (PHPUnit with a fake source and an in-memory cache)

### Task 2: Cache settings on the endpoint page and the clear action
- **spec_ref**: `openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001`
- **files**: the endpoint editor, `lib/Controller/EndpointsController.php`, `appinfo/routes.php`, `lib/actions.seed.json`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator on a cached endpoint WHEN they press clear cache THEN the next call reaches the source
- [ ] Implement
- [ ] Test (Playwright; PHPUnit for the generation bump)

### Task 3: The problem document class
- **spec_ref**: `openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002`
- **files**: `lib/Http/ProblemResponse.php`, `docs/problems/*.md`
- **acceptance_criteria**:
  - GIVEN a validation failure WHEN rendered THEN the content type is application/problem+json and invalid-params lists each field
- [ ] Implement
- [ ] Test (PHPUnit on the class)

### Task 4: Route every gateway error through it
- **spec_ref**: `openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN each error path in EndpointService WHEN it fires THEN the answer is a problem document that still carries error and details
- [ ] Implement
- [ ] Test (PHPUnit covering authentication, rate limit, validation and upstream errors; Newman checks the content type)

## Verification
- [ ] `openspec validate gateway-response-cache-and-problem-errors --type change --strict` passes
- [ ] `grep -c "new JSONResponse" lib/Service/EndpointService.php` shows no error return left outside ProblemResponse
- [ ] PHPUnit and Newman run, exit codes read
