# Tasks: slo-kerndoelen-import

## Implementation Tasks

### Task 1: Seed the dormant source template and the two mapping presets
- **spec_ref**: `openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001`
- **files**: `lib/Settings/register.d/slo-curriculum-source.json`, `lib/Service/CatalogRegistryService.php`, `tests/Unit/Settings/SloCurriculumSourceTemplateTest.php`, `tests/Unit/Service/CatalogRegistryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN read THEN the source is dormant, auth basic, credential-free, attributed, and the catalogue lists it
- [ ] Implement
- [ ] Test

### Task 2: Record the fixture from real SLO release data
- **spec_ref**: `openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003`
- **files**: `lib/Adapters/Slo/slo-curriculum-recorded.json`
- **acceptance_criteria**:
  - GIVEN the fixture WHEN read THEN it holds discovery and tree responses for FO burgerschap, 2006 Engels and Fries, examenprogramma Tekenen vwo and one leerdoelenkaart branch, with provenance in `$comment`
- [ ] Implement
- [ ] Test

### Task 3: Build the client (abstract, mock, live) and its DI binding
- **spec_ref**: `openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003`
- **files**: `lib/Adapters/Slo/SloCurriculumClient.php`, `lib/Adapters/Slo/SloCurriculumClientMock.php`, `lib/Adapters/Slo/SloCurriculumClientHttp.php`, `lib/Exception/SloCurriculumException.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN the flag off WHEN the client resolves THEN it is the mock; GIVEN status 401 WHEN the live client fetches THEN it throws
- [ ] Implement
- [ ] Test

### Task 4: Read JSONTag
- **spec_ref**: `openspec/specs/slo-curriculum-import/spec.md#requirement-jsontag-responses-are-read-into-linked-arrays-req-004`
- **files**: `lib/Adapters/Slo/JsonTagReader.php`
- **acceptance_criteria**:
  - GIVEN an annotated body WHEN read THEN objects carry @type and @id and links are references
- [ ] Implement
- [ ] Test

### Task 5: Walk a tree by profile
- **spec_ref**: `openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005`
- **files**: `lib/Adapters/Slo/SloCurriculumTreeWalker.php`
- **acceptance_criteria**:
  - GIVEN the burgerschap tree WHEN walked THEN 3 domeinen, 6 kernzinnen and 10 doelzinnen are emitted parent first
- [ ] Implement
- [ ] Test

### Task 6: Allocate years from SLO niveaus
- **spec_ref**: `openspec/specs/slo-curriculum-import/spec.md#requirement-years-come-only-from-slos-own-niveaus-req-006`
- **files**: `lib/Adapters/Slo/SloYearAllocator.php`
- **acceptance_criteria**:
  - GIVEN groep 3-4 WHEN allocated THEN groep 3 and groep 4; GIVEN fase 3 THEN nothing
- [ ] Implement
- [ ] Test

### Task 7: Read the presets and map nodes to learniq records
- **spec_ref**: `openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007`
- **files**: `lib/Adapters/Slo/SloCurriculumPresetRegistry.php`, `lib/Adapters/Slo/SloCurriculumMapper.php`, `lib/Exception/UnknownSloCurriculumSetException.php`
- **acceptance_criteria**:
  - GIVEN the same input twice WHEN mapped THEN identical UUID v5 ids; object keys equal the preset keys
- [ ] Implement
- [ ] Test

### Task 8: Source adapter facade with discovery and import
- **spec_ref**: `openspec/specs/slo-curriculum-import/spec.md#requirement-roots-are-discovered-through-slos-collection-routes-req-008`
- **files**: `lib/Sources/Slo/SloCurriculumSourceAdapter.php`, `tests/Unit/Sources/Slo/SloCurriculumSourceAdapterTest.php`
- **acceptance_criteria**:
  - GIVEN the mock WHEN each recorded set is discovered and imported THEN records match the contract and logs carry counts only
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off
- `openspec validate slo-kerndoelen-import` passes
- Diff-scoped checks while building; `composer check:strict`, `npm run lint`, `npm run format`, `npm run test:l10n` and the hydra gates once before push

## Tests (company-wide ADR-009)
- PHPUnit unit tests for every new class (`tests/Unit/Adapters/Slo/`, `tests/Unit/Sources/Slo/`, `tests/Unit/Settings/`)
- Newman: N/A, no endpoint
- Browser: N/A, no UI

## Documentation (company-wide ADR-010)
- N/A for user docs: no user-facing surface until the learniq write step lands. design.md records how to go live.

## i18n (company-wide hydra ADR-007)
- N/A: no UI strings. Goal texts and scale labels are Dutch data values.
