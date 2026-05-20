# Test Plan: openconnector-register-schema-declaration

## Test Cases

### TC-1: Descriptor file exists at canonical path and parses as JSON
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-001`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: openconnector repo checked out at the chain-A commit
- **steps**:
  1. Inspect `lib/Settings/` for `openconnector_register.json`
  2. Run `php -r "$d = json_decode(file_get_contents('lib/Settings/openconnector_register.json'), true); var_export(is_array($d));"`
  3. Inspect top-level keys
- **expected result**:
  - File exists
  - `json_decode` returns an array (`true` printed)
  - Top-level keys are exactly `openapi`, `info`, `x-openregister`, `components`
- **test command**: `/test-regression`

### TC-2: Register slug is openconnector with 15 schemas listed
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-001`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: TC-1 passed
- **steps**:
  1. Run `jq '.components.registers | keys' lib/Settings/openconnector_register.json`
  2. Run `jq '.components.registers.openconnector.schemas | length' lib/Settings/openconnector_register.json`
  3. Run `jq '.components.schemas | keys | sort' lib/Settings/openconnector_register.json`
- **expected result**:
  - Step 1: `["openconnector"]`
  - Step 2: `15`
  - Step 3: sorted union of the 11 mutable + 4 log slugs (no extras)
- **test command**: `/test-regression`

### TC-3: Every entity protected field appears in its schema
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-002`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: PHPUnit available; `tests/Unit/Settings/RegisterDescriptorTest.php` (added in Task 8) exists
- **steps**:
  1. Run `composer test -- --filter=RegisterDescriptorTest`
- **expected result**:
  - Test passes for all 15 entities
  - Failure case: diagnostic includes entity name, missing field, target schema slug
- **test command**: `/test-regression`

### TC-4: Log schemas carry appendOnly and immutable flags
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-003`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: TC-1 passed
- **steps**:
  1. Run `jq '.components.schemas | with_entries(select(.value.appendOnly == true)) | keys | sort' lib/Settings/openconnector_register.json`
  2. Run `jq '.components.schemas | with_entries(select(.value.immutable == true)) | keys | sort' lib/Settings/openconnector_register.json`
  3. Run `jq '.components.schemas | with_entries(select(.value.appendOnly == true)) | length' …`
- **expected result**:
  - Step 1 & 2: `["call_log", "job_log", "synchronization_contract_log", "synchronization_log"]`
  - Step 3: `4`
- **test command**: `/test-regression`

### TC-5: Mutable config schemas do not set appendOnly/immutable to true
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-003`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: TC-1 passed
- **steps**:
  1. Run `jq -r '.components.schemas | to_entries[] | select(.key as $k | ["source","consumer","endpoint","event","event_message","event_subscription","job","mapping","rule","synchronization","synchronization_contract"] | index($k)) | "\(.key) appendOnly=\(.value.appendOnly // "absent") immutable=\(.value.immutable // "absent")"' lib/Settings/openconnector_register.json`
- **expected result**:
  - Each of the 11 mutable schemas reports `appendOnly=false|absent immutable=false|absent`
- **test command**: `/test-regression`

### TC-6: Log schemas carry x-openregister-archival with PT1H + P30D
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-004`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: TC-1 passed
- **steps**:
  1. For each of the 4 log schemas, run `jq '.components.schemas.<slug>["x-openregister-archival"]' lib/Settings/openconnector_register.json`
- **expected result**:
  - Output for each schema includes `PT1H` (success retention) and `P30D` (error retention) somewhere in the annotation block
- **test command**: `/test-regression`

### TC-7: CallLog relation fields target source + synchronization
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-005`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: TC-1 passed
- **steps**:
  1. Run `jq '.components.schemas.call_log.properties.source' lib/Settings/openconnector_register.json`
  2. Run `jq '.components.schemas.call_log.properties.synchronization' lib/Settings/openconnector_register.json`
  3. Run `jq '.components.schemas.call_log.properties.sourceId' lib/Settings/openconnector_register.json`
- **expected result**:
  - Steps 1 & 2: each property has `type: "string"`, `format: "uuid"`, `$ref: "<target-schema>"`, `onDelete: "SET NULL"`, and a non-empty `description`
  - Step 3: legacy `sourceId` still exists with `type: "integer"`
- **test command**: `/test-regression`

### TC-8: EventMessage relation fields target event, consumer, event_subscription
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-005`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: TC-1 passed
- **steps**:
  1. For each property in `event_message.{event, consumer, subscription}`, run `jq '.components.schemas.event_message.properties.<prop>'`
  2. Inspect `onDelete`
- **expected result**:
  - All three properties carry the relation shape
  - `event` and `subscription` carry `onDelete: "CASCADE"`
  - `consumer` carries `onDelete: "SET NULL"`
- **test command**: `/test-regression`

### TC-9: Synchronization.sourceId/targetId documented overload
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-006`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: TC-1 passed
- **steps**:
  1. Run `jq '.components.schemas.synchronization.properties.sourceId' lib/Settings/openconnector_register.json`
  2. Run `jq '.components.schemas.synchronization.properties.targetId' lib/Settings/openconnector_register.json`
- **expected result**:
  - Both properties: `type: "string"`, NO `$ref`
  - `description` contains the substrings "integer", "register/schema", "uuid"
- **test command**: `/test-regression`

### TC-10: Seed file contains only mutable schemas with placeholder secrets
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-007`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: `lib/Settings/openconnector_seed_data.json` shipped
- **steps**:
  1. Run `jq 'keys | sort' lib/Settings/openconnector_seed_data.json`
  2. Run `jq '.[] | length' lib/Settings/openconnector_seed_data.json`
  3. Run `jq '.source[] | {apikey, password, secret, jwt}' lib/Settings/openconnector_seed_data.json`
- **expected result**:
  - Step 1: subset of the 11 mutable schema slugs only (no `call_log`, `job_log`, `synchronization_log`, `synchronization_contract_log`)
  - Step 2: each schema array has length 3–5
  - Step 3: every credential value is one of `"YOUR_API_KEY_HERE"`, `"<placeholder>"`, null, or empty
- **test command**: `/test-security`

### TC-11: Dev-environment import provisions 15 schemas
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-001`
- **type**: functional
- **persona**: Sem (developer)
- **preconditions**: nextcloud-docker-dev running with openregister + openconnector installed; clean OR state (no `slug='openconnector'` row)
- **steps**:
  1. Run `docker exec nextcloud php occ openregister:import /var/www/html/custom_apps/openconnector/lib/Settings/openconnector_register.json` (or invoke `ConfigurationService::importFromApp` via a quick `occ` shim)
  2. Query `SELECT slug FROM oc_openregister_registers WHERE slug='openconnector'`
  3. Query `SELECT count(*) FROM oc_openregister_schemas WHERE register=(SELECT id FROM oc_openregister_registers WHERE slug='openconnector')`
  4. Query `SELECT slug, "appendOnly", immutable FROM oc_openregister_schemas WHERE register=(SELECT id FROM oc_openregister_registers WHERE slug='openconnector') AND "appendOnly"=true`
- **expected result**:
  - Step 1: command exits 0, logs no warnings
  - Step 2: returns one row
  - Step 3: returns 15
  - Step 4: returns 4 rows with the 4 log slugs
- **test command**: `/test-functional`

### TC-12: Schema property type mapping for DateTime fields
- **spec_ref**: `openspec/changes/openconnector-register-schema-declaration/specs/openconnector-register-schema/spec.md#req-002`
- **type**: regression
- **persona**: Sem (developer)
- **preconditions**: TC-1 passed
- **steps**:
  1. Run `jq '[.components.schemas[] | .properties | to_entries[] | select(.value.format == "date-time")] | length' lib/Settings/openconnector_register.json`
  2. Cross-check with the count of `addType(..., 'datetime')` calls across `lib/Db/*.php`
- **expected result**:
  - Counts match exactly
- **test command**: `/test-regression`

## Coverage Summary

| Requirement                                              | Covered by    | Status     |
|----------------------------------------------------------|---------------|-----------|
| REQ-001: Descriptor file present and well-formed         | TC-1, TC-2, TC-11 | covered   |
| REQ-002: All 15 schemas declared + field coverage        | TC-2, TC-3, TC-12 | covered   |
| REQ-003: Log schemas appendOnly + immutable              | TC-4, TC-5    | covered   |
| REQ-004: Log schemas carry archival retention            | TC-6          | covered   |
| REQ-005: Integer FK relations annotated                  | TC-7, TC-8    | covered   |
| REQ-006: Synchronization overload documented             | TC-9          | covered   |
| REQ-007: Seed file mutable-only + safe placeholders      | TC-10         | covered   |
| REQ-008: Legacy `*Id` retained                            | TC-7 (partial) | covered  |

## Out of Scope

- Performance under load — descriptor import is one-time at app upgrade;
  load testing is owned by the storage chain.
- Cross-app integration tests (e.g. decidesk reading an openconnector object)
  — those are owned by the storage chain's test plan because they require the
  storage layer to be wired up.
- Accessibility / NL Design — this change is data-only, no UI surface.
- Multilingual content in the descriptor itself — by design English-only
  (ADR-005 covers user-facing strings via `l10n/`, not register descriptors).
