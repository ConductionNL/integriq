# configuration-export-import Specification (delta)

---
status: proposed
---

## Purpose

Give REQ-002 (register connector export) a routed HTTP trigger. The
`exportRegister()` service method was fully implemented but reachable only from
PHPUnit — no controller or route invoked it (issue #165, gate-52
orphaned-write-capability).

## MODIFIED Requirements

### Requirement: REQ-002 — Export every entity transitively reachable from a register

The system SHALL export the dependency closure of a single register: all
endpoints and synchronizations whose `targetId`/`sourceId` reference that
register (optionally filtered by source-side, target-side, or both), plus the
mappings, rules, sources and jobs those endpoints and synchronizations
transitively reference. The system SHALL resolve register/schema id→slug maps
before serialising any entity, and SHALL follow mapping-to-mapping references to
a fixed point so that nested mappings referenced inside other mappings are
included.

The register export SHALL be reachable over HTTP: `GET /api/registers/{id}/export`
(route `configuration#exportRegister`) MUST resolve to
`ConfigurationController::exportRegister()`, which authorises the caller with the
`configuration.export` action (per ADR-023, enforced in the method body) and
returns the serialised bundle as a downloadable JSON attachment. Unauthenticated
callers receive 401; callers without the action receive 403.

Notes: `getEndpointsByTarget()` / `getSynchronizationsByTarget()` match on
`str_starts_with($targetId, $registerId.'/')` or exact equality, so they match
register-level and register/schema-level references but not bare schema
references. `findJobsByArgumentIds()` decodes a string `arguments` field as JSON
before matching — a malformed JSON arguments value silently yields `[]` (no
match), so a job with corrupt arguments is quietly dropped from the export.

#### Scenario: endpoint dependency closure is exported

- GIVEN a register `reg-A` targeted by one endpoint that references an input mapping and two rules
- WHEN `exportRegister('reg-A')` is called
- THEN the export's `components` contains that endpoint, its input mapping, and both rules, each keyed by slug

#### Scenario: register export is reachable over HTTP as a download

- GIVEN an authenticated caller holding the `configuration.export` action
- WHEN they `GET /api/registers/reg-A/export`
- THEN `ConfigurationController::exportRegister()` calls
  `ConfigurationService::exportRegister('reg-A')`
- AND the response is the register bundle with a `Content-Disposition: attachment`
  header naming `register-reg-A.json`

#### Scenario: register export denies an unauthorised caller

- GIVEN a caller without the `configuration.export` action
- WHEN they `GET /api/registers/reg-A/export`
- THEN the response is 403 and no export runs

#### Scenario: synchronizations can be excluded from the closure

- GIVEN `includeSynchronizations = false`
- WHEN `exportRegister()` is called
- THEN no synchronizations are walked or exported
- AND only endpoint-reachable dependencies appear

#### Scenario: nested mapping references are followed to a fixed point

- GIVEN a mapping `m1` that references another mapping `m2` in its config
- WHEN the register is exported
- THEN both `m1` and `m2` appear in `components.mappings` because the export loops on newly-discovered mapping ids until none remain

#### Scenario: jobs referencing exported ids are included

- GIVEN a job whose `arguments` reference an exported synchronization, endpoint, or source id
- WHEN the register is exported
- THEN that job is included
- AND jobs referencing none of the exported ids are excluded
