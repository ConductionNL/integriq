# Synchronizations

## Overview

A **Synchronization** defines a complete data flow between a source system and a target system. The synchronization engine reads objects from a configured source (via `CallService`), applies a mapping, detects changes via hash comparison, and writes the transformed result to a target (OpenRegister schema or another source). Per-object state is stored in **SynchronizationContracts**.

## Synchronization Configuration

### Source Configuration

| Field | Description |
|-------|-------------|
| `sourceId` | ID of the Source to fetch data from |
| `sourceEndpoint` | Path appended to the source base URL |
| `sourceType` | `api` or other supported types |
| `resultsPosition` | Where objects live in the response: `_root`, dot-notation (e.g. `data.items`), or auto-detected common keys (`items`, `results`, `result`) |
| `sourceIdField` | Path to the unique ID field in each source object |
| `paginationQuery` | Query parameter name for page-based pagination |
| `usesPagination` | Set to `"false"` if the source does not paginate (default: auto-detect) |
| `conditions` | JSON Logic expression to filter which objects to sync |
| `restrictDeletion` | If `true`, only delete objects whose origin ID appeared in the most recent source response |

### Target Configuration

| Field | Description |
|-------|-------------|
| `targetType` | `register/schema` (OpenRegister) or source-based target |
| `targetId` | Register ID for `register/schema` targets |
| `targetSchema` | Schema ID for `register/schema` targets |
| `targetSourceId` | Source ID for source-based targets |
| `targetMapping` | Mapping ID for outgoing data transformation |
| `idInRequestBody` | Key to inject the target object ID into the request body (for targets that require it) |

### Mapping

| Field | Description |
|-------|-------------|
| `mappingId` | Mapping applied to inbound data before writing to target |

## Process Flow

```
1. Fetch all pages from source (pagination handled automatically)
2. For each source object:
   a. Compute origin hash (SHA256 of source JSON)
   b. Look up or create SynchronizationContract
   c. Skip if: origin hash unchanged AND sync config unchanged AND target exists
   d. Apply mapping (source → target schema)
   e. Write to target (POST create or PUT/PATCH update)
   f. Update contract: targetId, targetHash, sourceLastChecked
3. For objects in contracts but absent from source response:
   a. Mark as deleted in target (DELETE) unless restrictDeletion applies
   b. Update contract status
4. Write SynchronizationLog entry with result summary
```

## Change Detection

The synchronization engine skips updates when all of the following are true:

1. Origin hash matches the stored hash in the contract (source object unchanged)
2. The synchronization configuration has not been updated since the last check
3. The source-target mapping (if used) has not been updated since the last check
4. The target ID and target hash exist in the contract (object not deleted from target)
5. `force` parameter is not set

This prevents unnecessary API calls and database writes on unchanged data.

## SynchronizationContracts

A **SynchronizationContract** tracks the state of a single synchronized object:

| Field | Description |
|-------|-------------|
| `synchronizationId` | Parent synchronization |
| `originId` | Unique ID of the object in the source system |
| `targetId` | Unique ID of the object in the target system |
| `originHash` | SHA256 of the last-seen source object |
| `targetHash` | SHA256 of the last-written target object |
| `sourceLastChecked` | Timestamp of the last check against the source |
| `targetLastChecked` | Timestamp of the last write to the target |

## Sub-Object Support

Related or nested objects inside a parent can be synchronized with their own contracts. Configure `subObjects` in the source configuration with the path to each sub-object and its own synchronization reference. The engine finds and updates existing contracts for sub-objects rather than duplicating them.

To enable sub-object deduplication, map an `originId` field in the sub-object's mapping so the engine can locate the existing contract.

## Pagination

OpenConnector handles pagination automatically:

- Detects `next` link in response for cursor-based pagination
- Supports page-number-based pagination via `paginationQuery`
- Respects a maximum of 50 pages per run (safety limit, configurable)
- Set `usesPagination: "false"` to disable pagination for sources that return all results in one response

## XML Support

Sources returning XML are automatically parsed into JSON before mapping. Attribute values are preserved using the `@attributes` convention.

## Force and Test Modes

| Mode | Behavior |
|------|----------|
| `force: true` | Skip change detection; update all objects regardless of hash |
| `test: true` | Run through the full flow but do not write to target; log results only |

## Logging

Each synchronization run writes a **SynchronizationLog** entry with:

- Run start and end timestamps
- Number of objects processed, created, updated, deleted, skipped
- Error details per failed object
- Overall result (`success`, `warning`, `error`)

Log retention is configurable per synchronization (success retention, error retention, error contract retention).

## Implementation

- `lib/Service/SynchronizationService.php` — Core sync engine, pagination, change detection
- `lib/Controller/SynchronizationsController.php` — REST CRUD API
- `lib/Controller/SynchronizationContractsController.php` — Contract management API
- `lib/Db/Synchronization.php` — Synchronization entity
- `lib/Db/SynchronizationContract.php` — Contract entity
- `lib/Db/SynchronizationLog.php` — Log entity
- `lib/Db/SynchronizationContractMapper.php` — Contract mapper
- `lib/Db/SynchronizationLogMapper.php` — Log mapper
