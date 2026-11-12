# Proposal: intake-handler-group-notice

kind: code. Ruben approved this on 2026-10-04 as a small follow-up to `bsn-intake-records-access-rules`.

## Why

`dso-behandelaars` and `openformulieren-behandelaars` start empty on purpose. Nobody gets BSN access by default. But an administrator who sets up a connection sees no sign of that. The intake stores records, and nobody but an administrator can read them.

## What changes

- The DSO and the Open Formulieren connection settings show a warning while their handler group has no members.
- The warning names the group and tells the administrator where to add handlers.
- The settings endpoints return `handlerGroup: {id, empty}`. A group that does not exist yet counts as empty.

## Capabilities

### New Capabilities

- `intake-access`: REQ-IAC-001.

## Impact

- `IntakeGroups` gains `hasMembers()` and `describe()`.
- `DsoPkiSettingsController::getConfig()` and `OpenFormulierenSettingsController::getConfig()` return `handlerGroup`.
- `DsoPkiSettings.vue` and `OpenFormulierenConnectionSettings.vue` show an `NcNoteCard`.
- Two new strings, in English and Dutch.
