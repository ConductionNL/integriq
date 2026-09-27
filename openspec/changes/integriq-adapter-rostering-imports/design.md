# Design: integriq-adapter-rostering-imports

## Architecture Overview
Two independent halves sharing one change because they close the same M3-integrations rows (`I11`, `I25`) from the same wave.

```
Rostering:
  RosterImportSourceAdapter (lib/Sources/Roster/)
    -> RosterImportClient (abstract, lib/Adapters/Roster/)
         -> RosterImportClientMock (default, deterministic)

Migration presets:
  MigrationSourcesController::presets()  (new method, existing controller)
    -> MigrationMappingPresetRegistry (lib/Migration/)
         -> ColumnMapping::fromArray() (existing, unchanged)
```

## API Design

### `GET /api/migration-sources/column-mapping/presets`
Read-only. Lists the seeded named-incumbent presets so an operator can select one as a starting `ColumnMapping` instead of hand-authoring one from `POST /api/migration-sources/column-mapping/validate`.

**Request:** none (query-less GET).

**Response:**
```json
{
  "results": [
    {
      "id": "parnassys-export",
      "sourceSystem": "ParnasSys",
      "description": "Preset column mapping for a ParnasSys pupil export (representative, not captured from a live export).",
      "mapping": {
        "name": "parnassys-export",
        "kind": "pupil",
        "columns": {"Leerlingnummer": "externalId", "Achternaam": "lastName", "Voorletters": "initials", "Geboortedatum": "dateOfBirth", "Groep": "groupLabel"},
        "identifierColumn": "Leerlingnummer",
        "version": 1
      }
    }
  ]
}
```

No new endpoint for the rostering half — it is invoked through integriq's existing Source-execution path, the same as `integriq-adapter-lvs-imports`.

## Database Changes
None.

## Nextcloud Integration
- Controllers: `MigrationSourcesController::presets()` (new method on the existing controller).
- Services: `OCA\Integriq\Adapters\Roster\RosterImportClient` (abstract), `RosterImportClientMock`; `OCA\Integriq\Migration\MigrationMappingPresetRegistry`.
- Source facade: `OCA\Integriq\Sources\Roster\RosterImportSourceAdapter` (same constructor shape as `UwlrResultImportSourceAdapter`).
- Mappers/Entities: none new — `MigrationMappingPresetRegistry` returns `ColumnMapping` value objects the engine already understands.
- Events/Hooks: none.

## Security Considerations
Rostering: no pupil-identifying data — a timetable entry (subject, time, room, teacher/group reference) is not sensitive personal data the way a toets result or BSN is, so no special log redaction is required beyond the existing `isActive()`/`flavour()` summary pattern.
Migration presets: `presets()` is read-only, returns no OpenRegister object, and reads no per-caller state, so it follows the `validateMapping()` precedent — `#[NoAdminRequired]` + `#[NoCSRFRequired]` with a `@no-admin-idor-exempt` note (pure computation over static seed data, no object to scope to a caller).

## File Structure
```
lib/
  Adapters/
    Roster/
      RosterImportClient.php        (abstract)
      RosterImportClientMock.php
  Sources/
    Roster/
      RosterImportSourceAdapter.php
  Migration/
    MigrationMappingPresetRegistry.php
  Controller/
    MigrationSourcesController.php   (+ presets() method, existing file)
  sources.seed.json                  (+ 4 rostering rows, existing file)
  migration-mapping-presets.seed.json (new)
tests/
  Unit/
    Adapters/Roster/RosterImportClientMockTest.php
    Sources/Roster/RosterImportSourceAdapterTest.php
    Migration/MigrationMappingPresetRegistryTest.php
    Controller/MigrationSourcesControllerPresetsTest.php
  fixtures/
    roster/fixture-roster-batch.json
appinfo/
  routes.php  (+ 1 route)
```

## Trade-offs
One shared `RosterImportClient` for four scheduling systems versus four independent clients: same reasoning as `integriq-adapter-lvs-imports` — behaviourally identical in mock mode, avoiding near-duplicate dormant code. A `MigrationMappingPresetRegistry` alongside the existing `MigrationSourceRegistry` (rather than folding presets into that class) keeps "which source reads" (`MigrationSourceRegistry`) and "how a named incumbent's columns map" (`MigrationMappingPresetRegistry`) as two separate, independently-testable concerns — a preset is not a source, it is configuration for the `file` source.
