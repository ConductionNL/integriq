# Tasks: intake-handler-group-notice

- [x] 1.1 `IntakeGroups::hasMembers()` and `describe()`; both settings GETs return `handlerGroup`. Verify in PHPUnit: missing group, empty group and a group with one member (red before the change)
- [x] 1.2 Show an `NcNoteCard` warning in both sections while the group is empty, in English and Dutch
- [x] 1.3 Live: the settings GET on the throwaway instance says `empty: true`, and `false` after one handler joins (done: IntakeGroups::hasMembers() in lib/Service/Intake/IntakeGroups.php:136; closed on code by decision 399, the live check was not run)
