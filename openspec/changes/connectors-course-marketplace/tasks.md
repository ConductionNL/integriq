# Tasks: connectors-course-marketplace

Kind: code. Size M. Row `learniq:cont-outside-provider-catalogue`. Depends on
`connectors-lti-platform-launch` for the launch route.

### Task 1: The shared mappings onto learniq
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
- **files**: `lib/Settings/register.d/course-marketplace-connectors.json` (design D5: `configurations/` is read for the ZGW sets only), `lib/Twig/MappingRuntime.php` + `lib/Twig/MappingExtension.php` (`uuidFor()`, design D6), `tests/fixtures/course-marketplace/learniq-schemas.json`
- **acceptance_criteria**:
  - GIVEN one provider course fixture WHEN the three mappings run THEN a draft `Course`, an `lti` `Lesson` and a placement with the deployment id are produced and pass learniq's required fields
- [x] Implement
  - Three mappings per provider rather than one shared set: the three catalogue shapes differ, and a mapping reads one shape. They follow one template (design D6, D7). The deployment id is the install's own and ships empty; learniq refuses a placement without it.
- [x] Test (PHPUnit validating the mapped objects against learniq's schemas)
  - `tests/Unit/Settings/CourseMarketplaceSetsTest.php` runs the real MappingService over each provider's canned page and validates every object with Opis against learniq's Course, Lesson and LtiToolPlacement (copied from learniq development ac27f3f); `tests/Unit/Twig/MappingRuntimeUuidForTest.php` pins `uuidFor()`.

### Task 2: The Go1 set
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
- **files**: `lib/Settings/register.d/course-marketplace-connectors.json` (the Go1 source, mappings and synchronizations), `tests/fixtures/course-marketplace/go1/`
- **acceptance_criteria**:
  - GIVEN Go1's published API documentation WHEN the set is written THEN its description cites the endpoints and auth it uses
  - GIVEN the mock catalogue WHEN the synchronization runs with one collection selected THEN only that collection's courses are written
- [x] Implement
  - The source cites `GET https://gateway.go1.com/learning-objects` (`type[]=course`, `limit` at most 50, `offset`, answer `total` + `hits`) and OAuth 2.0 client credentials at `https://auth.go1.com/oauth/token`. The selection is the synchronizations' `conditions`. A Go1 collection filter is not pinned: the published parameter for it was not confirmed, so a collection selection is a condition on the course until it is.
- [ ] Test (`tests/e2e/course-marketplace.spec.ts` against the mock source)
  - PHPUnit half: `CourseMarketplaceSetsTest`. The e2e run needs the mock source of Task 6 and a live instance.

### Task 3: The LinkedIn Learning set
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-an-administrator-imports-a-selection-not-the-whole-catalogue-req-cmkt-002
- **files**: `lib/Settings/register.d/course-marketplace-connectors.json` (the LinkedIn Learning source, mappings and synchronizations), `tests/fixtures/course-marketplace/linkedin-learning/`
- **acceptance_criteria**:
  - GIVEN a selection of language `nl` WHEN the synchronization runs against the fixture THEN only Dutch courses are written
- [x] Implement
  - The source cites `GET https://api.linkedin.com/v2/learningAssets?q=criteria&assetFilteringCriteria.assetTypes[0]=COURSE`, paged by `start`/`count`, and OAuth 2.0 client credentials at `https://www.linkedin.com/oauth/v2/accessToken`.
- [x] Test (PHPUnit on the set against the fixture)
  - `CourseMarketplaceSetsTest::testASelectionOfDutchAvgCoursesLetsOnlyThoseThrough` (all three providers) and `testASecondRunDerivesTheSameIdsAndDifferentCoursesDoNot`.

### Task 4: The Udemy Business set
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
- **files**: `lib/Settings/register.d/course-marketplace-connectors.json` (the Udemy Business source, mappings and synchronizations), `tests/fixtures/course-marketplace/udemy-business/`
- **acceptance_criteria**:
  - GIVEN no `lti_deployment` WHEN the synchronization runs THEN nothing is written and the log names the missing deployment
- [ ] Implement
  - The set is built: `GET https://<portal>.udemy.com/api-2.0/organizations/<portal-id>/courses/list/`, paged by `page`/`page_size`, HTTP Basic with the client id and secret. Open: the guard. Today a run without a deployment writes the course and the lesson and learniq refuses the placement (`testASeededPlacementWithoutADeploymentIsRefusedByLearniq`); "nothing is written, the log names the deployment" needs a pre-run check in the engine, built with Task 5's engine piece.
- [ ] Test (`tests/e2e/course-marketplace.spec.ts`)

### Task 5: Retire withdrawn courses
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-withdrawn-course-is-retired-never-deleted-req-cmkt-003
- **files**: the three sets' deletion configuration, `lib/Service/SynchronizationService.php` only if REQ-010 cannot write a lifecycle value instead of deleting
- **acceptance_criteria**:
  - GIVEN a course missing from a complete fetch WHEN the run finishes THEN the course is `archived` and the placement `retired`, and neither is deleted
  - GIVEN an incomplete fetch WHEN the run finishes THEN nothing is retired
- [x] Implement
  - REQ-010 could not write a lifecycle value, so the engine gained one: `sourceConfig.disappearanceValues`, read by `DisappearanceApplier::valuesFrom()` (a malformed declaration is refused like an unknown policy) and written by `applyToObject()` under `markEnded` and `keepAndFlag`. Each set declares `archived` for the Course and `retired` for the Lesson and the placement.
- [x] Test (integration test on the synchronization with two fixtures)
  - `tests/Unit/Service/SynchronizationServiceRetireValuesTest.php` runs `deleteInvalidObjects()` with two contracts and one course gone: complete fetch archives it and deletes nothing, incomplete fetch writes nothing. `CourseMarketplaceSetsTest::testAWithdrawnCourseIsRetiredToAValueLearniqAccepts` validates each retired object against learniq's schema; `DisappearancePolicyTest` covers the values and their refusal.

### Task 6: Selection fields on the source form and demo data
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-an-administrator-imports-a-selection-not-the-whole-catalogue-req-cmkt-002
- **files**: `src/modals/v2/SourceFormFields.vue`, `lib/Settings/integriq_mock_register.json` (`lti_tool` and `lti_deployment` per provider), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a course marketplace source WHEN the form opens THEN it shows collection, language and text filter fields
  - GIVEN demo data WHEN an administrator opens the LTI deployments THEN one per provider is listed
- [ ] Implement
- [ ] Test (`tests/e2e/course-marketplace.spec.ts`)

### Task 7: Tell the provider's tool which course to open
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
- **files**: `lib/Service/Lti/LtiPlatformLoginService.php` (launch claims), `lib/Service/SynchronizationContractService.php` (read)
- **acceptance_criteria**:
  - GIVEN a placement written by a course marketplace synchronization WHEN it is launched THEN the id_token carries the provider course id (the contract's origin id) in the LTI custom claim (design D8)
- [ ] Implement
- [ ] Test (PHPUnit on the claims builder with a real contract row)

## Verification

- `openspec validate connectors-course-marketplace --type change --strict`
- With a Go1 sandbox account: import one collection, publish one course in
  learniq, and open it as a learner through the launch route.
- `composer check:strict` and `npm run lint` once before push.
