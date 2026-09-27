# Tasks: integriq-adapter-psp

## Implementation Tasks

### Task 1: Seed the iDEAL ouderbijdrage payment-source template
- **spec_ref**: `openspec/specs/psp-source-template/spec.md#requirement-a-seeded-mock-mode-ideal-payment-source-template-is-discoverable-in-the-catalog-req-001`
- **files**: `lib/Settings/register.d/ideal-ouderbijdrage-source.json`
- **acceptance_criteria**:
  - GIVEN the seed fragment WHEN `CatalogRegistryService::collect()` is called THEN it returns an entry with slug `source-template:ideal-ouderbijdrage`
- [x] Implement
- [x] Test

### Task 2: Prove the template is genuinely wired (catalog assembly + working mock payment)
- **spec_ref**: `openspec/specs/psp-source-template/spec.md#requirement-the-seeded-configuration-produces-a-working-mock-ideal-payment-req-002`
- **files**: `tests/Unit/Service/CatalogRegistryServiceTest.php`, `tests/Unit/Settings/IdealOuderbijdrageSourceTemplateTest.php`
- **acceptance_criteria**:
  - GIVEN the existing `testCollectAssemblesFromAllThreeSources` test WHEN extended with this slug THEN it still passes
  - GIVEN the seeded configuration WHEN passed unchanged to `LogPaymentProvider::createPayment()` THEN the result's `extras.method` is `ideal` and `paymentStatus` is `open`
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria (unit-level)
- [ ] Code review against spec requirements (pending PR review)

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- N/A Newman/Postman — no new HTTP endpoint in this change
- N/A Browser tests (Playwright MCP) — no UI in this change (the Catalog UI itself already exists and is unchanged)
- [x] All tests pass (`vendor/bin/phpunit --filter IdealOuderbijdrageSourceTemplateTest|CatalogRegistryServiceTest`)

## Documentation (company-wide ADR-010)

- N/A Feature documentation — the Catalog page and its Instantiate action are already documented; this change only adds one more template to an existing, documented mechanism
- N/A Screenshot — no UI change

## i18n (company-wide hydra ADR-007)

- N/A no new user-facing strings — `name`/`description` follow the same operator-facing metadata shape as every existing `register.d` template
