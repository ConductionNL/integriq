# rostering-import Specification

## Purpose
Provide the integriq-side wire adapter for pulling timetable data from the four VO/MBO/HE rostering systems the market-intelligence corpus found — Zermelo, Untis (OneRoster), Xedule and TimeEdit — and mapping it onto learniq's rostering-import `DataExchangeJob` payload, per the abstract integration pattern (D3, decisions.md).

## Requirements

### Requirement: Dormant roster-import client with deterministic mock default (REQ-001)
The system MUST provide an abstract `RosterImportClient` with exactly one concrete subclass active by default, `RosterImportClientMock`, returning a deterministic, canned roster batch and never performing network I/O. Each client MUST expose `flavour()` returning `mock` (or, for a future live binding, `https`).

#### Scenario: Mock client returns a deterministic roster batch
- GIVEN a `RosterImportClientMock` instance
- WHEN `fetchLessons('roster-zermelo')` is called
- THEN it returns an array of lesson records with `subject`, `startsAt`, `endsAt`, `room`, `teacherReference` and `groupReference` keys
- AND `flavour()` returns `mock`

### Requirement: Source adapter maps a roster batch onto the rostering-import job payload (REQ-002)
The system MUST provide a `RosterImportSourceAdapter` that calls the configured `RosterImportClient`, maps each returned lesson onto the field names learniq's rostering-import job payload declares, and logs a summary (system id, record count, `isActive()`, `flavour()`).

#### Scenario: Source adapter produces a rostering-import-shaped payload
- GIVEN the `RosterImportSourceAdapter` is configured with the mock client
- WHEN `importLessons('roster-zermelo')` is called
- THEN the returned payload array uses the rostering-import job's field names, not the raw client field names

### Requirement: Four dormant Source rows, one per system, sharing one adapter class (REQ-003)
The system MUST seed four Source rows in `lib/sources.seed.json` — `roster-zermelo`, `roster-untis-oneroster`, `roster-xedule`, `roster-timeedit` — each `isEnabled: false`, each referencing `RosterImportSourceAdapter` as `adapterClass`, each gated behind `roster.import.feature_flag`, each carrying a `type` reflecting its real wire shape (`rest-token`, `oneroster`, `rest-oauth2`, `rest-token` respectively).

#### Scenario: All four rostering rows are seeded and dormant
- GIVEN `lib/sources.seed.json` after this change
- WHEN the sources list is parsed
- THEN it contains exactly four new rows with ids `roster-zermelo`, `roster-untis-oneroster`, `roster-xedule`, `roster-timeedit`
- AND each has `isEnabled: false`
