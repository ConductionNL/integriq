# OpenConnector Register Schema Declaration

**OpenSpec change:** `openconnector-register-schema-declaration`
**Status:** Implemented (chain A of the storage chain split)

## Overview

OpenConnector declares its data model as a single OpenRegister register descriptor at
`lib/Settings/openconnector_register.json`. This file is the authoritative source of truth
for all 15 schemas in the integration platform — replacing 15 hand-maintained
`oc_openconnector_*` database tables.

The companion change `openconnector-register-storage` (chain B) invokes
`OCA\OpenRegister\Service\ConfigurationService::importFromApp()` at app install/upgrade time
to provision the register and schemas from this descriptor.

## Descriptor Layout

```
lib/Settings/
├── openconnector_register.json   # Register + 15 schema definitions
└── openconnector_seed_data.json  # Runtime seed (empty; operators populate via UI)
```

### `openconnector_register.json`

OpenAPI 3.0 document with `x-openregister` vendor extension. Top-level structure:

```json
{
  "openapi": "3.0.0",
  "info": { "title": "OpenConnector Register", "version": "1.1.0" },
  "x-openregister": {
    "type": "application",
    "app": "openconnector",
    "openregister": "^v0.2.10"
  },
  "components": {
    "registers": {
      "openconnector": { "slug": "openconnector", "schemas": [/* 15 slugs */] }
    },
    "schemas": { /* 15 schema definitions */ }
  }
}
```

## Schema Inventory

### Mutable config schemas (11)

| Schema slug | Title | Description |
|---|---|---|
| `source` | Source | Outbound integration target (HTTP API, database, or service) |
| `consumer` | Consumer | Event-bus subscriber identity |
| `endpoint` | Endpoint | Inbound API endpoint mapping |
| `event` | Event | CloudEvents-shaped record |
| `event_message` | EventMessage | Delivery record for Event-to-Subscription match |
| `event_subscription` | EventSubscription | Consumer × event-type filter binding |
| `job` | Job | Scheduled background task |
| `mapping` | Mapping | Transformation rule between schemas |
| `rule` | Rule | Conditional pipeline step |
| `synchronization` | Synchronization | Bidirectional/unidirectional sync definition |
| `synchronization_contract` | SynchronizationContract | Per-object sync state |

### Append-only log schemas (4)

| Schema slug | Title | Retention (success) | Retention (error) |
|---|---|---|---|
| `call_log` | CallLog | PT1H (1 hour) | P30D (30 days) |
| `job_log` | JobLog | PT1H | P30D |
| `synchronization_log` | SynchronizationLog | PT1H | P30D |
| `synchronization_contract_log` | SynchronizationContractLog | PT1H | P30D |

## Declarative Flags

### `appendOnly` and `immutable`

All four log schemas declare `"appendOnly": true` and `"immutable": true` at the schema level:

- **`appendOnly: true`** — OpenRegister allows INSERT but blocks UPDATE and DELETE at the API layer.
- **`immutable: true`** — Stronger guarantee: once set, field values on existing rows cannot be changed even through admin tooling.

The combination prevents log tampering from both directions (external callers and internal admin tools).

Mutable config schemas omit both flags (defaulting to `false`), allowing full CRUD operations.

### `x-openregister-archival` retention

Each log schema carries split retention windows encoded as `x-openregister-archival`:

```json
"x-openregister-archival": {
  "retention": {
    "default": "P30D",
    "rules": [
      { "condition": "statusCode < 400", "retention": "PT1H" },
      { "condition": "statusCode >= 400", "retention": "P30D" }
    ]
  }
}
```

Retention values match `JobService` constants:
- `DEFAULT_SUCCESS_LOG_RETENTION = 3 600 000 ms = 3600 s = PT1H`
- `DEFAULT_ERROR_LOG_RETENTION = 2 592 000 000 ms = 2 592 000 s = P30D`

## FK Relations

Six integer foreign-key columns on legacy entities are re-declared as UUID properties
paired with `$ref` to the target schema (REQ-A-005):

| Source schema | Property | Target schema | onDelete |
|---|---|---|---|
| `call_log` | `source` | `source` | SET NULL |
| `call_log` | `synchronization` | `synchronization` | SET NULL |
| `event_message` | `event` | `event` | CASCADE |
| `event_message` | `consumer` | `consumer` | SET NULL |
| `event_message` | `subscription` | `event_subscription` | CASCADE |
| `synchronization_contract_log` | `synchronization_contract` | `synchronization_contract` | CASCADE |

Legacy `*Id` integer fields are retained alongside the new UUID fields during the transition
window (REQ-A-008). Field-rename cleanup is deferred to a follow-up change.

## Overloaded Fields

`synchronization.sourceId` and `synchronization.targetId` carry an overloaded format — they
may hold an integer Source PK (legacy), a `register/schema` slug-pair, or a UUID. The descriptor
declares them as `type: "string"` with **no** `$ref` and a description enumerating all three formats.
Chain B's migrator (`lib/Service/SynchronizationService.php`) contains the resolution logic.

## Chain Split

This change (chain A) is purely declarative — no PHP code, no migration class, no routes:

| Chain | Responsibility |
|---|---|
| **A (this change)** | Descriptor JSON files on disk |
| **B (`openconnector-register-storage`)** | Migration class that invokes OR's importer + data migration from legacy tables |

## CI Guard

`tests/Unit/Settings/RegisterDescriptorTest.php` validates the descriptor on every CI run:

- All 15 schema slugs declared in `components.registers.openconnector.schemas`
- All 15 schemas defined in `components.schemas`
- Log schemas have `appendOnly: true`, `immutable: true`, `x-openregister-archival`
- Mutable schemas do not have these flags
- All 6 FK relations carry `$ref` and `x-openregister-onDelete`
- `synchronization.sourceId` / `targetId` are `type: string` without `$ref`
