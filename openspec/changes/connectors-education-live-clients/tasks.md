# Tasks: connectors-education-live-clients

Kind: code. Size M. Rows `integriq:con-timetable`, `integriq:con-lvs-results`, `integriq:nl-swv-handoff`.

### Task 1: Live Zermelo rostering client
- **spec_ref**: `openspec/changes/connectors-education-live-clients/specs/rostering-planninq-target/spec.md#requirement-a-live-rostering-client-reads-lessons-from-the-schools-scheduling-package-req-007`
- **files**: `lib/Adapters/Roster/RosterImportClientZermelo.php`, `lib/Adapters/AdaptersBindings.php` (bind by flag)
- [x] Implement
- [x] Test (PHPUnit against a recorded Zermelo answer)

### Task 2: Live UWLR result client
- **spec_ref**: `openspec/changes/connectors-education-live-clients/specs/lvs-result-import/spec.md#requirement-a-live-uwlr-client-reads-results-from-the-pupil-tracking-system-req-004`
- **files**: `lib/Adapters/Lvs/UwlrResultImportClientHttp.php`, `lib/Adapters/AdaptersBindings.php`
- [x] Implement
- [x] Test (PHPUnit against a recorded UWLR batch)

### Task 3: Live SWV hand-off client
- **spec_ref**: `openspec/changes/connectors-education-live-clients/specs/swv-handoff/spec.md#requirement-a-live-client-hands-a-support-request-to-the-swv-system-req-005`
- **files**: `lib/Adapters/Swv/SwvHandoffClientHttp.php`, `lib/Adapters/AdaptersBindings.php`
- [x] Implement
- [x] Test (PHPUnit against a recorded acknowledgement)

## Verification
- [ ] `openspec validate connectors-education-live-clients --type change --strict` passes
