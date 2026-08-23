# ADR-005: Source / Synchronization / SynchronizationContract is the core data triad

## Status
Accepted (capturing existing decision)

## Date
2026-05-20

## Context

Integriq's data-sync feature set is built around a three-entity model:

1. **Source** (`lib/Db/Source.php`) — an external API connection. Holds base
   URL, authentication, headers, rate-limit watermarks (`rateLimitLimit`,
   `rateLimitRemaining`, `rateLimitReset`, `rateLimitWindow`), `lastCall`,
   `lastSync`, type (`json`/`xml`/`soap`/`ftp`/`sftp`), auth scheme
   (`apikey`/`jwt`/`username-password`/`oauth`/...).
2. **Synchronization** (`lib/Db/Synchronization.php`) — a sync flow definition
   that links a Source to a Target with mapping + pagination + conditions.
3. **SynchronizationContract** (`lib/Db/SynchronizationContract.php`) — a
   PER-OBJECT state record tracking `originId`, `originHash`, `targetId`,
   `targetHash`, and last-checked / last-changed / last-synced timestamps on
   both sides.

The contract is the unit of incremental sync: it carries the hashes used for
change detection, the FK between source-side ID and target-side ID, and the
`targetLastAction` audit field (`create`/`read`/`update`/`delete`). Without
contracts, sync would be either full-refresh-only or write-only.

`SynchronizationContract.php:19-21` still carries deprecated `sourceId` /
`sourceHash` fields with a `@todo can be removed when migrations are merged`
note — the rename to `originId` / `originHash` happened but the columns
remain pending a migration cleanup.

## Decision

Source -> Synchronization -> SynchronizationContract is the canonical data
flow for integriq. Any new sync-style integration MUST express itself
in these three entities:

- A new external system is modelled as a `Source` row.
- A flow that pulls (or pushes) data is a `Synchronization` row.
- Each pulled / pushed object gets exactly one `SynchronizationContract` row
  per Synchronization.

Per-object hash storage on the contract is the change-detection primitive.
Hash-based diffing is the contract; do not bypass it for "force re-sync"
unless the call explicitly opts in (the existing `force` flag on
SynchronizationService).

## Consequences

- A new sync direction (e.g. push-based webhooks) must reuse `Source` +
  `Synchronization` rather than introducing a parallel `WebhookSource` /
  `WebhookSync` pair. `Consumer` + `EventSubscription` + `EventMessage` form
  a separate event-bus surface (5 of the 15 schemas) and are deliberately
  distinct from this triad. The event-bus model currently has no dedicated
  ADR — flagged as MISSING-ADR-2 in the [2026-05-20 critical audit](/tmp/audit-2026-05-20.md);
  a follow-up ADR-012 should document it.
- The deprecated `sourceId` / `sourceHash` columns on
  `SynchronizationContract` are kept until a migration cleanup; do not
  write to them. Read from `originId` / `originHash`.
- Cross-reference: ADR-003 (CallLog) — each contract sync produces N
  CallLog rows.
- Cross-reference: ADR-002 (mapping engine) — the Synchronization's mapping
  drives the source-to-target transform.
- Cross-reference:
  `openspec/changes/openconnector-register-storage/proposal.md` — the
  storage migration turns each of these into an OR schema; the triad
  shape stays unchanged.

## Evidence

- `lib/Db/Source.php:21-63` — 40+ field Source entity.
- `lib/Db/SynchronizationContract.php:18-42` — origin/target tracking fields.
- `lib/Db/SynchronizationContract.php:19-21` — `@todo can be removed when
  migrations are merged` for the deprecated columns.
- `lib/Service/SynchronizationService.php:46-80` — service-level
  orchestration with extra-data fetch hooks, mutation types, mapping/contract
  linkage.
- `README.md:113-127` — public data-model documentation matching this triad.
