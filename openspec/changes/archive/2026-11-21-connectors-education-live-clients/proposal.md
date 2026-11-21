---
kind: code
---

# Proposal: connectors-education-live-clients

## Summary

Three education connectors run end to end on a mock client only. The adapters, mappers and dormant sources exist, but no client talks to a real supplier:

- `con-timetable`: `RosterImportClient` has only `RosterImportClientMock`, bound whatever the flag (design D9 of the rostering changes).
- `con-lvs-results`: `UwlrResultImportClient` has only `UwlrResultImportClientMock`.
- `nl-swv-handoff`: `SwvHandoffClient` has only `SwvHandoffClientMock`.

This change adds a live client for each, behind the existing flag, the way `SloCurriculumClientHttp` does for SLO. Onboarding a school stays configuration: a source row with the supplier's address and a brokered credential.

## Rows

| row | state |
|---|---|
| `con-timetable` | specified |
| `con-lvs-results` | specified |
| `nl-swv-handoff` | specified |
