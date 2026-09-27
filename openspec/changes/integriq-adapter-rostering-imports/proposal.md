---
kind: code
---

# Proposal: integriq-adapter-rostering-imports

## Summary
Two related, currently-missing wire capabilities for VO/MBO/HE cohorts: (1) a dormant rostering-import adapter for the four scheduling systems the corpus found — Zermelo, Untis (via its OneRoster API), Xedule and TimeEdit — feeding learniq's rostering-import job type; and (2) four named-incumbent column-mapping presets (ParnasSys, ESIS, Magister, Somtoday) for the whole-instance migration engine integriq already ships (`openspec/changes/migration-source-adapters`), so an operator migrating historical pupil data from one of these four LAS does not hand-author a column mapping from scratch. Per D3 (decisions.md) and change-plan.md row `integriq-adapter-rostering-imports`, learniq declares the job type/contract; integriq owns the adapter.

## Motivation
Row `I11` (M3-integrations.md) found a live timetable-import koppeling from Zermelo, Untis or Xedule documented on the VO/MBO/HE side of every incumbent surveyed (vo-las, mbo-he-sis), while "today learniq has no timetable-import adapter at all, only an import job type" (M3-integrations.md (b)). Row `I25` (migration import) found that "none of the eleven states a real cross-vendor migration path; this is a genuine fleet-wide gap" — but integriq already ships a generic, incumbent-agnostic migration-reading engine (`MigrationSourceAdapterInterface`, `ColumnMapping`, `FileMigrationSource`) from the `migration-source-adapters` change; per ADR-011 the gap to close here is a named-incumbent *preset* mapping, not a second reading engine.

## Affected Projects
- [x] Project: `integriq` — dormant rostering-import client/adapter (Zermelo, Untis/OneRoster, Xedule, TimeEdit) and four named-incumbent `ColumnMapping` presets for the existing migration engine.

## Scope

### In Scope
- An abstract `RosterImportClient` (dormant-adapter shape, mirroring `UwlrResultImportClient` from `integriq-adapter-lvs-imports`) with a deterministic `RosterImportClientMock` default.
- A `RosterImportSourceAdapter` mapping a fetched roster batch (lesson/timetable entries: subject, start/end time, room, teacher reference, group reference) onto learniq's rostering-import job payload field names.
- Four dormant Source rows sharing that one adapter class: `roster-zermelo`, `roster-untis-oneroster`, `roster-xedule`, `roster-timeedit`, each carrying its own `subCategory`/`type`/`documentation` reflecting the real wire shape (Zermelo: REST/JSON token auth per `docs.zportal.nl`; Untis: OneRoster REST per `developer.untis.com`/WebUntis release notes; Xedule: REST API plus the OAuth2 Xedule Connect layer per the SURF DPIA; TimeEdit: REST per `developer.timeedit.com`).
- A `MigrationMappingPresetRegistry` loading four seeded `ColumnMapping` presets (`parnassys-export`, `esis-export`, `magister-export`, `somtoday-export`) for pupil migration records, plus a read-only `GET /api/migration-sources/column-mapping/presets` endpoint on the existing `MigrationSourcesController` so an operator can select a preset instead of hand-authoring one.
- Contract tests against representative fixtures for both halves.

### Out of Scope
- A live HTTP binding to any of the four rostering systems — each needs its own institution-level OAuth/API-key onboarding (Zermelo: `partners@zermelo.nl`; Xedule Connect: OAuth 2.0 client credentials in production since January 2025) that this change cannot complete.
- A new migration-reading engine for the four LAS exports — the existing `FileMigrationSource` + `ColumnMapping` engine already reads any correctly-mapped delivered file; this change only seeds the four presets.
- The PO PSA/SIS-side `Progress`/`Eduarte`/`Osiris` product's own OOAPI-based catalogue coupling (`I19`) — a different, opencatalogi-owned row.

## Approach
Rostering: one shared client/mapping family across all four systems (same reasoning as `integriq-adapter-lvs-imports` — in mock mode the four are behaviourally identical; a live binding can subclass per system later without touching the other three Source rows).

Migration presets: extend, don't duplicate. `FileMigrationSource::read()` already turns a delivered file into `MigrationRecord`s through any `ColumnMapping` an operator supplies via `POST /api/migration-sources/column-mapping/validate`. This change adds a `MigrationMappingPresetRegistry` (same shape as the existing `MigrationSourceRegistry`) that ships four named presets an operator can fetch and use as a starting mapping — closing `I25`'s "no real cross-vendor migration path" finding without a second reading engine.

## New Dependencies
None.

## Impact
- New files under `lib/Adapters/Roster/`, `lib/Sources/Roster/`, `lib/Migration/MigrationMappingPreset*.php`, plus one new controller method + one new route on the existing `migrationSources` route group.
- One addition to `lib/sources.seed.json` (four rostering rows) and one new seed file `lib/migration-mapping-presets.seed.json` (four presets).

## Cross-Project Dependencies
Depends on learniq's rostering-import `DataExchangeJob` type/payload and `DataMappingProfile` preset contract (same wave per change-plan.md). The rostering mapping and the preset column names are the two seams to update if learniq's contract shape changes before archive.

## Risks

### Risk 1: Roster and export column shapes are inferred, not captured live
**Severity:** Medium — **Mitigation:** same mitigation as `integriq-adapter-lvs-imports` — mapping is isolated in one method/one seed file per concern, correctable from a real captured payload without touching the client, Source-row or registry shape.

### Risk 2: planninq vs integriq ownership of rostering imports is an open tension
**Severity:** Low — **Mitigation:** change-plan.md flags this explicitly (`M3-integrations.md` (b) recommended planninq own this specifically) but records that D3 as written assigns it to integriq's uniform pattern; this change follows D3. If Ruben resolves the tension toward planninq, this Source-row family (not the migration-preset half, which is unambiguously integriq's existing engine) is what would move.

## Rollback Strategy
Revert the merge commit. All new Source rows ship `isEnabled: false`; the preset registry is additive and read-only.

## Open Questions
Whether rostering-import ownership belongs to integriq (per D3) or planninq (per the standalone `M3-integrations.md` (b) recommendation) is flagged, not resolved, in change-plan.md — not a code blocker for this change, but worth Ruben's explicit call per that file's own note.
