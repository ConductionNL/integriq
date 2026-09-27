# Tasks: connectors-course-marketplace

Kind: code. Size M. Row `learniq:cont-outside-provider-catalogue`. Depends on
`connectors-lti-platform-launch` for the launch route.

### Task 1: The shared mappings onto learniq
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
- **files**: `lib/Settings/configurations/course-marketplace-mappings.json` (Course, Lesson, LtiToolPlacement)
- **acceptance_criteria**:
  - GIVEN one provider course fixture WHEN the three mappings run THEN a draft `Course`, an `lti` `Lesson` and a placement with the deployment id are produced and pass learniq's required fields
- [ ] Implement
- [ ] Test (PHPUnit validating the mapped objects against learniq's schemas)

### Task 2: The Go1 set
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
- **files**: `lib/Settings/configurations/course-marketplace-go1.json`, `tests/fixtures/course-marketplace/go1/`
- **acceptance_criteria**:
  - GIVEN Go1's published API documentation WHEN the set is written THEN its description cites the endpoints and auth it uses
  - GIVEN the mock catalogue WHEN the synchronization runs with one collection selected THEN only that collection's courses are written
- [ ] Implement
- [ ] Test (`tests/e2e/course-marketplace.spec.ts` against the mock source)

### Task 3: The LinkedIn Learning set
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-an-administrator-imports-a-selection-not-the-whole-catalogue-req-cmkt-002
- **files**: `lib/Settings/configurations/course-marketplace-linkedin-learning.json`, `tests/fixtures/course-marketplace/linkedin-learning/`
- **acceptance_criteria**:
  - GIVEN a selection of language `nl` WHEN the synchronization runs against the fixture THEN only Dutch courses are written
- [ ] Implement
- [ ] Test (PHPUnit on the set against the fixture)

### Task 4: The Udemy Business set
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-providers-catalogue-arrives-in-learniq-as-draft-courses-that-launch-through-lti-req-cmkt-001
- **files**: `lib/Settings/configurations/course-marketplace-udemy-business.json`, `tests/fixtures/course-marketplace/udemy-business/`
- **acceptance_criteria**:
  - GIVEN no `lti_deployment` WHEN the synchronization runs THEN nothing is written and the log names the missing deployment
- [ ] Implement
- [ ] Test (`tests/e2e/course-marketplace.spec.ts`)

### Task 5: Retire withdrawn courses
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-a-withdrawn-course-is-retired-never-deleted-req-cmkt-003
- **files**: the three sets' deletion configuration, `lib/Service/SynchronizationService.php` only if REQ-010 cannot write a lifecycle value instead of deleting
- **acceptance_criteria**:
  - GIVEN a course missing from a complete fetch WHEN the run finishes THEN the course is `archived` and the placement `retired`, and neither is deleted
  - GIVEN an incomplete fetch WHEN the run finishes THEN nothing is retired
- [ ] Implement
- [ ] Test (integration test on the synchronization with two fixtures)

### Task 6: Selection fields on the source form and demo data
- **spec_ref**: openspec/changes/connectors-course-marketplace/specs/course-marketplace-connectors/spec.md#requirement-an-administrator-imports-a-selection-not-the-whole-catalogue-req-cmkt-002
- **files**: `src/modals/v2/SourceFormFields.vue`, `lib/Settings/integriq_mock_register.json` (`lti_tool` and `lti_deployment` per provider), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a course marketplace source WHEN the form opens THEN it shows collection, language and text filter fields
  - GIVEN demo data WHEN an administrator opens the LTI deployments THEN one per provider is listed
- [ ] Implement
- [ ] Test (`tests/e2e/course-marketplace.spec.ts`)

## Verification

- `openspec validate connectors-course-marketplace --type change --strict`
- With a Go1 sandbox account: import one collection, publish one course in
  learniq, and open it as a learner through the launch route.
- `composer check:strict` and `npm run lint` once before push.
