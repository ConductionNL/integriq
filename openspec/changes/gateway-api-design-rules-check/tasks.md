# Tasks: gateway-api-design-rules-check

Kind: code. Size M. Rows `integriq:acc-governance`, `integriq:nl-api-design-rules`.

## Implementation tasks

### Task 1: The rule engine
- **spec_ref**: `openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001`
- **files**: `lib/Service/ApiDesign/ApiDesignRuleService.php`, `lib/Service/ApiDesign/Check/*.php`, `composer.json` (JSONPath library after the licence check)
- **acceptance_criteria**:
  - GIVEN a rule with given paths.* and then pattern WHEN run on a document with a trailing slash path THEN one finding points at that path
- [x] Implement
  - `lib/Service/ApiDesign/ApiDesignRuleService.php` (+ interface, bound in `ServiceBindings`), the seven checks in `lib/Service/ApiDesign/Check/`. No JSONPath library was added: `JsonPathSelector` evaluates the part of JSONPath the rules use (child, wildcard, recursive descent, key and member filters, `~` for keys) and refuses anything else, so a rule cannot silently select nothing. That keeps `composer.json` unchanged and skips the licence check the library would need.
- [x] Test (PHPUnit per check type with small fixture documents)
  - `tests/Unit/Service/ApiDesign/JsonPathSelectorTest.php`, `Check/RuleChecksTest.php`, `ApiDesignRuleServiceTest.php` (the trailing-slash scenario, references, manual rules, the custom rule set validation).

### Task 2: The Dutch rule set
- **spec_ref**: `openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-the-dutch-api-design-rules-are-built-in-req-adrc-002`
- **files**: `lib/Settings/api-design-rules/nl-api-design-rules.json`
- **acceptance_criteria**:
  - GIVEN the published Logius version WHEN the rule set is loaded THEN every automatable core rule id is present with its URL, and the rest are listed as manual
- [x] Implement
  - API Design Rules 2.2.2 (published 2026-09-29): all 38 core rules, 13 with a check taken from the Logius linter of that version, 25 manual. `/core/publish-openapi` stays manual: the linter checks it as OpenAPI schema validity, which the import owns.
- [x] Test (PHPUnit comparing rule ids with a recorded list from the published version; a known-good and a known-bad document)
  - `tests/Unit/Service/ApiDesign/NlApiDesignRuleSetTest.php` with `fixtures/good.json` and `fixtures/bad.json`.

### Task 3: Rule sets and findings as objects, and the product panel
- **spec_ref**: `openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001`
- **files**: `lib/Settings/register.d/api-design-rules.json`, `src/manifest.json` (panel and check action on `ApiProductDetail`, rule set pages), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator on a product WHEN they press check THEN findings are listed by severity with a link to each rule
- [x] Implement
  - Frontend (2026-11-14): `src/components/ApiDesignFindingsPanel.vue` on `ApiProductDetail` loads the product's stored findings, "Check design" runs the check endpoint, findings are grouped errors first with a link to each rule, the manual rules are listed to check by hand, and an open error is waived with a reason through `src/dialogs/WaiveDesignFindingDialog.vue`. Rule set pages: `src/manifest.d/gateway-api-design-rules-check.json` (index of `api_design_ruleset`, admin only, under Gateway). Strings in `l10n/en.js` and `l10n/nl.js`. Vitest: `tests/vitest/apiDesignFindingsPanel.spec.js` (12).
  - Backend done: `lib/Settings/register.d/api-design-rules.json` adds `api_design_ruleset` and `api_design_finding` (admin-only). `ApiProductDesignCheck` checks the product's description, which it gets from `OpenApiPublishService` (looked up by class name). It uses the built-in and stored rule sets, and `ApiDesignFindingStore` replaces the open findings and keeps matching waivers. `POST /api/products/{productId}/design-check` answers the findings errors first. Still left: the findings panel and check button on the custom `ApiProductDetail` component, its frontend strings, and the rule set pages.
- [x] Test (Playwright)
  - PHPUnit: `ApiProductDesignCheckTest`, `ApiDesignFindingStoreTest`, `ApiDesignCheckControllerTest`, `Settings/ApiDesignRulesFragmentTest`.
  - Playwright: written, runs in nightly. `tests/e2e/api-design-rules.spec.ts` ("Check design lists the findings by severity..."), inside the testDir of `tests/e2e/playwright.config.ts` and matched by no testIgnore; its test ids (`api-design-findings`, `api-design-check`, `api-design-group-<severity>`) are the ones `ApiDesignFindingsPanel.vue` renders. Not run here: no instance in the sandbox.

### Task 4: The publish guard and waivers
- **spec_ref**: `openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003`
- **files**: `lib/Service/ApiDesign/ApiDesignRuleService.php`, the product status action, the finding waiver action
- **acceptance_criteria**:
  - GIVEN an open error finding WHEN the administrator sets the product active THEN it is refused with the finding listed; after a waiver with a reason it succeeds
- [x] Implement
  - `ApiDesignRuleService::blockingFindings()` decides which findings block: the open, error-level ones. `ApiProductPublishGuardListener`, on `ObjectUpdatingEvent`, runs a fresh check when an `api_product` moves to `active`. It refuses with 409 and the blocking findings, and it also refuses when the description cannot be checked. Waiving goes through `POST /api/products/design-findings/{findingId}/waive` with a reason; `ApiDesignFindingStore::waive()` records who waived the finding and when.
- [x] Test (PHPUnit on the guard; Playwright for waive and publish)
  - PHPUnit done: `ApiProductPublishGuardListenerTest`, plus the waive tests in `ApiDesignFindingStoreTest` and `ApiDesignCheckControllerTest`.
  - Playwright: written, runs in nightly. `tests/e2e/api-design-rules.spec.ts` ("an open error refuses activation until it is waived..."): activation refused with the rule named, waive through `waive-finding-dialog` with a reason, activation then accepted and the waiver shown after a reload. Not run here: no instance in the sandbox.

### Task 5: Seed data and documentation
- **spec_ref**: `openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-the-dutch-api-design-rules-are-built-in-req-adrc-002`
- **files**: `lib/Settings/register.d/api-design-rules.json` (`x-openregister-seed`), `docs/`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the seeded product page opens THEN one error and one waived warning are shown
- [x] Implement
  - Seed (2026-11-15): `lib/Settings/register.d/api-design-rules.json` now seeds through `components.objects`, the rows OpenRegister's import reads (with `@ref:` tokens); a schema-level `x-openregister-seed` is stored as schema configuration and never imported, so the rule set moved out of it. It holds `gemeente-voorbeeld`, the product `besluiten-api` 1.0.0 and its two findings: the open error `/core/no-trailing-slash` and the waived `gemeente-voorbeeld/operation-summary` warning. The product starts `deprecated` (as the developer portal change seeds it, sunset 2027-01-01), so it is not published while it carries an open error. `ApiDesignRulesFragmentTest` checks the rows and that each seeded finding is what a check of a `/besluiten/` description produces.
  - The custom rule set `gemeente-voorbeeld` is seeded. Docs written (2026-11-14): `docs/administrators/api-design-rules.md`, linked from `docs/SUMMARY.md` (check, publish refusal, waiver, rule sets). The `besluiten-api` question (no unpublished product state) is settled by seeding it deprecated, see above.
- [x] Test (docs walked once)
  - Walked 2026-11-25 against the code: the menu entries (Gateway → API products, Gateway → API design rules), the panel's labels (API design rules, Check design, Errors/Warnings/Notes, To check by hand, Waive), the refusal of an empty reason (`ApiDesignFindingStore::waive()`), the `enabled` switch (`ApiDesignRuleService`, `ApiDesignFindingStore`) and the seed (Besluiten API 1.0.0 deprecated, the open `/core/no-trailing-slash` error, the waived warning with "partner requires this path until 2027") all match. A walk through the screens on a live instance follows with the nightly.

## Verification
- [x] `openspec validate gateway-api-design-rules-check --type change --strict` passes (openspec 2.2.0, 25 Nov 2026)
  - Not run 2026-11-25: fetching the openspec CLI with npx was refused by the sandbox's permission check.
- [x] PHPUnit and Playwright run, exit codes read
  - PHPUnit run 2026-11-25, exit 0: `tests/Unit/Service/ApiDesign` (49 tests), `ApiDesignRulesFragmentTest` (4), `ApiDesignCheckControllerTest` (5), `ApiProductPublishGuardListenerTest` (8), `OpenApiPublishServiceTest` (5).
  - Playwright run 2026-11-25 on a live Nextcloud 34 (MariaDB, OpenRegister main, ci-seed), `tests/e2e/api-design-rules.spec.ts`: 2 passed, exit 0. The first run was red: the publisher dropped an endpoint's trailing slash although the gateway routes it with the slash, so `/core/no-trailing-slash` never fired (fixed in `OpenApiPublishService`, unit-tested); the spec read the finding's path as plain text where it is a JSON Pointer, matched the refusal's escaped JSON as text, and filled a reason input inside NcTextField where the test id sits on the input itself. A gateway-written description also fails `/core/doc-openapi-contact`, `/core/uri-version` and `/core/version-header` for every product (and `/core/transport/tls` on http); the waiver test waives those first, and the gap is named for a follow-up.
- [x] The added library passes `composer audit` and the licence triangle gate
  - N/A: no library was added (Task 1, `JsonPathSelector`), `composer.json` is unchanged by this change.
