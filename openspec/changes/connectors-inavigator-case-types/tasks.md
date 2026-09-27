# Tasks: connectors-inavigator-case-types

Kind: code. Size M. Rows `integriq:con-inavigator` and
`integriq:nl-zgw-resync`. Depends on `zgw-connectors-for-dossiq` for the
Catalogi set's synchronization and mapping.

### Task 1: Pin the i-Navigator interface
- **spec_ref**: openspec/changes/connectors-inavigator-case-types/specs/case-type-import/spec.md#requirement-the-i-navigator-interface-is-pinned-against-a-real-export-before-the-import-is-built-req-inav-001
- **files**: `openspec/changes/connectors-inavigator-case-types/design.md` (D1 outcome), `tests/fixtures/inavigator/` (a real, anonymised export)
- **acceptance_criteria**:
  - GIVEN a real i-Navigator export or the vendor's interface document WHEN the task closes THEN D1 names the interface and the fixture is committed
  - GIVEN no checkable interface WHEN the task closes THEN D1 says so and Task 2 is closed as blocked
- [ ] Implement
- [ ] Test (review of the recorded outcome)

### Task 2: The i-Navigator template, synchronization and mapping
- **spec_ref**: openspec/changes/connectors-inavigator-case-types/specs/case-type-import/spec.md#requirement-an-administrator-imports-i-navigator-case-types-with-all-their-attributes-req-inav-002
- **files**: `lib/Settings/configurations/inavigator-case-types.json`, `lib/Settings/register.d/inavigator-source.json`, the mapping file named by the set
- **acceptance_criteria**:
  - GIVEN the fixture WHEN the synchronization runs and is approved THEN each case type is written with every attribute in `eigenschappen`
  - GIVEN a fixture with one extra attribute WHEN it runs again THEN the attribute arrives with no mapping edit
- [ ] Implement
- [ ] Test (PHPUnit on the mapping against the fixture; `tests/e2e/inavigator-case-types.spec.ts`)

### Task 3: The change set at the gate
- **spec_ref**: openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
- **files**: `lib/Service/SynchronizationService.php`, `lib/Service/Synchronization/ChangeSetBuilder.php`, `lib/Service/ApprovalService.php` (`suspendForSynchronization()` takes the change set), `lib/Settings/register.d/hitl-approval-rule-action.json` (`fingerprint`, 1.1.0)
- **acceptance_criteria**:
  - GIVEN a gated run with one new, one changed and one removed object WHEN it pauses THEN the request's snapshot lists them with field diffs and a fingerprint
  - GIVEN an incomplete fetch WHEN the change set is built THEN it lists no removals
  - GIVEN any gated run WHEN the change set is built THEN no target object is written
- [ ] Implement
- [ ] Test (PHPUnit on `ChangeSetBuilder` and on the gate)

### Task 4: Accept or supersede
- **spec_ref**: openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-accepting-writes-the-previewed-change-set-or-asks-again-req-inav-004
- **files**: `lib/Service/SynchronizationService.php`, `lib/Service/ApprovalService.php` (`resume()`)
- **acceptance_criteria**:
  - GIVEN a matching fingerprint WHEN the request resumes THEN the write loop runs
  - GIVEN a different fingerprint WHEN the request resumes THEN nothing is written, the request is superseded and a new one exists
- [ ] Implement
- [ ] Test (PHPUnit on resume with a changed fixture)

### Task 5: The change set on the approval screen
- **spec_ref**: openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
- **files**: `src/views/Approvals/ApprovalDetail.vue`, `lib/Controller/ApprovalsController.php`, `lib/Settings/integriq_mock_register.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a pending synchronization request WHEN an approver opens it THEN created, changed and removed tabs show the objects and field diffs
  - GIVEN demo data WHEN the approvals page opens THEN one synchronization request shows a change set
- [ ] Implement
- [ ] Test (`tests/e2e/inavigator-case-types.spec.ts`)

## Verification

- `openspec validate connectors-inavigator-case-types --type change --strict`
- Import the anonymised fixture into a local dossiq case type schema, change
  one case type in the fixture, and accept the second run from the approval
  screen.
- `composer check:strict` and `npm run lint` once before push.
