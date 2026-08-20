# Tasks: ori-public-serving

## Implementation Tasks

### Task 1: Author the 10 ORI Endpoint + Mapping register.d configs
- **spec_ref**: `openspec/changes/ori-public-serving/specs/ori-public-serving/spec.md#requirement-anonymous-ori-resource-dispatch-req-oripub-001`, `#requirement-fixed-per-resource-filter-injection-req-oripub-002`, `#requirement-field-projection-to-ori-popolo-json-ld-shape-req-oripub-003`
- **files**: `lib/Settings/openconnector_ori_register.d/*.json` (or the app's existing register.d convention), `openspec/changes/ori-public-serving/design.md` Seed Data (source table)
- **acceptance_criteria**:
  - GIVEN the 10 resources in design.md D1 WHEN each Endpoint is created THEN it carries `targetType: register/schema`, the correct `targetId`, no `authentication` rule, and the `inputMapping` fixed-filter recipe from D3/contract.md's per-resource table
  - GIVEN each resource's Mapping recipe WHEN the after-rule runs THEN field projection matches `OriSerializer::FIELD_RULES`/`PAYLOAD_FIELD_RULES`/`EMAIL_TYPES` (design.md D4)
  - GIVEN design.md Gap 1 WHEN the collection response is rendered THEN it is spiked first as a single mapping recipe (list-mode sub-mapping under `items` + fixed `@context`/`@type` literals + `count` passthrough); if that does not work, fall back to two chained `mapping`-type after-rules (list-mapping then envelope-mapping) — either way TC-7 (test-plan.md) must pass before this task is considered done
- [ ] Implement
- [ ] Test

### Task 2: Implement REQ-EP-010 (declarative id-fetch guard) and verify Risk 3 (publish-window RBAC propagation)
- **spec_ref**: `openspec/changes/ori-public-serving/specs/endpoint-runtime/spec.md#requirement-declarative-id-fetch-guard-for-single-object-get-req-ep-010`, `openspec/changes/ori-public-serving/specs/ori-public-serving/spec.md#requirement-single-item-404-non-disclosure-across-discriminator-lifecycle-and-publish-window-gates-req-oripub-004`
- **files**: `lib/Service/EndpointService.php` (`getObjects()` id-branch, ~line 1737-1754)
- **acceptance_criteria**:
  - GIVEN an Endpoint with a declared fixed filter set WHEN `getObjects()`'s id-branch resolves an object that fails that filter set THEN it returns HTTP 404 instead of the object (TC-8, TC-9 in test-plan.md — both MUST fail before this task starts and MUST pass after, proving the guard is load-bearing, not vacuous)
  - GIVEN an Endpoint with no declared fixed filter set WHEN the same code path runs THEN behaviour is unchanged from pre-change (existing Endpoints are unaffected — regression check)
  - GIVEN TC-10 (publish-window, publications resource) WHEN run against both `OriController` (control) and the new Endpoint THEN the two responses agree; if the new Endpoint returns 200 where the control returns 404, Risk 3 does not hold and the publish-window field must be added to the same declarative guard (do not assume RBAC propagation without this empirical check)
- [ ] Implement
- [ ] Test

### Task 3: Wire anonymous rate-limiting and CORS parity for the ORI Endpoints
- **spec_ref**: `openspec/changes/ori-public-serving/contract.md#error-codes`, `openspec/changes/ori-public-serving/design.md#security-considerations`
- **files**: `lib/Settings/openconnector_ori_register.d/*.json` (Endpoint consumer/rate-limit config), openconnector's existing `consumer-management` rate-limit + CORS config surface
- **acceptance_criteria**:
  - GIVEN 121 requests in 60 seconds to one ORI Endpoint WHEN the ceiling is exceeded THEN request 121 returns 429 (TC-12), matching `OriController`'s current `AnonRateLimit(limit: 120, period: 60)`
  - GIVEN an `OPTIONS` preflight to an ORI Endpoint WHEN it is served THEN `Access-Control-Allow-*` headers match `OriController::applyCorsHeaders()`'s current values (TC-13)
- [ ] Implement
- [ ] Test

### Task 4: Run the full parity test plan; fix diffs; file the notubiz-ibabs-griffie-koppeling fold/close recommendation
- **spec_ref**: `openspec/changes/ori-public-serving/test-plan.md`
- **files**: none (validation + a cross-repo note, no new source in this task)
- **acceptance_criteria**:
  - GIVEN the 10 Endpoints deployed under a validation path prefix (test-plan.md preamble) WHEN TC-1 through TC-14 run against both `OriController` and the new Endpoints THEN every TC passes with zero response diffs
  - GIVEN decidesk's pending `notubiz-ibabs-griffie-koppeling` change (`kind: openconnector`) proposes new NOTUBIZ/iBabs adapters that duplicate openconnector's already-shipped `NotuBizConnectorService`/`IBabsConnectorService`/`RISPollJob` (archived changes `2026-06-14-ibabs-notubiz-connector`, `2026-06-15-decidesk-ris-import-bundle`) WHEN this task completes THEN a note/issue is filed against decidesk's change recommending it fold into or reference the shipped connectors instead of re-specifying them, and TC-14 (non-regression against the existing RIS poll job) has run and passed
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for `EndpointService::getObjects()`'s new id-fetch guard (Task 2) and for the mapping recipes' field-projection output (Task 1)
- [ ] Newman/Postman collection covering test-plan.md TC-1 through TC-14 against the validation-prefix Endpoints
- [ ] Browser tests (Playwright MCP) — N/A, this change has no Vue/UI surface; ORI is a public JSON API only
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation — N/A for openconnector's `docs/`; the ORI contract is documented in this change's `contract.md` and design.md, which is the correct home for a config-driven Endpoint capability (no new UI to screenshot)
- [ ] Screenshot captured — N/A, no UI surface (see above)

## i18n (company-wide hydra ADR-007)

- [ ] N/A — ORI field names and vocabulary are fixed by the ORI/Popolo standard, not localised (matches `OriController`/`OriSerializer` today; see design.md's `ori-public-serving` spec Non-Functional Requirements)
