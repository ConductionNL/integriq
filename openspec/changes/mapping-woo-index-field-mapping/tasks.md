# Tasks: mapping-woo-index-field-mapping

Kind: code. Sibling matrix row `opencatalogi:woo-metadata-map`, integriq's half.

### Task 1: The typed event and its listener
- **spec_ref**: openspec/changes/mapping-woo-index-field-mapping/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
- **files**: `lib/Event/MappingExecutionRequestedEvent.php`, `lib/EventListener/MappingExecutionRequestedListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a callable mapping WHEN the event is dispatched with its slug THEN the output is set and `isHandled()` is true
  - GIVEN an unknown slug WHEN the event is dispatched THEN it is refused with `not-found`
  - GIVEN a slug that OpenRegister's `find()` does not resolve WHEN the listener runs THEN the slug fallback still finds the mapping
- [ ] Implement
- [ ] Test (PHPUnit with the real MappingService and a seeded mapping)

### Task 2: callableBy on the mapping
- **spec_ref**: openspec/changes/mapping-woo-index-field-mapping/specs/woo-index-mapping/spec.md#requirement-a-mapping-names-the-apps-allowed-to-run-it-by-event-req-woom-002
- **files**: `lib/Settings/register.d/mapping-woo-index-field-mapping.json`, `lib/EventListener/MappingExecutionRequestedListener.php`, `src/views/wrappers/MappingDetailPage.vue`
- **acceptance_criteria**:
  - GIVEN a mapping without `callableBy` WHEN a sibling dispatches it THEN it is refused with `not-allowed`
  - GIVEN a non-administrator WHEN they try to change `callableBy` THEN the change is refused
- [ ] Implement
- [ ] Test (PHPUnit on the listener; `node tests/validate-register.js`)

### Task 3: The seeded Woo-index mapping
- **spec_ref**: openspec/changes/mapping-woo-index-field-mapping/specs/woo-index-mapping/spec.md#requirement-integriq-seeds-an-editable-woo-index-mapping-req-woom-003
- **files**: `lib/Settings/register.d/mapping-woo-index-field-mapping.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the seed runs THEN `woo-index-publication` exists with four rules and `callableBy: ["opencatalogi"]`
  - GIVEN an edited rule WHEN the seed runs again THEN the edit is kept
  - GIVEN the mapping detail page WHEN an administrator edits the `officieleTitel` rule THEN the test panel shows the new value
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/woo-index-mapping.spec.ts`; integration test on the seed import)

### Task 4: Tell opencatalogi
- **spec_ref**: openspec/changes/mapping-woo-index-field-mapping/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
- **files**: an issue on ConductionNL/opencatalogi, `docs/` page for the event contract
- **acceptance_criteria**:
  - GIVEN this change is merged WHEN the issue is opened THEN it names the event class, the slug `woo-index-publication`, the result slot and the two refusal codes
- [ ] Implement
- [ ] Test (the docs page is linked from the issue)

## Verification

- `openspec validate mapping-woo-index-field-mapping --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/woo-index-mapping.spec.ts` green against a local instance
