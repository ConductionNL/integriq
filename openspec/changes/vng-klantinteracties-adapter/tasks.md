# Tasks: vng-klantinteracties-adapter

## Implementation Tasks

### Task 1: Composite transactional fan-out Rule type
- **spec_ref**: `openspec/specs/rule-pipeline/spec.md#req-rule-006`
- **files**: `lib/Service/RuleProcessingService.php`, `lib/Rule/CompositeFanoutRule.php`
- **acceptance_criteria**:
  - GIVEN a fan-out Rule and a parent+children body WHEN it runs THEN all objects are created atomically
  - GIVEN a child write fails WHEN the Rule runs THEN the whole operation rolls back with one error
- [ ] Implement
- [ ] Test

### Task 2: `referentienummer` generation Rule
- **spec_ref**: `openspec/specs/rule-pipeline/spec.md#req-rule-007`
- **files**: `lib/Rule/ReferentienummerRule.php`, `lib/Service/RuleProcessingService.php`
- **acceptance_criteria**:
  - GIVEN the Rule enabled WHEN a resource is emitted THEN a unique UUIDv4 referentienummer is stamped
  - GIVEN a configured scheme WHEN a resource is emitted THEN the reference follows the scheme
- [ ] Implement
- [ ] Test

### Task 3: VNG list-filter operator translation onto OpenRegister search
- **spec_ref**: `openspec/specs/mapping-and-search/spec.md#req-006`
- **files**: `lib/Service/MappingService.php` (search compiler)
- **acceptance_criteria**:
  - GIVEN `field__icontains` WHEN compiled THEN an OpenRegister contains filter is produced
  - GIVEN a `partijIdentificator` BSN filter WHEN compiled THEN it matches the stored hash, never a raw BSN
- [ ] Implement
- [ ] Test

### Task 4: `expand=` relation embedding with bounded depth
- **spec_ref**: `openspec/specs/mapping-and-search/spec.md#req-007`
- **files**: `lib/Service/MappingService.php` (search compiler)
- **acceptance_criteria**:
  - GIVEN `expand=digitaleAdressen` WHEN resolved THEN relations are embedded inline
  - GIVEN nesting beyond the cap WHEN resolved THEN expansion stops at the documented depth
- [ ] Implement
- [ ] Test

### Task 5: Absolute self-URL / HAL `_links` output helper
- **spec_ref**: `openspec/specs/endpoint-runtime/spec.md#req-ep-006`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN the helper enabled WHEN a resource is emitted THEN it carries an absolute `url` and HAL `_links`
  - GIVEN two hosts WHEN each renders THEN each `url` reflects its own host
- [ ] Implement
- [ ] Test

### Task 6: PUT-all-mandatory vs PATCH-partial enforcement
- **spec_ref**: `openspec/specs/endpoint-runtime/spec.md#req-ep-007`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN PUT missing a mandatory field WHEN dispatched THEN it is rejected
  - GIVEN a PATCH subset WHEN dispatched THEN only supplied fields change
- [ ] Implement
- [ ] Test

### Task 7: AVG BSN policy Rule (validate + hash inbound, no raw outbound)
- **spec_ref**: `openspec/specs/vng-klantinteracties-adapter/spec.md#req-003`
- **files**: `lib/Rule/AvgBsnPolicyRule.php`, `lib/Service/RuleProcessingService.php`
- **acceptance_criteria**:
  - GIVEN an inbound BSN WHEN the Rule runs THEN it is 11-proef-validated and SHA-256-hashed via the BRP flow before storage
  - GIVEN a stored hashed identity WHEN rendered outbound THEN no raw BSN is reconstructed
- [ ] Implement
- [ ] Test

### Task 8: Package + seed the VNG Klantinteracties configuration set (ADR-015)
- **spec_ref**: `openspec/specs/vng-klantinteracties-adapter/spec.md#req-001`
- **files**: `configuration/vng-klantinteracties.oas.json`, seed `_registers.json` entries
- **acceptance_criteria**:
  - GIVEN the Endpoints/Mappings/Rules/Consumer per design.md Seed Data WHEN exported THEN references are slugs and credentials are placeholders
  - GIVEN a clean environment WHEN the OAS config is imported THEN the VNG surface responds under `/api/endpoint/klantinteracties/...`
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off
- `openspec validate vng-klantinteracties-adapter --type change --strict` passes
- Manual Newman run against the packaged config in a clean environment
- Code review against spec requirements (esp. AVG BSN policy)

## Tests (company-wide ADR-009)
- PHPUnit unit tests for composite fan-out, referentienummer, AVG BSN policy, filter/expand compiler, self-URL helper, PUT/PATCH
- Newman/Postman tests for the VNG Klantinteracties endpoints incl. `maak-klantcontact`
- Browser tests: N/A — backend gateway capability, no browser UI

## Documentation (company-wide ADR-010)
- Document the AVG BSN raw-value deviation from VNG in `docs/`
- Document the packaged config slugs consumed by the pipelinq leaf

## i18n (company-wide hydra ADR-007)
- Dutch (`nl_NL`) and English (`en_US`) strings for new validation/error messages
