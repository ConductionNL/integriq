# Tasks: connectors-hr-staff-sync

Kind: code. Size M. Row `planninq:tt-hr-sync`.

### Task 1: The Untis target source and the staff synchronization
- **spec_ref**: openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
- **files**: `lib/Settings/configurations/staff-to-untis.json`, its source template (Untis, broker reference, mock mode), the `Employee` and `OrgAssignment` synchronizations and mappings, `tests/fixtures/untis/`
- **acceptance_criteria**:
  - GIVEN developer.untis.com WHEN the set is written THEN its description cites the teacher endpoints and auth it uses
  - GIVEN a new employee with an active assignment WHEN it is saved THEN one teacher create goes to the mock Untis
  - GIVEN an ended assignment WHEN it is saved THEN the teacher is made inactive and not deleted
- [ ] Implement
- [ ] Test (PHPUnit against the mock target; `tests/e2e/staff-to-untis.spec.ts`)

### Task 2: Approved leave as teacher absence
- **spec_ref**: openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-approved-leave-becomes-an-untis-teacher-absence-req-hrs-002
- **files**: the `LeaveRequest` synchronization and mapping in the set, `triggerOnlyOnEvents` and its condition on `status`
- **acceptance_criteria**:
  - GIVEN a request approved WHEN it is saved THEN one absence create goes to Untis with dates and a neutral reason
  - GIVEN an approved request later rejected WHEN it is saved THEN the absence is deleted in Untis
  - GIVEN a submitted request WHEN it is saved THEN nothing is sent
- [ ] Implement
- [ ] Test (PHPUnit on the trigger condition and mapping)

### Task 3: Allow-list mappings and the install check
- **spec_ref**: openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-allow-listed-fields-leave-the-hr-register-req-hrs-003
- **files**: the set's mappings, the set install guard (a check that refuses pass-through mappings in a set marked `personalData: true`)
- **acceptance_criteria**:
  - GIVEN an employee fixture with BSN and IBAN WHEN it is mapped THEN neither is in the output
  - GIVEN a mapping with `passThrough: true` in the set WHEN the set is installed THEN the install is refused naming the mapping
- [ ] Implement
- [ ] Test (PHPUnit on the mappings and the guard)

### Task 4: Nightly reconciliation
- **spec_ref**: openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
- **files**: `lib/BackgroundJob/StaffReconciliationJob.php`, `lib/AppInfo/Application.php` or `appinfo/info.xml` (job registration)
- **acceptance_criteria**:
  - GIVEN an employee missing in Untis WHEN the job runs THEN the teacher is created
  - GIVEN an incomplete Untis read WHEN the job runs THEN no teacher is made inactive
  - GIVEN an Untis teacher with no humaniq match WHEN the job runs THEN it is listed in the log and not duplicated
- [ ] Implement
- [ ] Test (PHPUnit on the job with a paged mock that fails halfway)

### Task 5: The per-system README
- **spec_ref**: openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-new-or-departing-employee-reaches-untis-without-a-manual-step-req-hrs-001
- **files**: `lib/Settings/configurations/staff-to-untis.README.md`
- **acceptance_criteria**:
  - GIVEN Zermelo, Xedule and TimeEdit WHEN the README is read THEN each has its documented HR import, its source URL, and what a humaniq school lacks
- [ ] Implement
- [ ] Test (review)

## Verification

- `openspec validate connectors-hr-staff-sync --type change --strict`
- Against a WebUntis test school: add an employee in humaniq, approve a leave
  request, and read both in WebUntis.
- `composer check:strict` and `npm run lint` once before push.
