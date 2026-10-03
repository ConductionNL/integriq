# Tasks: gateway-upstream-routing

Kind: code. Size M. Rows `integriq:gw-loadbalance`, `integriq:gw-canary`, `integriq:gw-content-route`.

## Implementation tasks

### Task 1: Targets on the endpoint schema
- **spec_ref**: `openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001`
- **files**: `lib/Settings/integriq_register.json` (endpoint `targets`, `sticky`; call_log `routedTarget`, `routedReason`)
- **acceptance_criteria**:
  - GIVEN an endpoint without targets WHEN called THEN it behaves as before
- [ ] Implement
- [ ] Test (existing endpoint tests pass unchanged)

### Task 2: Content rules and weighted pick
- **spec_ref**: `openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001`
- **files**: `lib/Service/Endpoint/UpstreamSelector.php`, `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN weights 95 and 5 WHEN 10,000 selections are made THEN the split is within one percent of the weights
  - GIVEN a when rule on body.zaaktype WHEN a matching body arrives THEN the rule's target is used
- [ ] Implement
- [ ] Test (PHPUnit with a seeded random source; sticky picks stable per consumer)

### Task 3: Skip open breakers and retry once
- **spec_ref**: `openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-a-target-that-is-down-is-skipped-req-uprt-002`
- **files**: `lib/Service/Endpoint/UpstreamSelector.php`, `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN one of two targets with an open breaker WHEN called THEN only the other is used
  - GIVEN a POST that got a 503 WHEN the endpoint has two targets THEN no retry is made
- [ ] Implement
- [ ] Test (PHPUnit with fake sources)

### Task 4: The endpoint editor and the per-target split
- **spec_ref**: `openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-the-call-log-records-which-target-served-a-call-req-uprt-003`
- **files**: the endpoint editor, `src/manifest.json` (split widget on the endpoint detail), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN they add a second target with weight 5 THEN the endpoint page shows both and, after traffic, the split per target
- [ ] Implement
- [ ] Test (Playwright)

## Verification
- [ ] `openspec validate gateway-upstream-routing --type change --strict` passes
- [ ] PHPUnit and Playwright run, exit codes read
