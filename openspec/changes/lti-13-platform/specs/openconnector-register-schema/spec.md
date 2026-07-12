# openconnector-register-schema — Delta: three new LTI schemas (16 → 19)

## MODIFIED Requirements

### Requirement: Register descriptor file MUST exist at the canonical path (REQ-A-001)

The system MUST ship a register descriptor file at
`lib/Settings/openconnector_register.json` that conforms to OpenAPI 3.0 with the
`x-openregister` vendor extension. The file MUST declare exactly one register
with slug `openconnector` and a non-empty `schemas` array referencing every
schema defined in `components.schemas`.

#### Scenario: Descriptor file present at canonical path

- **GIVEN** a fresh checkout of the openconnector repo
- **WHEN** inspecting `openconnector/lib/Settings/`
- **THEN** `openconnector_register.json` MUST exist
- **AND** it MUST parse as valid JSON
- **AND** its top-level keys MUST be exactly `openapi`, `info`, `x-openregister`,
  `components`

#### Scenario: Register slug is openconnector

- **GIVEN** the descriptor file is loaded
- **WHEN** inspecting `components.registers`
- **THEN** exactly one register entry MUST exist with slug `openconnector`
- **AND** its `schemas` array MUST list all 19 schema slugs

### Requirement: All 19 schemas MUST be declared (REQ-A-002)

The system MUST declare 19 schemas in `components.schemas`. The mutable config
schemas are: `source`, `consumer`, `endpoint`, `event`, `event_message`,
`event_subscription`, `job`, `mapping`, `rule`, `synchronization`,
`synchronization_contract`, `ris_sync_record`, `lti_platform`, `lti_tool`,
`lti_deployment` (15 total). The append-only log schemas are: `call_log`,
`job_log`, `synchronization_log`, `synchronization_contract_log` (4 total,
unchanged by this change).

`lti_platform` and `lti_tool` are mutable registration schemas (an external
Platform or Tool this instance has a trust relationship with — see the
`lti-platform` capability's REQ-LTI-001/002 for their field shape, including
the per-registration `signingKeys[]` array). `lti_deployment` is a mutable
join schema linking exactly one `lti_platform` or `lti_tool` to a
consuming-app placement (REQ-LTI-010). None of the three is a log schema:
none is append-only, and none carries the `x-openregister-archival`
annotation (REQ-A-004 continues to apply only to the 4 existing log schemas).

Each schema MUST declare `slug`, `title`, `version`, and `properties` at
minimum. Each schema's `properties` MUST cover every protected field declared on
the matching `lib/Db/<EntityName>.php` entity (excluding internally-derived
fields like `id` which OR manages automatically).

#### Scenario: All 19 schemas present

- **GIVEN** the descriptor file is parsed
- **WHEN** inspecting `components.schemas`
- **THEN** exactly 19 schema entries MUST exist
- **AND** their slugs MUST be the union of the 15 mutable config and 4 log
  slugs

#### Scenario: Schema field coverage matches entity definition

- **GIVEN** the `Source` entity defined in `lib/Db/Source.php` with 39 protected fields
- **WHEN** comparing against the `source` schema's `properties`
- **THEN** every entity protected field MUST appear as a property on the schema
- **AND** the property `type` MUST map per the conversion: PHP `string` → JSON
  `string`, PHP `integer` → JSON `integer`, PHP `boolean` → JSON `boolean`,
  PHP `array` (json column) → JSON `array` or `object`, PHP `DateTime` → JSON
  `string` with `format: "date-time"`

#### Scenario: lti_platform, lti_tool, and lti_deployment are mutable, not append-only

- **GIVEN** the descriptor file is parsed
- **WHEN** inspecting the `lti_platform`, `lti_tool`, and `lti_deployment`
  schema entries
- **THEN** none SHALL carry `immutable: true` or an
  `x-openregister-archival` annotation
- **AND** all three SHALL remain in the mutable config group counted by this
  requirement
