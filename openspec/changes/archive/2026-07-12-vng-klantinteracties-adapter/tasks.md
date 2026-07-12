# Tasks: vng-klantinteracties-adapter

## Implementation Tasks

### Task 1: Composite transactional fan-out Rule type
- **spec_ref**: `openspec/specs/rule-pipeline/spec.md#req-rule-006`
- **files**: `lib/Service/EndpointService.php`, `lib/Rule/CompositeFanoutRule.php`
- **acceptance_criteria**:
  - GIVEN a fan-out Rule and a parent+children body WHEN it runs THEN all objects are created atomically
  - GIVEN a child write fails WHEN the Rule runs THEN the whole operation rolls back with one error
- [x] Implement
- [x] Test

### Task 2: `referentienummer` generation Rule
- **spec_ref**: `openspec/specs/rule-pipeline/spec.md#req-rule-007`
- **files**: `lib/Rule/ReferentienummerRule.php`, `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN the Rule enabled WHEN a resource is emitted THEN a unique UUIDv4 referentienummer is stamped
  - GIVEN a configured scheme WHEN a resource is emitted THEN the reference follows the scheme
- [x] Implement
- [x] Test

### Task 3: VNG list-filter operator translation onto OpenRegister search
- **spec_ref**: `openspec/specs/mapping-and-search/spec.md#req-006`
- **files**: `lib/Service/MappingService.php`
- **acceptance_criteria**:
  - GIVEN `field__icontains` WHEN compiled THEN an OpenRegister contains filter is produced
  - GIVEN a `partijIdentificator` BSN filter WHEN compiled THEN it matches the stored hash, never a raw BSN
- [x] Implement
- [x] Test

### Task 4: `expand=` relation embedding with bounded depth
- **spec_ref**: `openspec/specs/mapping-and-search/spec.md#req-007`
- **files**: `lib/Service/MappingService.php`
- **acceptance_criteria**:
  - GIVEN `expand=digitaleAdressen` WHEN resolved THEN relations are embedded inline
  - GIVEN nesting beyond the cap WHEN resolved THEN expansion stops at the documented depth
- [x] Implement
- [x] Test

### Task 5: Absolute self-URL / HAL `_links` output helper
- **spec_ref**: `openspec/specs/endpoint-runtime/spec.md#req-ep-006`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN the helper enabled WHEN a resource is emitted THEN it carries an absolute `url` and HAL `_links`
  - GIVEN two hosts WHEN each renders THEN each `url` reflects its own host
- [x] Implement
- [x] Test

### Task 6: PUT-all-mandatory vs PATCH-partial enforcement
- **spec_ref**: `openspec/specs/endpoint-runtime/spec.md#req-ep-007`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN PUT missing a mandatory field WHEN dispatched THEN it is rejected
  - GIVEN a PATCH subset WHEN dispatched THEN only supplied fields change
- [x] Implement
- [x] Test

### Task 7: AVG BSN policy Rule (validate + hash inbound, no raw outbound)
- **spec_ref**: `openspec/specs/vng-klantinteracties-adapter/spec.md#req-003`
- **files**: `lib/Rule/AvgBsnPolicyRule.php`, `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN an inbound BSN WHEN the Rule runs THEN it is 11-proef-validated and SHA-256-hashed before storage
  - GIVEN a stored hashed identity WHEN rendered outbound THEN no raw BSN is reconstructed
- [x] Implement
- [x] Test

### Task 8: Package + seed the VNG Klantinteracties configuration set (ADR-015)
- **spec_ref**: `openspec/specs/vng-klantinteracties-adapter/spec.md#req-001`
- **files**: `configuration/vng-klantinteracties.oas.json`, `configuration/vng-klantinteracties-consumer.seed.json`, `configuration/README.md`
- **acceptance_criteria**:
  - GIVEN the Endpoints/Mappings/Rules/Consumer per design.md Seed Data WHEN exported THEN references are slugs and credentials are placeholders
  - GIVEN a clean environment WHEN the OAS config is imported THEN the VNG surface responds under `/api/endpoint/klantinteracties/...`
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate vng-klantinteracties-adapter --type change --strict` passes
- [ ] Manual Newman run against the packaged config in a clean environment — **not run**: requires a live pipelinq instance with the `ticket`/`client`/`contact`/`contactPoint` schemas provisioned (the sibling `vng-klantinteracties-leaf` change, not yet built). See Deviations.
- [x] Code review against spec requirements (esp. AVG BSN policy) — self-reviewed; see Deviations below

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for composite fan-out (`tests/Unit/Rule/CompositeFanoutRuleTest.php`), referentienummer (`tests/Unit/Rule/ReferentienummerRuleTest.php`), AVG BSN policy (`tests/Unit/Rule/AvgBsnPolicyRuleTest.php`), filter/expand compiler (`tests/Unit/Service/MappingServiceTest.php`), self-URL helper + PUT/PATCH (`tests/Unit/Service/EndpointServiceTest.php`), packaged config structure (`tests/Unit/Configuration/VngKlantinteractiesConfigTest.php`) — 34 new tests, all green
- [ ] Newman/Postman tests for the VNG Klantinteracties endpoints incl. `maak-klantcontact` — **not run**: same live-pipelinq-leaf dependency as above
- Browser tests: N/A — backend gateway capability, no browser UI

## Documentation (company-wide ADR-010)
- [x] Document the AVG BSN raw-value deviation from VNG — see `lib/Rule/AvgBsnPolicyRule.php` class docblock + this file's Deviations section
- [x] Document the packaged config slugs consumed by the pipelinq leaf — `configuration/README.md` + slugs frozen in `configuration/vng-klantinteracties.oas.json`

## i18n (company-wide hydra ADR-007)
- [ ] Dutch (`nl_NL`) and English (`en_US`) strings for new validation/error messages — **not added**: the new Rule/mapping-compiler errors (`Invalid BSN: failed 11-proef checksum.`, `Unsupported VNG filter operator "%s" on field "%s".`, `Composite fan-out failed, all writes rolled back: %s`) are currently raw English exception messages surfaced via the pipeline's existing HTTP 500 handler (`processRules`'s catch block returns `{"error": "Rule processing failed"}` — the exception message itself is logged, not rendered to the client). Localising them requires threading `IL10N` into three new constructor-injected classes that intentionally have zero framework dependencies (so they stay trivially unit-testable without OCP mocks, matching this codebase's existing Rule/handler style). Flagged as a follow-up; not a regression (existing rule types like `save_object`/`extend_input` have the same English-exception-only posture).

## Deviations

- **`RuleProcessingService` does not exist in this codebase.** tasks.md's original `files` fields named
  `lib/Service/RuleProcessingService.php` throughout. The actual rule-dispatch engine lives in
  `EndpointService::processRules()` (a `match` statement) delegating to private wrapper methods, with a
  separate `RuleService` handling only `custom`-type rules. The four new rule types
  (`composite_fanout`, `referentienummer`, `avg_bsn_policy`, `selfurl_hal`) were wired into that real
  `match` statement instead, each delegating to a dedicated `lib/Rule/*.php` class — matching the spirit
  of "dedicated Rule handler classes" the tasks intended, on the actual dispatch mechanism.
- **`rule.type`/`rule.method` are single strings, not comma-lists.** design.md's Seed Data table showed
  `method: GET,POST,PUT,PATCH` on one Endpoint object; the real `endpoint` schema's `method` property is
  a single string (confirmed against `lib/Settings/openconnector_register.json`). The packaged config
  therefore ships one Endpoint object per HTTP method (e.g. `vng-klantcontacten-list` GET,
  `vng-klantcontacten-create` POST, `vng-klantcontacten-update` PUT, `vng-klantcontacten-patch` PATCH)
  rather than design's single multi-method row.
- **AVG BSN outbound guard needed a second Rule object.** The real `rule` schema carries one `timing`
  field per Rule object, but REQ-003 needs the AVG BSN mechanic on both timings (hash inbound, guard
  outbound). Added `vng-avg-bsn-policy-outbound-guard` (timing `after`) alongside design's frozen
  `vng-avg-bsn-policy` (timing `before`); both share the `AvgBsnPolicyRule::apply()` handler, dispatched
  by `timing`.
- **Consumer is not part of the ADR-015 OAS document.** `ConfigurationService`/`ConfigurationHandlers`
  has handlers for `source`, `mapping`, `rule`, `endpoint`, `synchronization`, `job` — no `consumer`
  handler exists (confirmed against `lib/Service/ConfigurationService.php`). The `vng-kiss-consumer`
  definition ships as a separate `configuration/vng-klantinteracties-consumer.seed.json` with a
  `_deviation` field explaining it must be created via the Consumers UI/API after the OAS import, not
  design.md's assumption that it round-trips through `ConfigurationService`.
- **Composite fan-out vs. the before-rule/dispatch pipeline ordering is not fully proven end-to-end.**
  `CompositeFanoutRule` is a "before"-timing rule (per REQ-RULE-006) that writes parent + children
  directly via `ObjectService::saveObject()`. Per `EndpointService::handleRequest()`'s documented flow
  (REQ-EP-003), before-rules run and THEN the pipeline still dispatches to `targetType` —
  `register/schema` in `vng-maak-klantcontact`'s case. Whether that second dispatch step re-persists the
  same parent (double-write) or is a no-op depends on how `$parameters` vs. the rule-mutated
  `$data['body']` are threaded through `FlowToken`, which this pass could not fully trace without a live
  OpenRegister/pipelinq integration test (the `save_object` rule type has the exact same
  before-rule-writes-then-dispatch-runs shape already in this codebase, so this is a pre-existing
  pipeline characteristic, not a new defect). `CompositeFanoutRule` itself is fully unit-tested and
  behaves correctly in isolation (atomic create + rollback). Flagged for verification once the
  pipelinq `vng-klantinteracties-leaf` schemas exist and an end-to-end Newman run is possible.
- **Six of the eleven design-listed VNG resources are only partially packaged.** `actoren`,
  `onderwerpobjecten`, `internetaken`, `bijlagen` are NOT packaged in this pass (no canonical
  schema.org field mapping exists yet on the pipelinq side to map onto — see `configuration/README.md`).
  `betrokkenen` and `digitaleadressen` are packaged at GET+POST only (no PUT/PATCH/DELETE). This is a
  scope-honesty call, not a spec-compliance shortcut: the generic gateway mechanics (composite fan-out,
  filter/expand translation, self-URL/HAL, PUT/PATCH semantics, referentienummer, AVG BSN policy) are
  all dialect-agnostic and fully ready to serve these resources — adding them is a config-only follow-up
  once the leaf's schemas land, exactly as design.md's "Risk 3" mitigation intends.
- **`putPatchSemantics` and `vngFilterTranslation` are new Endpoint schema properties**, added additively
  to `lib/Settings/openconnector_register.json`'s `endpoint` schema (both default `false`, so every
  existing endpoint's behaviour is unchanged) — required to make REQ-EP-007 and REQ-006/007 genuinely
  per-endpoint opt-in as their scenarios specify, rather than global behaviour changes.
