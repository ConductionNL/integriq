# Test Plan: slo-kerndoelen-import

All cases are PHPUnit unit tests that run offline against the recorded fixture (`lib/Adapters/Slo/slo-curriculum-recorded.json`). There is no UI and no HTTP endpoint, so browser, Newman and persona runs do not apply.

## Test Cases

### TC-1: Seeded source is dormant, credential-free and attributed
- **spec_ref**: `openspec/changes/slo-kerndoelen-import/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001`
- **type**: security
- **preconditions**: the register.d fragment on disk
- **steps**: decode it, find the `source` and both `mapping` objects
- **expected result**: `isEnabled` false, auth `basic`, no credential keys, attribution names SLO, CC BY 4.0 and the licence URL; catalogue lists `source-template:slo-curriculum`
- **test command**: `vendor/bin/phpunit -c phpunit-unit.xml --filter 'SloCurriculumSourceTemplateTest|CatalogRegistryServiceTest'`

### TC-2: Mapping presets produce exactly the contract fields
- **spec_ref**: `...#requirement-mapping-presets-name-learniqs-contract-fields-req-002`
- **type**: regression
- **preconditions**: preset registry over the real fragment
- **steps**: map a normalised framework and node
- **expected result**: object keys equal the preset keys; literals pass through
- **test command**: `vendor/bin/phpunit -c phpunit-unit.xml --filter SloCurriculumMapperTest`

### TC-3: Mock serves recordings; live client uses CallService; errors throw
- **spec_ref**: `...#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003`
- **type**: regression
- **preconditions**: mock over the fixture; live client over mocked `CallService` and object service
- **steps**: fetch a recorded path, an unrecorded path, a path with status 401
- **expected result**: body returned; `SloCurriculumException` for the unrecorded path and for 401; `Accept` header and endpoint passed through
- **test command**: `vendor/bin/phpunit -c phpunit-unit.xml --filter 'SloCurriculumClientMockTest|SloCurriculumClientHttpTest'`

### TC-4: JSONTag reader
- **spec_ref**: `...#requirement-jsontag-responses-are-read-into-linked-arrays-req-004`
- **type**: regression
- **steps**: read annotated objects, links, strings containing `<` and `>`, empty objects, malformed input
- **expected result**: `@type`/`@id` set, `@link` references, strings verbatim, exception on malformed input
- **test command**: `vendor/bin/phpunit -c phpunit-unit.xml --filter JsonTagReaderTest`

### TC-5: Tree walk per profile
- **spec_ref**: `...#requirement-the-tree-walk-follows-the-set-profile-req-005`
- **type**: regression
- **steps**: walk the recorded FO, 2006 kerndoelen, examenprogramma and leerdoelenkaart trees; walk synthetic trees with a deprecated node, a duplicate, a bare reference and a depth overrun
- **expected result**: counts and parent links as specified; skips counted; expansion through `/uuid/`; guard exception
- **test command**: `vendor/bin/phpunit -c phpunit-unit.xml --filter SloCurriculumTreeWalkerTest`

### TC-6: Year allocation
- **spec_ref**: `...#requirement-years-come-only-from-slos-own-niveaus-req-006`
- **type**: regression
- **steps**: allocate for groep, band, VO leerjaar (uuid and title), phase, reference level
- **expected result**: canonical labels, sorted, unique; nothing for non-year niveaus
- **test command**: `vendor/bin/phpunit -c phpunit-unit.xml --filter SloYearAllocatorTest`

### TC-7: Import produces stable, attributed, parent-first records
- **spec_ref**: `...#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007`
- **type**: regression
- **steps**: import each recorded set twice; import with a subject map; import with a bad tenant id and a bad map value
- **expected result**: identical uuids; parents before children; attribution in description; subjectId only top level; `InvalidArgumentException` on bad input
- **test command**: `vendor/bin/phpunit -c phpunit-unit.xml --filter SloCurriculumSourceAdapterTest`

### TC-8: Discovery and paging
- **spec_ref**: `...#requirement-roots-are-discovered-through-slos-collection-routes-req-008`
- **type**: api
- **steps**: discover each recorded set; page through a two-page collection
- **expected result**: roots with uuid, title, status; `perPage` sent; deprecated skipped
- **test command**: `vendor/bin/phpunit -c phpunit-unit.xml --filter SloCurriculumSourceAdapterTest`

### TC-9: No personal data, no secrets, count-only logs
- **spec_ref**: `...#requirement-no-personal-data-and-no-secrets-req-009`
- **type**: security
- **steps**: scan fixture and fragment; capture the adapter's log context
- **expected result**: no e-mail address, token or password value; log keys limited to set, root, counts, flavour
- **test command**: `vendor/bin/phpunit -c phpunit-unit.xml --filter 'SloCurriculumSourceTemplateTest|SloCurriculumSourceAdapterTest'`

## Coverage Summary
- REQ-001: TC-1 covered
- REQ-002: TC-2 covered
- REQ-003: TC-3 covered
- REQ-004: TC-4 covered
- REQ-005: TC-5 covered
- REQ-006: TC-6 covered
- REQ-007: TC-7 covered
- REQ-008: TC-8 covered
- REQ-009: TC-9 covered

## Out of Scope
- A live call to SLO: no registered key exists yet (design.md "Fixture provenance"). The live client is covered with a mocked `CallService`.
- Writing into learniq: the follow-up change.
