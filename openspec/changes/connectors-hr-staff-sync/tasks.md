# Tasks: connectors-hr-staff-sync

Kind: code. Size M. Row `planninq:tt-hr-sync`.

### Task 1: The Untis target source and the staff synchronization
- **spec_ref**: openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
- **files**: `lib/Settings/configurations/staff-to-untis.json`, its source template (Untis, broker reference, mock mode), the `Employee` and `OrgAssignment` synchronizations and mappings, `tests/fixtures/untis/`
- **acceptance_criteria**:
  - GIVEN developer.untis.com WHEN the set is written THEN its description cites the teacher endpoints and auth it uses
  - GIVEN a new employee with an active assignment WHEN it is saved THEN one teacher create goes to the mock Untis
  - GIVEN an ended assignment WHEN it is saved THEN the teacher is made inactive and not deleted
- [x] Implement
  > 2026-11-25, the assignment half (decision: the last assignment to end counts). `staff-to-untis-teachers` also runs when one of the employee's `OrgAssignment` rows is created or changed (`sourceConfig.triggerFromRelatedObjects`, related by `employeeId`). The teacher mapping reads only the end dates of the employee's assignments through the new mapping function `lookupAll()` (every match of an allow-listed schema, refusing instead of answering past 200 rows) and sends: active while the employment has not ended and at least one assignment is open; exit date the employment end date, else the last assignment end once every assignment has one. An employee without assignment rows follows the employment end date as before. `humaniq/OrgAssignment` must be on the lookup allowlist (source description and README say so). PHPUnit: `StaffToUntisSetTest::testAnOpenAssignmentKeepsTheTeacherActive`, `::testTheLastAssignmentEndIsTheExitDate`, `::testEveryAssignmentEndedMakesTheTeacherInactive`, `::testAnEndedEmploymentWinsOverAnOpenAssignment`, `::testOnlyTheEmployeesOwnAssignmentsCount`, `::testTheAssignmentReadNeedsTheAllowlist`, `::testAnAssignmentChangeRunsTheTeacherPush`; `MappingLookupTest::testLookupAll*`. Mock mode and e-mail stay as noted below (no generic mock answer; Untis keeps e-mail on a user account), both outside this task's acceptance criteria.
  > 2026-11-23, built except the assignment half: `lib/Settings/configurations/staff-to-untis.json` (personalData, importable through the configuration import) with the dormant source `staff-to-untis-source` (the Untis central gateway `https://api.webuntis.com/WebUntis`, OAuth 2 client credentials with Basic auth to `/WebUntis/api/sso/v3/{tenant}/token`, the platform-generated password by broker reference, paced at Untis's 40 requests a second), the mapping `staff-to-untis-teacher` and the synchronization `staff-to-untis-teachers` over `humaniq/Employee` (CREATE and UPDATE only, so a deleted employee sends nothing): a Teacher Management v2 batch create on `POST /api/rest/extern/v2/teachers`, the Untis id read from `teachers.0.id`, an update on `PUT` of the same path keyed by `externKey`. The set's description cites the teacher and absence endpoints and the auth, pinned against developer.untis.com's OpenAPI documents on 23 Nov 2026. Fixtures under `tests/fixtures/untis/`.
  > Open, so the box stays unticked:
  > - `OrgAssignment` does not drive the teacher yet. Today the teacher is active while the Employee's own `endDate` is not past, and an ended employment sends `active: false` with the exit date. Making an ended school assignment deactivate the teacher needs a decision: an employee can hold several `OrgAssignment` rows (schools, roles), and which end counts (the last one, any one at the Untis school's org unit) is not in the spec.
  > - Mock mode: integriq's CallService has no generic `configuration.mock` answer (only the directory, case-system, document-generation and mailbox adapters have one), so the source ships dormant (`isEnabled: false`) instead of in mock mode with a canned teacher list.
  > - E-mail: Untis keeps e-mail on a user account (`ExternUser`), not on the teacher, and humaniq's Employee carries only `privateEmail`; no e-mail is sent. Creating Untis user accounts is out of this change.
- [x] Test (PHPUnit against the mock target; `tests/e2e/staff-to-untis.spec.ts`); written, runs in nightly
  > PHPUnit done: `StaffToUntisSetTest::testANewEmployeeBecomesTheRecordedTeacherCreate`, `::testALeaverIsMadeInactiveNotDeleted`, `::testTheSynchronizationsWriteToTheDocumentedUntisPaths`, `::testTheUntisSourceIsDormantAndBrokered`. Written, not run (it needs a live instance with humaniq and an Untis stub at `UNTIS_STUB_URL`): `tests/e2e/staff-to-untis.spec.ts`.

### Task 2: Approved leave as teacher absence
- **spec_ref**: openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-approved-leave-becomes-an-untis-teacher-absence-req-hrs-002
- **files**: the `LeaveRequest` synchronization and mapping in the set, `triggerOnlyOnEvents` and its condition on `status`
- **acceptance_criteria**:
  - GIVEN a request approved WHEN it is saved THEN one absence create goes to Untis with dates and a neutral reason
  - GIVEN an approved request later rejected WHEN it is saved THEN the absence is deleted in Untis
  - GIVEN a submitted request WHEN it is saved THEN nothing is sent
- [x] Implement (engine part done: `targetConfig.deleteWhenConditionsFail` deletes an earlier-pushed object at the target once the conditions stop holding)
  > 2026-11-23: the synchronization `staff-to-untis-absences` over `humaniq/LeaveRequest`, condition `status == approved`, `deleteWhenConditionsFail: true`, on Teacher Absence Management v1 (`POST /api/rest/extern/v1/teacher-absence`, `PUT` and `DELETE` on `/{id}`). The mapping `staff-to-untis-absence` sends the teacher's Untis id (`getTargetIdByOriginId(employeeId)`, the target id of the employee's teacher contract), the first day from `T00:00:00`, the last day until `T23:59:00` (Untis's local date-times), and `reasonId` as the neutral reason an administrator fills in; empty sends none. Never the leave type, the reason text, the hours or a document. Leave of an employee Untis does not know yet sends no `teacherId`, so Untis refuses it in the call log rather than booking it on no teacher. Limit: the lookup is not scoped to the teacher synchronization (`findBySyncAndOrigin` matches the synchronization's uuid, which a set file cannot know), so it takes the employee's first contract; while no other synchronization pushes humaniq employees that is the teacher contract.
- [x] Test (PHPUnit on the withdrawal done; the set's mapping: `StaffToUntisSetTest::testApprovedLeaveBecomesTheRecordedAbsence`, `::testOnlyApprovedLeaveIsSentAndAWithdrawalDeletes`, `::testTheNeutralReasonIsTheAdministratorsChoice`, `::testLeaveOfAnUnknownTeacherSendsNoTeacherId`)

### Task 3: Allow-list mappings and the install check
- **spec_ref**: openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-allow-listed-fields-leave-the-hr-register-req-hrs-003
- **files**: the set's mappings, the set install guard (a check that refuses pass-through mappings in a set marked `personalData: true`)
- **acceptance_criteria**:
  - GIVEN an employee fixture with BSN and IBAN WHEN it is mapped THEN neither is in the output
  - GIVEN a mapping with `passThrough: true` in the set WHEN the set is installed THEN the install is refused naming the mapping
- [x] Implement (guard done: `PersonalDataSetGuard` refuses a configuration import marked `personalData: true` with a pass-through mapping; the set's own allow-list mappings `staff-to-untis-teacher` and `staff-to-untis-absence` name every target field, `passThrough: false`, and the set is marked `personalData: true`)
- [x] Test (PHPUnit on the guard done; on the set's mappings: `StaffToUntisSetTest::testABsnAndAnIbanNeverLeaveHumaniq` maps an employee fixture with BSN, IBAN, salary, birth date, address and private e-mail, and a leave request with type, reason and document, and finds none of them in what is sent; `::testTheSetIsPersonalDataWithAllowListMappingsOnly`; `::testAPassThroughMappingInTheSetRefusesTheInstall` names the mapping)

### Task 4: Nightly reconciliation
- **spec_ref**: openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
- **files**: `lib/BackgroundJob/StaffReconciliationJob.php`, `lib/AppInfo/Application.php` or `appinfo/info.xml` (job registration)
- **acceptance_criteria**:
  - GIVEN an employee missing in Untis WHEN the job runs THEN the teacher is created
  - GIVEN an incomplete Untis read WHEN the job runs THEN no teacher is made inactive
  - GIVEN an Untis teacher with no humaniq match WHEN the job runs THEN it is listed in the log and not duplicated
- [x] Implement
  > 2026-11-23: built generic. `lib/Service/Synchronization/OutboundReconciliationService.php` reconciles any register-to-api synchronization that declares `targetConfig.reconcile` (`endpoint`, the engine's paging keys, `idPosition`, `match` pairs); `lib/Service/Synchronization/OutboundReconciliationJob.php` runs it daily (registered in `appinfo/info.xml`). It reads the target list with the engine's own paged fetch, pushes each object the target lacks through `synchronizeContract` (force, on its own contract), adopts an unclaimed target item that matches on a `match` pair instead of creating a duplicate, skips objects that fail the synchronization's conditions, lists unclaimed target items in the run log, and never deletes or deactivates. An incomplete read writes nothing and says so in the run log. `staff-to-untis-teachers` opts in (match on `externKey` from the Nextcloud user id or uuid, then `shortName` from the employee number). The list's answer shape (`teachers`, `id`) is confirmed in the live pass with the e2e of task 1. Absences stay event-driven (README).
- [x] Test (PHPUnit on the job with a paged mock that fails halfway)
  > `OutboundReconciliationServiceTest` (missing teacher created, a read that stopped after page one writes nothing, a failed read, adoption and the unmatched list, adoption only once, a gone target pushed again, nothing pushed for in-sync objects, conditions honoured, failed pushes reported); `OutboundReconciliationJobTest`; `StaffToUntisSetTest::testTheTeachersAreReconciledNightlyOnTheMappedKeys`.

### Task 5: The per-system README
- **spec_ref**: openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
- **files**: `lib/Settings/configurations/staff-to-untis.README.md`
- **acceptance_criteria**:
  - GIVEN Zermelo, Xedule and TimeEdit WHEN the README is read THEN each has its documented HR import, its source URL, and what a humaniq school lacks
- [x] Implement (`lib/Settings/configurations/staff-to-untis.README.md`)
- [ ] Test (review)

## Verification

- `openspec validate connectors-hr-staff-sync --type change --strict`
- Against a WebUntis test school: add an employee in humaniq, approve a leave
  request, and read both in WebUntis.
- `composer check:strict` and `npm run lint` once before push.
