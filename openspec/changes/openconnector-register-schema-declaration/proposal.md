---
kind: config
depends_on: []
---

# Proposal: openconnector-register-schema-declaration

## Summary

Declare OpenConnector's full data model as an OpenRegister register descriptor at
`lib/Settings/openconnector_register.json`, following the OpenAPI 3.0 + `x-openregister`
extension shape established by pipelinq and procest. The register contains one
`openconnector` register with 15 schemas (11 mutable config + 4 append-only +
immutable logs), relation-annotated foreign-key fields, retention metadata on the
log schemas, and ADR-001-compliant seed data. This change is **config-only** — it
introduces no PHP code. The companion code change
[`openconnector-register-storage`](../openconnector-register-storage/) consumes this
descriptor through `ConfigurationService::importFromApp(...)` to provision the
register and migrate the existing tables.

## Motivation

OpenConnector currently persists its entire domain (15 entities) in dedicated
`oc_openconnector_*` tables managed by hand-rolled mappers. This means:

- **Logs cannot be marked immutable** — `call_log`, `job_log`, `synchronization_log`,
  and `synchronization_contract_log` are crucial for audit and forensic analysis,
  but the current schema gives them no append-only or immutability guarantees.
  OpenRegister's `appendOnly: true` + `immutable: true` schema flags solve this
  declaratively (see `audit-trail-immutable` and `retention-management` specs in
  openregister/openspec).
- **Retention is triplicated as PHP constants** in `JobService`, `CallService`,
  and `SynchronizationService` (`DEFAULT_SUCCESS_LOG_RETENTION = 3600000`,
  `DEFAULT_ERROR_LOG_RETENTION = 2592000000`). The companion change
  [`openconnector-adopt-or-abstractions`](../openconnector-adopt-or-abstractions/)
  removes the constants; this change re-expresses the same retention windows once
  as `x-openregister-archival` annotations on the log schemas.
- **Foreign keys cannot drive cross-entity behaviour.** Integer FKs across 6 columns
  on `EventMessage` and `CallLog` cannot participate in OpenRegister's relation
  machinery (`_relations`, `extend`, `inversedBy`, cascade-delete). Re-expressed as
  `$ref` references they unlock platform-level features for free.
- **Cross-app discoverability** — apps like `decidesk`, `pipelinq`, and `procest`
  cannot easily target openconnector objects through OR's `register/schema/uuid`
  triplet. A first-class register makes openconnector queryable through the same
  surface as every other Conduction app.

ADR-032 forbids mixed code+config proposals when the code surface exceeds 20 LOC.
The companion storage change is hundreds of LOC, so the schema declaration is split
out as a stand-alone config change that can land first and be validated
independently.

## Affected Projects

- [x] Project: `openconnector` — adds `lib/Settings/openconnector_register.json` and
  `lib/Settings/openconnector_seed_data.json`. No PHP changes.
- [x] Project: `openregister` — passive consumer; provisions the register at
  install/upgrade time via `ConfigurationService::importFromApp(...)`. No
  openregister code change required.

## Scope

### In Scope

- `lib/Settings/openconnector_register.json` — single OpenAPI 3.0 + `x-openregister`
  document containing one register (`openconnector`) and 15 schema definitions:
  - **Mutable config (11):** `source`, `consumer`, `endpoint`, `event`,
    `event_message`, `event_subscription`, `job`, `mapping`, `rule`,
    `synchronization`, `synchronization_contract`
  - **Append-only logs (4)**, each carrying `appendOnly: true`, `immutable: true`,
    and `x-openregister-archival` retention: `call_log`, `job_log`,
    `synchronization_log`, `synchronization_contract_log`
- Foreign-key fields declared with `format: "uuid"` + `$ref: "<target-schema>"` so
  OpenRegister's relation engine activates. During the transition the legacy
  `*Id` field names are preserved alongside the relation-named fields (see Open
  Questions); the storage chain populates both.
- Retention windows on each log schema set from the existing `Source.logRetention` /
  `Job.logRetention` / `errorRetention` defaults (seconds → ISO-8601 duration).
- `lib/Settings/openconnector_seed_data.json` — 3–5 realistic objects per mutable
  config schema using general-organisation data per ADR-001 line 44. Logs receive
  no seed data (append-only).
- ADR-001 manifest entry (slug, register list, version, published date) at the
  register level.

### Out of Scope

- Any PHP code change. This change is **declarative-only**.
- Migration of existing data from `oc_openconnector_*` tables to OR — handled by
  the companion code change `openconnector-register-storage`.
- Dropping `oc_openconnector_*` tables — handled by a follow-up cleanup change one
  release after `openconnector-register-storage` ships.
- Renaming foreign-key field names from `*Id` to the target-schema name (e.g.
  `sourceId` → `source`). The transition window keeps both forms; a follow-up
  change resolves this.
- Changes to the hardcoded retention constants in PHP services — handled by
  `openconnector-adopt-or-abstractions`. This change only ensures the same values
  are now also expressible declaratively.

## Approach

1. Read each `lib/Db/*.php` entity, transcribe its fields and types into JSON
   Schema, preserving names (incl. legacy `*Id` fields) and types verbatim.
2. For every integer foreign-key column, add a sibling field named by the target
   schema (`source`, `event`, `consumer`, etc.) carrying `format: "uuid"` and
   `$ref: "<target-schema>"`. Both fields stay; the storage chain populates them
   in lockstep.
3. Mark each of the 4 log schemas with `appendOnly: true` and `immutable: true`.
4. Add `x-openregister-archival` on log schemas with retention derived from
   `JobService::DEFAULT_SUCCESS_LOG_RETENTION` (3,600,000 ms = 1 hour) and
   `DEFAULT_ERROR_LOG_RETENTION` (2,592,000,000 ms = 30 days), expressed as
   ISO-8601 durations.
5. Write seed data for the 11 mutable schemas. No PHP, no migration, no controller
   change.
6. Validate the register file by importing into a clean dev environment using OR's
   existing `ConfigurationService::importFromApp(...)` (read-only check, no commit
   of state).

## New Dependencies

None. OpenConnector already lists `openregister` as a peer app; this change adds no
package dependency.

## Impact

- **New files:** `lib/Settings/openconnector_register.json`,
  `lib/Settings/openconnector_seed_data.json`
- **Code untouched.** No controller, service, mapper, migration, or entity is
  modified.
- **OpenRegister side:** Once the storage chain merges and imports the register,
  OR provisions 1 register row + 15 schema rows + ~33 seed objects. Until then the
  file is dormant.

## Cross-Project Dependencies

- **`openregister`** — consumes the descriptor at `ConfigurationService::importFromApp`
  time. Requires OR `^v0.2.10` (matches pipelinq's existing minimum).
- **`openconnector-register-storage`** (sibling change, code chain) — depends on
  this descriptor existing in the repo before it can run its data migrator.
- **`openconnector-adopt-or-abstractions`** (sibling change, code chain) — does
  NOT block this change, but cooperates: once `adopt-or-abstractions` removes the
  triplicated retention constants and this change declares the same values on the
  log schemas, the values exist exactly once in the codebase.

## Risks

### Risk 1: Schema drift between JSON descriptor and live entity fields
**Severity:** Medium — **Mitigation:** Schema is generated by transcribing each
`lib/Db/*.php` entity field-for-field. A CI check (added in the storage chain's
test plan) re-reads every entity and asserts every protected property is present
in the register file.

### Risk 2: Retention values change in code but not in the JSON
**Severity:** Medium — **Mitigation:** `openconnector-adopt-or-abstractions`
removes the PHP constants and points docs at the JSON descriptor as the single
source of truth. The risk only exists during the brief window where both forms
co-exist.

### Risk 3: Relation `$ref` to a yet-undeclared schema (e.g. `synchronization`
referenced before `synchronization` is declared in the same file)
**Severity:** Low — **Mitigation:** OpenAPI 3.0 allows forward refs within the same
document. OR's importer resolves refs after the full document is parsed. Validated
during dev-env import.

### Risk 4: Field rename from `*Id` to target-schema name not coordinated with
existing frontend stores
**Severity:** Low — **Mitigation:** Frontend reads `*Id` today; the descriptor
declares both names. The storage chain populates both, frontend keeps working,
follow-up change removes `*Id` once Vue stores are updated.

## Rollback Strategy

This change introduces no runtime behaviour — it only adds two static JSON files
to disk. Rollback is a `git revert` of the commit that adds the files. The
companion storage change is what triggers OR provisioning; until that lands the
descriptor is dormant.

If a defect is discovered in the descriptor after the storage chain merges:
- The descriptor is re-imported on every app upgrade via
  `ConfigurationService::importFromApp(...)`. A corrected file shipped in a patch
  release replaces the broken one.
- Already-created OR objects keep their data; only schema metadata is updated
  in-place by the importer. Data loss is not possible from a descriptor fix.

## Open Questions

1. **FK field rename timing.** Should this change rename FK fields from `sourceId`
   → `source` everywhere (breaking change for frontend Vue stores that read
   `sourceId`), or keep both names through one release (provisional decision)?
   See DEFERRED Q1.
2. **`Synchronization.sourceId` / `targetId` overload.** These columns currently
   carry three different value formats (integer PK, `register/schema` slug-pair,
   uuid). The descriptor declares them as `type: string` (broadest), but cannot
   express the three-way disjunction in JSON Schema cleanly. Documented in the
   `synchronization` schema's `description` and resolved by the storage chain.
3. **Action target schema for `CallLog.actionId`.** `actionId` is an integer FK but
   the target table is ambiguous (job? endpoint? rule?). Provisional: declare
   `actionId` as `type: integer` legacy + `action` as `type: string, format: uuid`
   without a `$ref` (untyped relation) until the storage chain resolves the target
   via call-site inspection. See DEFERRED Q2.
