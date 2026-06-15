---
status: proposed
---

# Decidesk-targeted RIS import bundle

## Purpose

OpenConnector SHALL provide a declarative configuration bundle that imports
raadsinformatie (meetings, agenda items, motions/decisions, votes) from the iBabs
and Notubiz RIS into decidesk's ORI-aligned OpenRegister objects, with no
credentials committed and runtime ingestion gated on per-instance configuration.

**Cross-references**: `configurations/decidesk-ris-import/`, decidesk's
`Meeting`/`AgendaItem`/`Decision`/`Vote` schemas, archived
`openspec/changes/archive/2026-06-14-ibabs-notubiz-connector/`.

---

## ADDED Requirements

### Requirement: RIS sources ship with empty credential placeholders

The bundle SHALL provide an iBabs Source and a Notubiz Source under
`configurations/decidesk-ris-import/sources/`, each with empty credential
placeholders and `isEnabled: false`, so no secret material is committed (ADR-003
zero-knowledge).

#### Scenario: iBabs source has empty apikey and organisation placeholders

- **GIVEN** the bundle file `sources/ibabs-ris-v1.json`
- **WHEN** it is parsed
- **THEN** it MUST declare `type: "api"`, `auth: "apikey"`, an empty `configuration["headers.Authorization"]` and an empty `configuration.organisatieId`, and `isEnabled` MUST be `false`
- @e2e exclude declarative config bundle, not a UI flow — verified by JSON parse + key-parity check against the existing `configurations/` bundle shape

#### Scenario: Notubiz source targets the public read API with optional auth

- **GIVEN** the bundle file `sources/notubiz-ris-v1.json`
- **WHEN** it is parsed
- **THEN** `location` MUST be `https://api.notubiz.nl`, it MUST carry an empty `configuration.organisation_id` plus an empty optional `oauth.*` block, and `isEnabled` MUST be `false`
- @e2e exclude declarative config bundle, not a UI flow — verified by JSON parse + key-parity check against the existing `configurations/` bundle shape

### Requirement: Mappings transform RIS payloads into decidesk OR object shapes

The bundle SHALL provide mappings that transform iBabs and Notubiz RIS payloads
into decidesk's `Meeting`, `AgendaItem`, `Decision` and `Vote` object shapes, with
RIS motions/besluiten mapped to `Decision` with `decisionType: motion`.

#### Scenario: A motion/besluit maps to a Decision with decisionType motion

- **GIVEN** the mapping `Mapping-iBabs Besluit to Decision-v0.0.1.json` (and its Notubiz equivalent)
- **WHEN** it is parsed
- **THEN** the `mapping` block MUST set `decisionType` to `motion` and MUST map the RIS status (aangenomen/verworpen) onto the decidesk `outcome` enum (adopted/rejected)
- @e2e exclude declarative config bundle, not a UI flow — verified by JSON parse + manual mapping review against the decidesk Decision schema

#### Scenario: Each RIS object type has both an iBabs and a Notubiz mapping

- **GIVEN** the `mappings/` folder
- **WHEN** its files are listed
- **THEN** there MUST be an iBabs and a Notubiz mapping for each of Meeting, AgendaItem, Decision and Vote (8 mappings total), each matching the existing `configurations/` mapping key shape
- @e2e exclude declarative config bundle, not a UI flow — verified by JSON parse + key-parity check against `xxllnc-zoekendpoint-woo`

### Requirement: Synchronizations write into decidesk's OpenRegister objects

The bundle SHALL provide synchronizations with `targetType: register/schema`
pointing at decidesk's register so a sync writes straight into decidesk's OR
objects, one synchronization per (source × RIS object type).

#### Scenario: A synchronization targets the decidesk register and schema

- **GIVEN** any file under `synchronizations/`
- **WHEN** it is parsed
- **THEN** `targetType` MUST be `register/schema` and `targetId` MUST reference the decidesk register and the matching schema (`decidesk/<schema>`), with the correct `sourceTargetMapping`
- @e2e exclude declarative config bundle, not a UI flow — verified by JSON parse + key-parity check against the existing `configurations/` synchronization shape

### Requirement: Runtime ingestion is gated on per-instance configuration

The bundle SHALL document that runtime ingestion requires per-municipality
credentials, the instance's real register/schema ids, and the per-run path
variables, and that this is the only step not exercisable in CI.

#### Scenario: The bundle README states the per-instance wiring requirements

- **GIVEN** `configurations/decidesk-ris-import/README.md`
- **WHEN** it is read
- **THEN** it MUST list the credential fields to fill, the `targetId` register/schema substitution, and the per-run path variables, and MUST state that runtime ingestion cannot be exercised without those
- @e2e exclude documentation requirement, not a UI flow — verified by README review
