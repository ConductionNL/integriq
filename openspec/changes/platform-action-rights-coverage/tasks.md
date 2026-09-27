# Tasks: platform-action-rights-coverage

Kind: code. Matrix rows `integriq:plt-action-matrix` and `integriq:plt-roles`.

### Task 1: Complete the seed and guard it
- **spec_ref**: openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-every-enforced-action-is-in-the-seed-req-arc-001
- **files**: `lib/actions.seed.json`, `tests/Unit/Auth/ActionSeedCoverageTest.php`
- **acceptance_criteria**:
  - GIVEN the seed WHEN the test runs THEN all 34 missing actions are present with `admin`, a label and an area
  - GIVEN a new unseeded literal in a controller WHEN the test runs THEN it fails naming the action
- [ ] Implement
- [ ] Test (PHPUnit ActionSeedCoverageTest, first run red on the current seed)

### Task 2: Areas and labels on the screen
- **spec_ref**: openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-the-screen-groups-actions-by-area-with-labels-req-arc-002
- **files**: `lib/Controller/ActionMatrixController.php`, `src/views/admin/ActionAuthMatrix.vue`
- **acceptance_criteria**:
  - GIVEN the screen WHEN it loads THEN actions appear in area sections with labels
  - GIVEN a group ticked for `stuf-zkn.push` WHEN saved THEN a member can push and a non-member gets 403
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/action-rights-coverage.spec.ts`)

### Task 3: Measure today's object posture
- **spec_ref**: openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
- **files**: `tests/Integration/ObjectRightsPostureTest.php`, `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN a live instance WHEN a user in no group tries each verb on each of the nine schemas THEN the outcome is recorded, and the `object.*` seed values equal it
- [ ] Implement
- [ ] Test (integration test against OpenRegister; the recorded posture goes in the PR body)

### Task 4: Apply object rights on save and after import
- **spec_ref**: openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-survive-a-register-import-req-arc-004
- **files**: `lib/Service/ObjectRightsService.php`, `lib/Controller/ActionMatrixController.php`, `lib/Repair/ApplyObjectRights.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN `object.mapping.update` granted to a group WHEN saved THEN the mapping schema's authorization lists that group and `admin`
  - GIVEN a register import WHEN the repair step runs THEN the stored grants are back on the schemas
  - GIVEN a refused schema update WHEN saving THEN the answer names the schema
- [ ] Implement
- [ ] Test (PHPUnit on the service and repair step; Playwright for the mapping versus source scenario)

## Verification

- `openspec validate platform-action-rights-coverage --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/action-rights-coverage.spec.ts` green against a local instance
