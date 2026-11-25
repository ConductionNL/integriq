# Tasks: gateway-upstream-routing

Kind: code. Size M. Rows `integriq:gw-loadbalance`, `integriq:gw-canary`, `integriq:gw-content-route`.

## Implementation tasks

### Task 1: Targets on the endpoint schema
- **spec_ref**: `openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001`
- **files**: `lib/Settings/integriq_register.json` (endpoint `targets`, `sticky`; call_log `routedTarget`, `routedReason`) — built as the ADR-037 fragment `lib/Settings/register.d/gateway-upstream-routing.json`, with the `callsPerTarget` count on `call_log` (verified: tests/Unit/Settings/GatewayUpstreamRoutingFragmentTest.php)
- **acceptance_criteria**:
  - GIVEN an endpoint without targets WHEN called THEN it behaves as before
- [x] Implement
- [x] Test (existing endpoint tests pass unchanged)

### Task 2: Content rules and weighted pick
- **spec_ref**: `openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-an-endpoint-routes-to-one-of-several-targets-req-uprt-001`
- **files**: `lib/Service/Endpoint/UpstreamSelector.php`, `lib/Service/EndpointService.php` (verified: tests/Unit/Service/Endpoint/UpstreamSelectorTest.php)
- **acceptance_criteria**:
  - GIVEN weights 95 and 5 WHEN 10,000 selections are made THEN the split is within one percent of the weights
  - GIVEN a when rule on body.zaaktype WHEN a matching body arrives THEN the rule's target is used
- [x] Implement
- [x] Test (PHPUnit with a seeded random source; sticky picks stable per consumer)

### Task 3: Skip open breakers and retry once
- **spec_ref**: `openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-a-target-that-is-down-is-skipped-req-uprt-002`
- **files**: `lib/Service/Endpoint/UpstreamSelector.php`, `lib/Service/Endpoint/UpstreamRouter.php`, `lib/Service/EndpointService.php` (verified: tests/Unit/Service/Endpoint/UpstreamRouterTest.php, EndpointServiceTest::testAnEndpointWithTargetsIsRoutedAndAnswers503WhenNoneIsHealthy)
- **acceptance_criteria**:
  - GIVEN one of two targets with an open breaker WHEN called THEN only the other is used
  - GIVEN a POST that got a 503 WHEN the endpoint has two targets THEN no retry is made
- [x] Implement
- [x] Test (PHPUnit with fake sources)

### Task 4: The endpoint editor and the per-target split
- **spec_ref**: `openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-the-call-log-records-which-target-served-a-call-req-uprt-003`
- **files**: the endpoint editor, `src/manifest.json` (split widget on the endpoint detail), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN they add a second target with weight 5 THEN the endpoint page shows both and, after traffic, the split per target
- [x] Implement
  - The endpoint editor offers target type `api`, whose fields are `src/modals/v2/EndpointTargetsFields.vue`: the one source (`targetId`) while the endpoint has no targets, else the targets with source, weight, group and each one's share of its group (`endpointTargets.js`, the same groups and even split at weight 0 as `UpstreamSelector`), and the sticky switch once there are two. A stored `when` rule is kept and the row says it has one; rules are not authored here. The source picker is the shared mixin `src/mixins/sourceOptions.js`, now also used by `McpEndpointFields`. The endpoint page gains the chart `ep-target-split` ("Calls per target", a donut over `call_log` filtered to the endpoint, grouped by `routedTarget`, labelled with the source name), shown only when the endpoint has targets. Vitest: `tests/vitest/endpointTargetsFields.spec.js`, `tests/vitest/endpointFormFieldsRender.spec.js`.
- [x] Test (Playwright); written, runs in nightly
  - Written, not run here: `tests/e2e/endpoint-targets.spec.ts` (the dialog shows two targets at 95% and 5% and adds a third; the endpoint page shows the calls per target).

## Verification
- [x] `openspec validate gateway-upstream-routing --type change --strict` passes
  - OpenSpec 2.1.1, 2026-11-23: valid under `--strict`.
- [x] PHPUnit and Playwright run, exit codes read
  - PHPUnit 2026-11-25, exit 0: `UpstreamRouterTest` (9), `UpstreamSelectorTest` (11), `GatewayUpstreamRoutingFragmentTest` (2), `EndpointServiceTest` (31), `EndpointServiceMcpTargetTest` (3); vitest `endpointTargetSplitManifest.spec.js` (2).
  - Playwright 2026-11-25 on a live Nextcloud 34 (MariaDB, OpenRegister main, ci-seed), `tests/e2e/endpoint-targets.spec.ts`: 2 passed, exit 0. The first run was red: the endpoint page's "Calls per target" chart drew an empty axis because the manifest gave it the dashboard shape (`props` and a top-level `dataSource`), while a detail page hands a chart only its `content`. The chart's kind and data source moved into `content`; the vitest guards that.
