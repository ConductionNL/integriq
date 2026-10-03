# migration-mapping-presets Specification

## Purpose
Close the "no real cross-vendor migration path" gap (`I25`, M3-integrations.md) for the four named PO/VO incumbents — ParnasSys, ESIS, Magister, Somtoday — by seeding a named `ColumnMapping` preset per vendor for the existing whole-instance migration engine (`migration-source-adapters`), rather than building a second reading engine. `FileMigrationSource` already reads any correctly-mapped delivered file; this capability supplies the four starting mappings.

## Requirements

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
