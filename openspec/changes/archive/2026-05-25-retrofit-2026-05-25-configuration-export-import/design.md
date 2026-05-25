# Design — configuration-export-import (retrofit)

> **Retrofit change.** Tasks describe retroactive annotation of existing code,
> not new implementation work. No behaviour is changed by this change.

## Context

The export/import subsystem already exists and ships in production
(`lib/Service/ConfigurationService.php` + six `ConfigurationHandlers`). This
change reverse-engineers a spec from the observed behaviour so the capability is
tracked under the ADR-008 annotation convention and the Specter retrofit cohort.
The governing decisions are already documented in ADR-015 (slug translation) and
ADR-007 (plaintext source credentials).

## Architecture (as observed)

```
exportConfiguration(cfgId) / exportRegister(regId)
  → resetMappings()                         build idToSlug maps from live env (REQ-004)
  → findByConfiguration / getXByTarget      gather membership / dependency closure
  → buildRegisterAndSchemaMappings()        resolve register/schema halves
  → handler.export(entity, mappings)        id → slug per type (REQ-001/002/004)
       SourceHandler.export strips secrets  (REQ-005)
  → organizeEntitiesByComponent()           group under components.<type>.<component>

importConfiguration(oas)
  → assert components present               else InvalidArgumentException (REQ-003)
  → resetMappings()                         build slugToId maps from TARGET env (REQ-004)
  → for type in [source, mapping, rule,     dependency order (REQ-003)
       endpoint, synchronization, job]:
       handler.import(data, mappings)        slug → id, then saveObject upsert by slug
```

## Decisions captured (not made)

- **Slug is the portable identity** (ADR-015) — UUIDs are in-environment only.
- **Slug-not-found → verbatim fallback** — import never hard-fails on a missing
  dependency; it produces a dangling reference. Recorded in REQ-003/REQ-004
  Notes; a future pre-import validation pass is proposed in ADR-015, not added
  here.
- **Credential redaction lives on export, in SourceHandler only** — the only
  barrier given plaintext storage (ADR-007). REQ-005 Notes flag the
  substring-based header match as a partial-redaction gap.

## Out of scope (recorded for future tightening, not changed)

- Server-side filter pushdown for membership queries (O(all) scan today).
- Allowlist-based credential redaction replacing substring matching.
- Per-entity schema validation of the imported OAS payload.
- Re-population of the slug map with entities created earlier in the same import
  run.
