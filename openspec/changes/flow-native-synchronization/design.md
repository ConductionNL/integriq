# Design — the decomposed synchronization flow

## The reference flow

What today happens inside `SynchronizationService::synchronize()`, drawn:

```
[When it runs]                trigger-schedule / trigger-manual / trigger-object
      │
[Fetch a page]                openconnector.source-paginate   (NEW)
      │                       one item per PAGE; bounded-concurrent page
      │                       fetches once the first response reveals the
      │                       total; rate-limit suspension moves here
      │
[Map the page]                openconnector.apply-mapping     (NEW)
      │                       MappingService over every object in the page,
      │                       inside ONE step execution
      │
[Contract the page]           openconnector.contract          (NEW)
      │                       ONE SELECT for the page's sourceIds via
      │                       SynchronizationContractService; hash-compares;
      │                       stamps each object create | update | skip and
      │                       attaches the known targetId
      │
[Drop unchanged]              openregister.filter             (exists)
      │
[Bulk save]                   openregister.object-write       (EXTENDED)
      │                       `bulk: true` → ObjectService::saveObjects() —
      │                       one optimized write for the page
      │
[Commit contracts]            openconnector.contract-commit   (NEW)
      │                       bulk contract upsert + contract log rows,
      │                       stamped with this run's id
      │
      └── next page? ────────► back to [Fetch a page]         openregister.iterate (exists)
      │
[Stale sweep]                 openconnector.contract-sweep    (NEW)
      │                       contracts NOT stamped by this run = gone from
      │                       the source; delete / flag per config
[End]                         openregister.end                (exists)
```

Every NEW node is a thin adapter over a service that already exists and stays:
`CallService`, `MappingService`, `SynchronizationContractService`,
`SynchronizationContractLogService`, `SynchronizationLogService`. The node
layer adds vocabulary (`configKeys`/`configFields`), item shapes, and log
links — nothing else.

## Where the bulk calls and parallelism live (the optimization model)

1. **Fan out at the page, never the object.** A flow item per object means a
   per-object trip through the engine — persistence, logging, marking — which
   multiplies fixed overhead by N. The per-object sub-flow pattern in the demo
   flows is didactic; the sync architecture batches. Nodes carry ARRAYS (a
   page of objects per item) and loop internally. This is the single most
   important decision in this design.
2. **Network parallelism at the fetch.** `source-paginate` reuses
   `FlowConcurrency` (the bounded, ordered Guzzle promise pool
   `source-call` already ships): page 1 synchronous, and when the response
   names the total, the remaining pages dispatch concurrently up to the
   configured bound. Proven live by the CKAN benchmark flow ("the remaining
   pages are fetched concurrently rather than one round trip at a time").
3. **The database is the sync's real cost — one round-trip per page, per
   concern.** Contract matching: one `IN (sourceIds…)` SELECT per page.
   Saving: one `ObjectService::saveObjects()` per page (the bulk,
   memory-optimized handler OpenRegister already ships). Contract commit: one
   bulk upsert per page. Page size is the batching knob (`pageSize`, default
   100) — the only number an operator should ever need to tune.
4. **Idempotency stays contract-based.** The hash compare happens in
   `contract`, BEFORE the write path, so unchanged objects cost a lookup and
   nothing else — re-running a sync of 2000 unchanged objects performs zero
   writes.
5. **Resumability through flow state.** The page cursor is written to
   `openregister.flow-state` after each committed page; a failed or suspended
   run resumes at the cursor instead of refetching completed pages. Rate
   limits use the existing suspension machinery (`FlowSuspension`, bounded
   60s–3600s waits) — moved from the black box into the fetch step.
6. **Failure isolation per step.** `onError` policy applies per page-step; a
   page that fails after retries suspends the run with the cursor intact.
   The stale sweep runs ONLY after a complete pass — a partial run must never
   delete objects it simply did not reach.

## Dialogs: the real editor, never a uuid box

Two mechanisms, complementary:

- **Per-node-type editors** — `@conduction/nextcloud-vue` gains a
  `flowNodeEditors` registry (same pattern as the integration registry):
  `registerFlowNodeEditor(nodeId, component)`. `CnFlowDetail` opens the
  registered component instead of the generic `CnFlowNodeEditModal`; the
  component receives the node draft and the same Done/Cancel/Remove contract.
  OpenConnector registers: `synchronization-run` → the existing
  Synchronization dialog (plus the node's own fields: output, maxItems,
  onError), `apply-mapping` → the Mapping editor, source nodes → the Source
  picker.
- **Reference pickers in the generic dialog** — the catalogue's `configKeys`
  evolves to `configFields`: `{key, type, reference?: {register, schema}}`.
  A field carrying a reference renders as a select over that register/schema
  (label = object name, value = uuid) instead of a text box. Engine-declared,
  so every app's nodes get pickers without frontend work — and `configKeys`
  stays supported as the degraded form.

## Deprecation map (pages retire, code stays)

| Legacy surface | Becomes | Kept underneath |
|---|---|---|
| Jobs | `trigger-schedule` on a flow | job runner infrastructure until the last job migrates |
| Rules | `trigger-object` + `switch`/`filter` steps | rule evaluation service |
| Mappings page | mapping editor opened FROM the step dialog | `MappingService`, Mapping entities |
| Synchronizations page | the Flows list (generated flows) | every synchronization service + contract tables |

Phase order: banners + "open as flow" first, nav entries fold under
Automation → Flows, page removal LAST — only after the migration generator
has produced a flow for every live synchronization and the benchmark holds.

## Migration

A generator renders each Synchronization entity into a generated flow
(`app: openconnector`, named after the synchronization, disabled until
reviewed), following the `flow-engine-unification` task 6.2 precedent.
Contracts are untouched — same tables, same service — so a generated flow's
first run upserts exactly like the black box's next run would have.
`synchronization-run` stays as the bridge for anything not yet migrated and
deprecates last.

## What this deliberately does not do

- No rewrite of `SynchronizationService` — it keeps serving the bridge node
  and API consumers until the last migration, then shrinks to the shared
  helpers the step nodes use.
- No per-object sub-flow pattern for syncs (see optimization model, point 1).
- No new HTTP client, credential path or write path anywhere — delegation
  only, per ADR-011/ADR-022.
