---
status: needs-api-investigation
---

# GemeenteOplossingen (GO) RIS importer

## Purpose

Integriq SHALL eventually provide a GemeenteOplossingen (GO) RIS import bundle
that writes meetings, agenda items, motions/decisions and votes into decidesk's
OpenRegister objects, mirroring the iBabs/Notubiz bundle — but only after the GO
RIS API has been investigated. This spec captures the investigation gate.

**Cross-references**: `decidesk-ris-import-bundle`, `configurations/decidesk-ris-import/`.

---

## ADDED Requirements

### Requirement: GO RIS API SHALL be investigated before any connector is built

A GO Source, Mapping or Synchronization SHALL NOT be added to the bundle until the
GO RIS API base URL, auth model, read endpoints, results envelope and payload field
names are documented against a real GO instance.

#### Scenario: No fabricated GO connector is committed

- **GIVEN** the GO RIS API has not yet been investigated
- **WHEN** the bundle `configurations/decidesk-ris-import/` is inspected
- **THEN** it MUST contain no GO Source/Mapping/Synchronization files, and the investigation MUST be tracked as open tasks
- @e2e exclude investigation/backlog spec, not a runnable flow — verified by absence of GO bundle files

### Requirement: GO importer SHALL target decidesk OR objects once built

When built, the GO bundle SHALL follow the iBabs/Notubiz pattern: a Source with
empty credential placeholders, mappings to decidesk's Meeting/AgendaItem/Decision
(`decisionType: motion`)/Vote shapes, and synchronizations with
`targetType: register/schema` pointing at the decidesk register.

#### Scenario: GO bundle mirrors the iBabs/Notubiz target shape

- **GIVEN** the GO RIS API has been investigated and documented
- **WHEN** the GO bundle is built
- **THEN** its synchronizations MUST use `targetType: register/schema` with a `decidesk/<schema>` target and its mappings MUST produce decidesk Meeting/AgendaItem/Decision/Vote shapes
- @e2e exclude deferred build, gated on API investigation — verified at build time by key-parity against the existing bundle
