# migration-mapping-presets Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- integriq-adapter-rostering-imports

## Purpose
Close the "no real cross-vendor migration path" gap (`I25`, M3-integrations.md) for the four named PO/VO incumbents — ParnasSys, ESIS, Magister, Somtoday — by seeding a named `ColumnMapping` preset per vendor for the existing whole-instance migration engine (`migration-source-adapters`), rather than building a second reading engine. `FileMigrationSource` already reads any correctly-mapped delivered file; this capability supplies the four starting mappings.

## ADDED Requirements

### Requirement: A registry of named-incumbent column-mapping presets (REQ-001)
The system MUST provide a `MigrationMappingPresetRegistry` that loads presets from `lib/migration-mapping-presets.seed.json` and exposes each as `{id, sourceSystem, description, mapping: ColumnMapping}`. The seed file MUST ship exactly four presets: `parnassys-export`, `esis-export`, `magister-export`, `somtoday-export`, each producing `MigrationRecord`s of kind `pupil`.

#### Scenario: Registry lists all four seeded presets
- GIVEN `lib/migration-mapping-presets.seed.json` as seeded by this change
- WHEN `MigrationMappingPresetRegistry::describeAll()` is called
- THEN it returns exactly four presets with ids `parnassys-export`, `esis-export`, `magister-export`, `somtoday-export`

#### Scenario: A preset resolves to a usable ColumnMapping
- GIVEN the `parnassys-export` preset
- WHEN `MigrationMappingPresetRegistry::get('parnassys-export')` is called
- THEN it returns a `ColumnMapping` whose `getKind()` is `pupil` and whose `getIdentifierColumn()` is non-empty

### Requirement: An operator can list presets over the existing migration-sources HTTP surface (REQ-002)
The system MUST expose `GET /api/migration-sources/column-mapping/presets` on the existing `MigrationSourcesController`, read-only, `#[NoAdminRequired]` + `#[NoCSRFRequired]` (matching `validateMapping()`'s posture — pure computation over static seed data, no per-object authorization to scope).

#### Scenario: An authenticated user lists the available presets
- GIVEN an authenticated non-admin user
- WHEN they call `GET /api/migration-sources/column-mapping/presets`
- THEN the response lists the four seeded presets with their `mapping` field shaped as `ColumnMapping::toArray()`

## Non-Functional Requirements

- **Performance:** presets are static seed data, loaded once per request; no I/O beyond the JSON read.
- **Accessibility:** N/A — no user interface in this change (a future admin UI would consume this endpoint).
- **Internationalization:** N/A — no new user-facing strings; `sourceSystem`/`description` are operator-facing API metadata, not end-user UI copy.

## Acceptance Criteria

- [ ] `MigrationMappingPresetRegistry::describeAll()` returns the four seeded presets.
- [ ] `MigrationMappingPresetRegistry::get()` returns a valid `ColumnMapping` for each preset id.
- [ ] `GET /api/migration-sources/column-mapping/presets` returns the four presets for an authenticated non-admin caller.
- [ ] Contract tests pass against the seeded fixture.

## Notes
The four presets' column names are representative, built from the general shape a PO/VO pupil export carries (student number, name parts, date of birth, group/class label) — no vendor in market-intelligence round 1 published a raw export column-header sample for ParnasSys, ESIS, Magister or Somtoday specifically (the round found koppeling and product pages, not export file specifications). Correcting a preset's column names against a real captured export is a follow-up once a design-partner school supplies one; the seam is the one JSON seed file, not the engine.
