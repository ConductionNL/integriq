# openconnector-register-schema Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- openconnector-register-schema-declaration (this change)

## Purpose

Declare the OpenConnector data model as a single OpenRegister register descriptor.
Replaces the implicit data model encoded across 15 hand-maintained
`oc_openconnector_*` tables and their mappers with one authoritative
`lib/Settings/openconnector_register.json` document, mirroring the pipelinq and
procest patterns. This declaration is the platform-neutral source of truth from
which the companion code chain (`openconnector-register-storage`) provisions
storage and migrates existing data.

Aligns with: ADR-001 (data lives in OpenRegister registers), ADR-031
(declarative-over-imperative), ADR-032 (config/code chain split). Cross-references
the `audit-trail-immutable`, `retention-management`, `nextcloud-entity-relations`,
and `archival-destruction-workflow` specs in openregister.

## ADDED Requirements

### Requirement: Register descriptor file MUST exist at the canonical path

**ID**: REQ-A-001

The system MUST ship a register descriptor file at
`lib/Settings/openconnector_register.json` that conforms to OpenAPI 3.0 with the
`x-openregister` vendor extension. The file MUST declare exactly one register
with slug `openconnector` and a non-empty `schemas` array referencing every
schema defined in `components.schemas`.

#### Scenario: Descriptor file present at canonical path
- GIVEN a fresh checkout of the openconnector repo
- WHEN inspecting `openconnector/lib/Settings/`
- THEN `openconnector_register.json` MUST exist
- AND it MUST parse as valid JSON
- AND its top-level keys MUST be exactly `openapi`, `info`, `x-openregister`,
  `components`

#### Scenario: Register slug is openconnector
- GIVEN the descriptor file is loaded
- WHEN inspecting `components.registers`
- THEN exactly one register entry MUST exist with slug `openconnector`
- AND its `schemas` array MUST list all 15 schema slugs

### Requirement: All 15 schemas MUST be declared

**ID**: REQ-A-002

The system MUST declare 15 schemas in `components.schemas`. The mutable config
schemas are: `source`, `consumer`, `endpoint`, `event`, `event_message`,
`event_subscription`, `job`, `mapping`, `rule`, `synchronization`,
`synchronization_contract`. The append-only log schemas are: `call_log`,
`job_log`, `synchronization_log`, `synchronization_contract_log`.

Each schema MUST declare `slug`, `title`, `version`, and `properties` at
minimum. Each schema's `properties` MUST cover every protected field declared on
the matching `lib/Db/<EntityName>.php` entity (excluding internally-derived
fields like `id` which OR manages automatically).

#### Scenario: All 15 schemas present
- GIVEN the descriptor file is parsed
- WHEN inspecting `components.schemas`
- THEN exactly 15 schema entries MUST exist
- AND their slugs MUST be the union of the 11 mutable config and 4 log slugs

#### Scenario: Schema field coverage matches entity definition
- GIVEN the `Source` entity defined in `lib/Db/Source.php` with 39 protected fields
- WHEN comparing against the `source` schema's `properties`
- THEN every entity protected field MUST appear as a property on the schema
- AND the property `type` MUST map per the conversion: PHP `string` → JSON
  `string`, PHP `integer` → JSON `integer`, PHP `boolean` → JSON `boolean`,
  PHP `array` (json column) → JSON `array` or `object`, PHP `DateTime` → JSON
  `string` with `format: "date-time"`

### Requirement: Log schemas MUST be declared append-only and immutable

**ID**: REQ-A-003

Every log schema in the descriptor MUST set `appendOnly: true` AND `immutable: true` at the schema level.

The four log schemas (`call_log`, `job_log`, `synchronization_log`,
`synchronization_contract_log`) MUST declare both `appendOnly: true` and
`immutable: true` at the schema level. The 11 mutable config schemas MUST set
both flags to `false` (or omit them — OR defaults to false).

#### Scenario: Log schemas marked append-only and immutable
- GIVEN the descriptor declares 4 log schemas
- WHEN inspecting each log schema's top-level flags
- THEN `appendOnly` MUST be `true`
- AND `immutable` MUST be `true`

#### Scenario: Config schemas remain mutable
- GIVEN the descriptor declares 11 mutable config schemas
- WHEN inspecting each config schema's top-level flags
- THEN `appendOnly` MUST be `false` or absent
- AND `immutable` MUST be `false` or absent

### Requirement: Log schemas MUST carry retention annotation

**ID**: REQ-A-004

Each of the 4 log schemas MUST carry an `x-openregister-archival` annotation
encoding the retention window. Success-path log retention MUST default to
`PT1H` (one hour, matching `JobService::DEFAULT_SUCCESS_LOG_RETENTION = 3600000`
ms / 1000 = 3600 s). Error-path log retention MUST default to `P30D` (30 days,
matching `DEFAULT_ERROR_LOG_RETENTION = 2592000000` ms). The annotation MUST be
shaped so that OR's archival workflow can drive both windows; the exact
attribute names follow OR's `archival-destruction-workflow` spec.

#### Scenario: call_log carries split retention
- GIVEN the `call_log` schema in the descriptor
- WHEN inspecting `x-openregister-archival`
- THEN it MUST encode a retention rule with `PT1H` for success-class entries
- AND `P30D` for error-class entries
- AND the discriminator field (e.g. `statusCode >= 400` or a `level`-based rule)
  MUST be expressed as part of the annotation

### Requirement: Integer foreign-key columns MUST be relation-annotated

**ID**: REQ-A-005

Each integer foreign-key column on an openconnector entity MUST be re-declared in
the schema as a UUID property paired with a `$ref` to the target schema. The
following 6 relations MUST exist:

| Source schema   | Property name | $ref target          | Cardinality | onDelete   |
|-----------------|---------------|----------------------|-------------|-----------|
| `call_log`      | `source`      | `source`             | many-to-one | SET NULL   |
| `call_log`      | `synchronization` | `synchronization` | many-to-one | SET NULL   |
| `event_message` | `event`       | `event`              | many-to-one | CASCADE    |
| `event_message` | `consumer`    | `consumer`           | many-to-one | SET NULL   |
| `event_message` | `subscription`| `event_subscription` | many-to-one | CASCADE    |
| `synchronization_contract_log` | `synchronization_contract` | `synchronization_contract` | many-to-one | CASCADE |

The legacy `*Id` column name (e.g. `sourceId`, `eventId`) MUST be retained as a
sibling property to support the transition window described in REQ-008. The
existing string-typed FKs (`synchronizationId`, `synchronizationContractId`,
`synchronizationLogId`, `jobId`) MUST also be re-declared with the equivalent
relation annotation on the target-schema-named field.

#### Scenario: call_log has both legacy and relation fields for source
- GIVEN the `call_log` schema in the descriptor
- WHEN inspecting `properties.source` and `properties.sourceId`
- THEN `source` MUST exist with `type: "string"`, `format: "uuid"`, `$ref: "source"`
- AND `sourceId` MUST exist with `type: "integer"` (legacy)
- AND both properties MUST have an explanatory `description` field

#### Scenario: event_message has cascade-delete on event subscription
- GIVEN the `event_message` schema in the descriptor
- WHEN inspecting `properties.subscription`
- THEN it MUST carry `$ref: "event_subscription"` and `onDelete: "CASCADE"`
- AND its `description` MUST state cascade behaviour explicitly

### Requirement: Synchronization sourceId/targetId MUST remain string-typed with overload documented

**ID**: REQ-A-006

The `synchronization.sourceId` and `synchronization.targetId` properties MUST be declared as `type: "string"` with no `$ref`, and their description MUST document the three valid value formats.

`synchronization.sourceId` and `synchronization.targetId` are overloaded —
they may carry an integer Source PK string-encoded, a `register/schema`
slug-pair, or a UUID. The schema MUST declare them as `type: "string"` with
NO `$ref`, and the `description` MUST enumerate the three valid value formats
and direct callers to `lib/Service/SynchronizationService.php` for resolution
logic.

#### Scenario: synchronization.sourceId is documented overload
- GIVEN the `synchronization` schema in the descriptor
- WHEN inspecting `properties.sourceId`
- THEN `type` MUST be `"string"`
- AND `$ref` MUST be absent
- AND `description` MUST mention "integer PK", "register/schema slug-pair", and "uuid"

### Requirement: Seed data file MUST exist for mutable schemas only

**ID**: REQ-A-007

The system MUST ship `lib/Settings/openconnector_seed_data.json` containing
3–5 seed objects per mutable config schema. The file MUST be a JSON object keyed
by schema slug; each value MUST be an array of object literals carrying the
`@self` envelope (`register`, `schema`, `slug`). Log schemas MUST NOT appear in
the seed data file.

Seed objects MUST use safe placeholder values for any secret-bearing column
(`apikey`, `password`, `secret`, `jwt`, `authenticationConfig`). Examples:
`"YOUR_API_KEY_HERE"`, `"00000000-0000-0000-0000-000000000000"`,
`"<placeholder>"`.

#### Scenario: Seed file contains only mutable schemas
- GIVEN `openconnector_seed_data.json` is loaded
- WHEN inspecting the top-level keys
- THEN they MUST be a subset of the 11 mutable schema slugs
- AND `call_log`, `job_log`, `synchronization_log`,
  `synchronization_contract_log` MUST NOT appear

#### Scenario: Source seed objects use safe placeholder credentials
- GIVEN seed entries for the `source` schema
- WHEN inspecting `apikey`, `password`, `secret` fields
- THEN values MUST be one of `"YOUR_API_KEY_HERE"`, `"<placeholder>"`, or an
  obviously non-credential string
- AND values MUST NOT resemble real Bearer tokens, JWT tokens, or hex/base64
  secrets of plausible length

### Requirement: Descriptor MUST be backwards-compatible with legacy field names

**ID**: REQ-A-008

During the transition window, the descriptor MUST permit reads from both the
legacy `*Id` integer fields and the new target-schema-named string fields. The
storage chain populates both; the field-rename cleanup is deferred to a
follow-up change. The descriptor MUST NOT remove a legacy field without a
matching ADDED/REMOVED entry in a future change.

#### Scenario: Legacy field present alongside relation field
- GIVEN any schema with an FK relation declared per REQ-005
- WHEN inspecting both the relation field and the legacy `*Id` field
- THEN both MUST be present in `properties`
- AND both fields MUST NOT appear in the schema's `required` array (they are optional during the transition)

## Non-Functional Requirements

- **Performance:** Descriptor file MUST be importable by OR's
  `ConfigurationService` in under 5 seconds on a dev laptop (postgres + 15
  schemas + 33 seed objects). Empirical baseline: pipelinq's 25-schema file
  imports in ~2 s on the same hardware.
- **Internationalization:** Schema and property descriptions MUST be authored in
  English. Dutch + English translations of user-facing strings come from the
  app's `l10n/` directory, not from the descriptor (hydra ADR-007). Schema titles MUST
  be human-readable English suitable for surface in OR's admin UI.
- **File size:** Descriptor MUST stay under 250 KB to keep `git diff` reviewable.
  pipelinq ships ~80 KB for 25 schemas; openconnector with 15 schemas is expected
  to fit comfortably.

## Acceptance Criteria

- [ ] `lib/Settings/openconnector_register.json` exists, parses as JSON, and
  matches the structure declared in REQ-001 + REQ-002.
- [ ] All 4 log schemas pass `appendOnly + immutable` checks (REQ-003).
- [ ] All 6 integer-FK relations declared per REQ-005, sibling legacy fields
  preserved per REQ-008.
- [ ] `lib/Settings/openconnector_seed_data.json` exists with 3–5 objects per
  mutable schema, no log entries, placeholder secrets (REQ-007).
- [ ] Dev-environment dry-run import via `ConfigurationService::importFromApp`
  succeeds without errors (manual verification noted in test plan).

## Notes

- This spec is the contract that the storage chain
  (`openconnector-register-storage`) consumes. Field renames and rewrites in
  the storage chain MUST honour REQ-005 + REQ-008.
- The `actionId` field on `call_log` is intentionally NOT in REQ-005's relation
  table because its target schema is ambiguous (see DEFERRED Q2 on the
  proposal). The storage chain resolves this from call-site inspection.
- `archive` (first-class column on OR `Schema`, not the same as
  `x-openregister-archival`) is set to `[]` for all schemas in this change;
  per-schema archive rules can be added in a follow-up.
