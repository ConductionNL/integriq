# Tasks: platform-action-rights-coverage

Kind: code. Matrix rows `integriq:plt-action-matrix` and `integriq:plt-roles`.

### Task 1: Complete the seed and guard it
- **spec_ref**: openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-every-enforced-action-is-in-the-seed-req-arc-001
- **files**: `lib/actions.seed.json`, `tests/Unit/Auth/ActionSeedCoverageTest.php`
- **acceptance_criteria**:
  - GIVEN the seed WHEN the test runs THEN all 34 missing actions are present with `admin`, a label and an area
  - GIVEN a new unseeded literal in a controller WHEN the test runs THEN it fails naming the action
- [x] Implement
  - The seed now lists 106 actions; 37 were enforced but missing (one more than the 34 counted when this was written, plus `propertySource.query.brp`). Every action has `admin`, a label and an area; `exchange.read` keeps its broader default.
- [x] Test (PHPUnit ActionSeedCoverageTest, first run red on the current seed)
  - Red on the old seed with the 36 missing names and the unseeded `propertySource.query.` prefix. The test reads the source: literals, own and imported class constants (lists too), a `$parameter` followed to the calls of its helper, and a variable assigned from a method returning literals. Built names must use a listed prefix with a seeded variant. Anything else fails, naming file and line.

### Task 2: Areas and labels on the screen
- **spec_ref**: openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-the-screen-groups-actions-by-area-with-labels-req-arc-002
- **files**: `lib/Controller/ActionMatrixController.php`, `src/views/admin/ActionAuthMatrix.vue`
- **acceptance_criteria**:
  - GIVEN the screen WHEN it loads THEN actions appear in area sections with labels
  - GIVEN a group ticked for `stuf-zkn.push` WHEN saved THEN a member can push and a non-member gets 403
- [x] Implement
  - `getMatrix()` returns the seed's `labels` and `areas`; the screen shows one section per area, each action's label with its name beneath.
- [x] Test (Playwright `tests/e2e/action-rights-coverage.spec.ts`)
  - Live 25 Nov 2026 (sandbox: Nextcloud 34.0.6, MariaDB, Redis, OpenRegister main, integriq development): `tests/e2e/action-rights-coverage.spec.ts` 3 passed. The run found that a refused action answered 500, not 403: `requireAction()` throws `OCSForbiddenException`, which Nextcloud maps to its status only for an OCSController, and about 34 plain controllers call it. `ActionRefusalMiddleware` answers it with its own status (403) as JSON. Non-member `stuf-zkn.push` now answers 403 with problem details.
  - Unit-level: `ActionMatrixControllerTest`, `tests/vitest/actionMatrixSections.spec.js`, `adminSettingsPanelsRender.spec.js`. The Playwright run (delegate, then member and non-member push) needs a live instance.
  - `tests/e2e/action-rights-coverage.spec.ts` is written (listed under its area, delegate `stuf-zkn.push` through the screen, member push passes and non-member gets 403); not yet run against a live instance.

### Task 3: Measure today's object posture
- **spec_ref**: openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-per-configuration-schema-are-set-on-the-screen-req-arc-003
- **files**: `tests/Integration/ObjectRightsPostureTest.php`, `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN a live instance WHEN a user in no group tries each verb on each of the nine schemas THEN the outcome is recorded, and the `object.*` seed values equal it
- [x] Implement
  - Seeded from OpenRegister's rule for a schema without a block (every signed-in user, `authenticated`) and the three lockdown fragments (admin only); the live measurement is still to run.
- [x] Test (integration test against OpenRegister; the recorded posture goes in the PR body)
  - Live 25 Nov 2026 (sandbox: Nextcloud 34.0.6, MariaDB, Redis, OpenRegister main, integriq development): `tests/Integration/ObjectRightsPostureTest.php` with `INTEGRIQ_POSTURE_BASE_URL` and `INTEGRIQ_POSTURE_ADMIN`: OK (1 test, 3 assertions); the measured posture equals the seed, so the seed needs no change. A user in no group may create, read, update and delete mappings, synchronizations and event subscriptions, and create jobs, endpoints and API products; sources, rules and consumers are admin only.
  - `tests/Integration/ObjectRightsPostureTest.php` is written: a user in no group tries each verb on each schema through the object API and the outcome must equal the seed. It self-skips without `INTEGRIQ_POSTURE_BASE_URL` / `INTEGRIQ_POSTURE_ADMIN`; not yet run against a live instance.

### Task 4: Apply object rights on save and after import
- **spec_ref**: openspec/changes/platform-action-rights-coverage/specs/action-authorization/spec.md#requirement-object-rights-survive-a-register-import-req-arc-004
- **files**: `lib/Service/ObjectRightsService.php`, `lib/Controller/ActionMatrixController.php`, `lib/Repair/ApplyObjectRights.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN `object.mapping.update` granted to a group WHEN saved THEN the mapping schema's authorization lists that group and `admin`
  - GIVEN a register import WHEN the repair step runs THEN the stored grants are back on the schemas
  - GIVEN a refused schema update WHEN saving THEN the answer names the schema
- [x] Implement
  - `ObjectRightsService::apply()` writes each `object.<schema>.<verb>` row onto the schema's block (found by application and slug), always with `admin`, keeping keys the matrix does not govern. A row still at its seed is the register's own posture: `["admin"]` for source, rule and consumer, the verb removed (OpenRegister's default) for the six others, so an untouched matrix writes nothing. `setMatrix()` applies after storing and answers `notApplied` and a `warning` naming the schemas; `ApplyObjectRights` runs after InitializeRegister and InitializeActions in post-migration and install.
- [x] Test (PHPUnit on the service and repair step; Playwright for the mapping versus source scenario)
  - Live 25 Nov 2026 (sandbox: Nextcloud 34.0.6, MariaDB, Redis, OpenRegister main, integriq development): the mapping-versus-source scenario passes. The first run found that granting `object.mapping.update` to a group wrote `{update: [admin, group]}` alone, and OpenRegister reads a verb missing from a block as admin only, so every non-admin (the team included) lost read access to mappings and the member's edit answered 404. `authorizationFor()` now writes the untouched open verbs out as `authenticated` as soon as any verb is in the block, and leaves no block when every verb is back at its open seed. Live after the fix: the member reads and edits the mapping (200), a non-member reads it (200) and is refused the edit (403). PHPUnit `testGrantingOneVerbKeepsTheOtherOpenVerbsOpen`, `testAllVerbsBackAtTheirOpenSeedLeaveNoBlock`.
  - PHPUnit `ObjectRightsServiceTest`, `ApplyObjectRightsTest`, `ActionMatrixControllerTest`; vitest `actionMatrixObjectRights.spec.js` for the screen. The Playwright scenario is in `tests/e2e/action-rights-coverage.spec.ts`, not yet run against a live instance.

## Verification

- `openspec validate platform-action-rights-coverage --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/action-rights-coverage.spec.ts` green against a local instance
