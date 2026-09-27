# Tasks: gateway-api-design-rules-check

Kind: code. Size M. Rows `integriq:acc-governance`, `integriq:nl-api-design-rules`.

## Implementation tasks

### Task 1: The rule engine
- **spec_ref**: `openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001`
- **files**: `lib/Service/ApiDesign/ApiDesignRuleService.php`, `lib/Service/ApiDesign/Check/*.php`, `composer.json` (JSONPath library after the licence check)
- **acceptance_criteria**:
  - GIVEN a rule with given paths.* and then pattern WHEN run on a document with a trailing slash path THEN one finding points at that path
- [ ] Implement
- [ ] Test (PHPUnit per check type with small fixture documents)

### Task 2: The Dutch rule set
- **spec_ref**: `openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-the-dutch-api-design-rules-are-built-in-req-adrc-002`
- **files**: `lib/Settings/api-design-rules/nl-api-design-rules.json`
- **acceptance_criteria**:
  - GIVEN the published Logius version WHEN the rule set is loaded THEN every automatable core rule id is present with its URL, and the rest are listed as manual
- [ ] Implement
- [ ] Test (PHPUnit comparing rule ids with a recorded list from the published version; a known-good and a known-bad document)

### Task 3: Rule sets and findings as objects, and the product panel
- **spec_ref**: `openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001`
- **files**: `lib/Settings/register.d/api-design-rules.json`, `src/manifest.json` (panel and check action on `ApiProductDetail`, rule set pages), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator on a product WHEN they press check THEN findings are listed by severity with a link to each rule
- [ ] Implement
- [ ] Test (Playwright)

### Task 4: The publish guard and waivers
- **spec_ref**: `openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003`
- **files**: `lib/Service/ApiDesign/ApiDesignRuleService.php`, the product status action, the finding waiver action
- **acceptance_criteria**:
  - GIVEN an open error finding WHEN the administrator sets the product active THEN it is refused with the finding listed; after a waiver with a reason it succeeds
- [ ] Implement
- [ ] Test (PHPUnit on the guard; Playwright for waive and publish)

### Task 5: Seed data and documentation
- **spec_ref**: `openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-the-dutch-api-design-rules-are-built-in-req-adrc-002`
- **files**: `lib/Settings/register.d/api-design-rules.json` (`x-openregister-seed`), `docs/`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the seeded product page opens THEN one error and one waived warning are shown
- [ ] Implement
- [ ] Test (docs walked once)

## Verification
- [ ] `openspec validate gateway-api-design-rules-check --type change --strict` passes
- [ ] PHPUnit and Playwright run, exit codes read
- [ ] The added library passes `composer audit` and the licence triangle gate
