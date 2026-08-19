# Tasks — flow-native synchronization

## 0. Dialog debt (independent, ship first)

- [ ] 0.1 nextcloud-vue: `flowNodeEditors` registry +
      `registerFlowNodeEditor(nodeId, component)`; `CnFlowDetail` opens the
      registered editor over the generic dialog, same Done/Cancel/Remove
      contract.
- [ ] 0.2 openregister: catalogue `configFields`
      (`{key, type, reference?: {register, schema}}`) alongside `configKeys`;
      preflight keeps accepting both.
- [ ] 0.3 nextcloud-vue: `CnFlowNodeEditModal` renders a reference field as a
      register/schema-backed select — no more bare uuid boxes.
- [ ] 0.4 openconnector: register the Synchronization dialog as the editor for
      `synchronization-run` (node fields output/maxItems/onError alongside);
      Source picker for `source-call`.

## 1. Engine steps (each a thin adapter over a KEPT service)

- [ ] 1.1 `openconnector.source-paginate` — one item per page; bounded
      concurrent fetches via `FlowConcurrency` once the total is known;
      rate-limit suspension (`FlowSuspension`, 60s–3600s bounds) moves here.
- [ ] 1.2 `openconnector.apply-mapping` — `MappingService` over the whole
      page inside one execution.
- [ ] 1.3 `openconnector.contract` — one `IN (sourceIds…)` lookup per page
      via `SynchronizationContractService`; hash compare; stamps
      create/update/skip + targetId.
- [ ] 1.4 openregister: `object-write` gains `bulk: true` →
      `ObjectService::saveObjects()`.
- [ ] 1.5 `openconnector.contract-commit` — bulk contract upsert + log rows,
      stamped with the run id.
- [ ] 1.6 `openconnector.contract-sweep` — contracts not stamped by this
      run → delete/flag per config; refuses to run after a partial pass.
- [ ] 1.7 Every node: `configKeys`/`configFields`, vocabulary pinned by unit
      test (the SourceCallNode/SynchronizationRunNode pattern), log links.

## 2. The canonical flow, proven

- [ ] 2.1 Author the reference flow (design.md) against the CKAN benchmark
      source; page cursor in `flow-state`.
- [ ] 2.2 Benchmark: decomposed flow vs `synchronization-run` on the 2000-
      dataset CKAN run — meet or beat, measured, before any deprecation.
- [ ] 2.3 Re-run idempotency: second pass performs zero writes (contract hash
      path), asserted.
- [ ] 2.4 Playwright e2e: draw the flow in the editor, run it, assert objects
      + contracts + resumability after a mid-run suspension.

## 3. Migration + deprecation

- [x] 3.1 Generator: Synchronization entity → generated flow (named,
      disabled-until-reviewed), task-6.2 precedent; contracts untouched.
      `SynchronizationFlowGenerator` + `occ openconnector:synchronization-to-flow`.
      It REFUSES anything the decomposed flow cannot express rather than
      emitting a flow that would do less. Two gaps found and recorded on the
      PR: the engine cannot branch per item (`FlowTokenRouter::takenExits()`
      reads `$items[0]` and routes the whole token), so a mixed create/update
      pass cannot use `object-write`'s BULK path — the generated flow uses the
      single-object upsert; and `object-write` has no "write the record whole"
      shorthand, so the written field list is enumerated from the mapping and
      frozen at generation time.
- [ ] 3.2 Deprecation banners + "open as flow" on Jobs, Rules, Mappings,
      Synchronizations pages; nav folds under Automation.
- [x] 3.3 Jobs → trigger-schedule flows; Rules → trigger-object + switch.
      `JobToFlowGenerator` + `occ openconnector:job-to-flow`, and
      `RuleToFlowGenerator` + `occ openconnector:rule-to-flow <rule> <endpoint>`.
      Both follow the 3.1 pattern: refusal-first, `enabled: false`, nothing
      persisted, the source entity's id recorded in the description. A Job has
      no cron — it has an `interval` in SECONDS measured from the END of the
      previous run — so only intervals that divide the hour or the day evenly
      are translated and the rest are refused rather than rounded. A Rule has
      no register, schema or method of its own, so the endpoint that runs it is
      a required argument; only `after`-timing rules of type `synchronization`
      or `flow` have an object-event equivalent, and conditions are rewritten
      from `body.*` onto `json.*` with every other envelope root refused by
      name. Three engine gaps recorded on the PR: the flow preflight cannot
      fail a TRIGGER config (`configRejection()` blocks only on
      `UnexpectedValueException`, the trigger nodes throw
      `InvalidArgumentException`); `job.singleRun` is declared on the schema
      while `JobService::executeJob()` reads `isSingleRun`, so the flag never
      fires today; and no node emits a CloudEvent or calls a Source's bare
      root, which is why `EventAction` and `PingAction` jobs are refused.
- [ ] 3.4 Page removal — LAST, after every live synchronization has a
      reviewed generated flow and 2.2 holds; `synchronization-run` deprecates
      with them.
