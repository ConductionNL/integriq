# Tasks — flow-native synchronization

## 0. Dialog debt (independent, ship first)

> ⚠️ THESE BOXES WERE UNTICKED WHILE THE WORK WAS DONE. Verified against the
> code 2026-08-22: 0.1 and 0.4 shipped, and 0.2/0.3 describe a problem
> `optionsFrom` had already solved. The checkbox is not the record — the code
> is. A task list that lags the tree costs a later reader a day of rebuilding
> something that exists, which is exactly what nearly happened here.

- [x] 0.1 nextcloud-vue: `flowNodeEditors` registry +
      `registerFlowNodeEditor(nodeId, component)`; `CnFlowDetail` opens the
      registered editor over the generic dialog, same Done/Cancel/Remove
      contract. Live in `src/composables/useFlowNodeEditors.js`, exported from
      `src/index.js`.
- [x] 0.2 openregister: catalogue the typed config fields alongside
      `configKeys`; preflight keeps accepting both.
      NOT built as `configFields`. `IFlowNodeConfigForm::configForm()` already
      returns `{key, label, type, help, required, optionsFrom}`, is already
      exposed by `FlowNodeRegistry` and already rendered by the editor. A third
      declaration beside `configKeys` and `configForm` is precisely what
      `FlowNodeRegistry`'s own comment warns of — a second hand-maintained
      table of keys "is only ever correct until the next node ships a key".
      I DID BUILD A `reference` TYPE FOR THIS (or#2698) AND CLOSED IT. The
      premise was false: `reference: {register, schema}` derives an objects URL,
      and every caller already declares that URL directly via `optionsFrom`.
      It would have been a second mechanism for a solved problem.
- [x] 0.3 nextcloud-vue: the edit dialog renders an object-id field as a
      register/schema-backed select — no more bare uuid boxes.
      ALREADY TRUE via `optionsFrom`. `CnFlowNodeEditModal` (in `src/dialogs/`,
      not `src/components/`) renders `type: 'select'` + `optionsFrom` as a
      picker, resolving a stored uuid to the object's NAME. nc-vue#732, which
      would have rendered the `reference` type, is closed with or#2698.
- [x] 0.4 openconnector: register the Synchronization dialog as the editor for
      `synchronization-run` (node fields output/maxItems/onError alongside);
      Source picker for `source-call`. Live: `src/main.js` calls
      `registerFlowNodeEditor` for `src/modals/v2/SynchronizationNodeEditor.vue`.

> 📌 HOW 0.2/0.3 CAME TO BE WRITTEN, AND THE MEASUREMENT LESSON. I first
> reported them unstarted because I grepped for `configFields` — the name in
> THIS FILE — and found 0 hits, then read 0 hits as "nothing exists". The code
> calls it `configForm`. A search for the name a plan uses measures whether the
> plan's vocabulary was adopted, not whether its goal was met; when the answer
> is zero, the next question is what the code calls the thing, not whether the
> thing is missing.

## 1. Engine steps (each a thin adapter over a KEPT service)

- [x] 1.1 `openconnector.source-paginate` — one item per page; bounded
      concurrent fetches via `FlowConcurrency` once the total is known;
      rate-limit suspension (`FlowSuspension`, 60s–3600s bounds) moves here.
      `lib/Flow/SourcePaginateNode.php`.
- [x] 1.2 `openconnector.apply-mapping` — `MappingService` over the whole
      page inside one execution. `lib/Flow/ApplyMappingNode.php`.
- [x] 1.3 `openconnector.contract` — one `IN (sourceIds…)` lookup per page
      via `SynchronizationContractService`; hash compare; stamps
      create/update/skip + targetId. Shipped as `ContractMatchNode` (the type
      id is still `openconnector.contract`).
- [x] 1.4 openregister: `object-write` gains `bulk: true` →
      `ObjectService::saveObjects()`. Declared in `ObjectWriteNode::configKeys()`
      and read in its config guard.
- [x] 1.5 `openconnector.contract-commit` — bulk contract upsert + log rows,
      stamped with the run id. `lib/Flow/ContractCommitNode.php`.
- [x] 1.6 `openconnector.contract-sweep` — contracts not stamped by this
      run → delete/flag per config; refuses to run after a partial pass.
      `lib/Flow/ContractSweepNode.php`.
- [x] 1.7 Every node: `configKeys`/`configForm`, vocabulary pinned by unit
      test (the SourceCallNode/SynchronizationRunNode pattern), log links.
      All 7 nodes declare both, and each has a vocabulary-pinning test.
      LOG LINKS WERE THE ONLY PART ACTUALLY MISSING: SourceCallNode had them
      and the other six did not, so a run-log entry offered no way back to the
      synchronization it acted on. Closed by `SynchronizationLogActions`, a
      trait shared by paginate, commit, sweep and the legacy run node.
      TWO NODES DELIBERATELY GET NO LINK. `apply-mapping` and `contract` stamp
      no reference into the items they emit, and the log entry carries `input`,
      `output` and timings but NO CONFIG — so there is nothing to build a link
      from without first changing what those nodes emit. `IFlowNodeLogActions`
      says an entry with nothing to point at must return an empty array rather
      than link to a list page, and not implementing it is that answer stated
      once instead of per call.
      ⚠️ THE LINK IS BUILT WITHOUT RESOLVING ANYTHING. `FlowController::logActions()`
      is `NoAdminRequired` and passes the caller's POST body through verbatim,
      so the reference is attacker-controlled: it is escaped into the fragment
      and never looked up. A link built from a lookup would disclose, by its
      mere presence, that a record the caller may not read exists.

## 2. The canonical flow, proven

- [x] 2.1 Author the reference flow (design.md) against the CKAN benchmark
      source. `reference-flow.json` + `reference-flow.md`, validated live.
      The draft WITHOUT `explode` passed preflight and was still wrong —
      preflight validates vocabulary, not item SHAPE. NOT done: the page
      cursor in `flow-state`; the generated flow delegates paging to
      `source-paginate`, which keeps the cursor on the synchronization.
- [x] 2.2 Benchmark: decomposed flow vs `synchronization-run` on the 2000-
      dataset CKAN run — MET, on PARITY not on a margin. Re-measured
      2026-08-21 on a properly quiet box (loadavg 0.44/0.72/0.51, nextcloud
      0.01/0.00/0.00% — essentially the original 0.36 baseline):
        legacy unmapped   7.48 s  (6.98 / 7.32 / 8.15), spread 1.17
        legacy MAPPED    18.91 s  (17.93 / 19.24 / 19.56), spread 1.63,
                                  contract delta 0
        decomposed       18.90 s  (16.42 / 20.82 / 19.45), spread 4.40
      18.90 vs 18.91 is a difference of 0.006 s — 688x SMALLER than the
      flow's own run-to-run spread. They are indistinguishable. The flow
      MATCHES the legacy engine; "meet or beat" is satisfied by meeting.
      CORRECTION: an earlier entry claimed 14.6% FASTER (20.38 vs 23.87).
      Both those figures were taken at loadavg 1.6-2.5 and were inflated by
      contention, unequally. The margin was an artefact of the noise floor,
      not a property of the engines. When the difference between two
      measurements is smaller than the spread within either, report the tie.
      UNCHANGED (counts, not wall clocks): contract delta 0; `write` 3-11 ms
      so `skipWhen` still skips all 2000 and the decomposition is nearly
      free; and MAPPING STILL DOMINATES BOTH ENGINES — `map` is 8.9-9.7 s of
      the flow's ~19 s, and legacy pays 11.4 s more mapped than unmapped on a
      run that writes nothing, because the mapping is resolved PER ITEM
      before the skip decision. Optimise the mapping, not the decomposition.
      3.4 STAYS GATED on a human decision.
- [x] 2.3 Re-run idempotency. `contract-commit` never wrote `targetHash`, so
      `ContractMatchNode::isUnchanged()` could never hold and `skip` was
      unreachable (#1297). Measured live: contracts went 18/18 rewritten to
      0/18. Objects still moved, because a skipped item must keep reaching
      `object-write` or `contract-sweep` deletes it — closed by
      `object-write`'s `skipWhen` (openregister #2592) plus the `synced-id`
      step (#1300), which keeps skipped objects inside the synced set.
- [x] 2.4 Playwright e2e: the generated flow validates, runs, writes objects
      and contracts, and re-runs (#1296). Resumability after a mid-run
      suspension is SKIPPED with a reason — it needs a programmable stub
      source reachable from the SERVER, or an occ seam forcing a
      `FlowSuspension`.

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
- [x] 3.2 Deprecation banners + a Flows affordance on Jobs, Rules, Mappings
      and Synchronizations (#1292). The nav fold was DECLINED, deliberately:
      `menu-layout.json` carries a written `_navigationRationale` (ADR-079/080)
      grouping the menu by data DIRECTION, and Synchronizations sits in
      Connections because it is outbound. Jobs, Rules, Mappings and Flows are
      already under Automation. Reversing a documented decision to satisfy one
      line of design.md — on pages 3.4 removes anyway — needs a human call.
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
      STILL GATED ON A HUMAN DECISION, now with the number it was waiting for.
      Migration coverage MEASURED across all 240 synchronizations on the dev
      instance (full write-up in `migration-coverage.md`):
        before `payloadFrom`:  20 / 119 cleanly judged = 16.8%
        after  #2684 + #1334: 161 / 240                = 67.1%
      `sourceTargetMapping is not set` went from 98 refusals to 0.
      OF THE 79 REMAINING REFUSALS, 74 ARE `actions` — the synchronization
      declares rules and no generated step evaluates them. The rest are single
      digits (targetId shape 4, empty sourceType/targetType 2+2, file source 1,
      api target 1, conditions 1, sourceHashMapping 1). 94% of what is left is
      ONE cause, so measure whether rule evaluation can be expressed as flow
      steps before assuming the scatter matters.
      A PROJECTION OF ~99% WAS RECORDED IN #1334 AND WAS WRONG BY 30 POINTS. It
      came from the earlier sweep, where 98 of 99 refusals were the missing
      mapping — but that sweep judged only 119 of 240 before the instance
      degraded, and the `actions`-heavy synchronizations sat in the part it
      never reached. A partial measurement is not a proportional one.
      TWO THIRDS IS NOT "THE PAGES CAN GO": a third of synchronizations still
      cannot migrate, 74 of them for a reason nobody has designed for yet.
      DECIDED 2026-08-22 (Ruben, with the number in hand): KEEP THE PAGES, and
      design rule evaluation as flow steps FIRST. Removing them at 67.1% would
      strand ~79 live synchronizations without a working generated flow. 3.4
      stays open, but it is no longer waiting on a measurement — it is waiting
      on the `actions` design, which is now the single blocking piece of work
      for this whole change.
      AND THAT DESIGN IS MUCH SMALLER THAN "RULE EVALUATION". Measured on the
      dev instance the same day, over all 240 synchronizations and all 61 rules:
        every one of the 74 blocked synchronizations references EXACTLY ONE
        rule, and all 74 of those rules are `fetch_file` / `after`
        rule types across the whole instance: fetch_file/after 59,
        authentication/before 1, (empty)/before 1
      So the blocker is not a general rule engine and not per-item branching —
      it is ONE MISSING STEP, `openconnector.fetch-file`, doing after the write
      what `SynchronizationService` does inside its own write path. The
      `authentication` rule is a `before` rule on a single synchronization and
      is a separate, smaller question.
      ⚠️ NO COVERAGE PROJECTION IS RECORDED HERE, DELIBERATELY. A fetch-file
      step is NECESSARY for all 74 but only SUFFICIENT for those whose ONLY
      refusal is `actions`, and refusals outnumber synchronizations (79 refusals
      across the tail, with a scatter summing to more than the non-`actions`
      remainder, so some synchronizations carry two). The last projection made
      here from a plausible-looking ratio was wrong by 30 points. Build the
      step, then RE-MEASURE all 240.
      THE STEP IS BUILT (#1526): `openconnector.fetch-file`, a thin adapter over
      `SynchronizationService::runFetchFileRule()` — the same code path the
      legacy engine runs, reached through a public seam that resolves the rule
      and checks its type rather than lifting 75 lines out of the legacy write
      path. It carries a rule REFERENCE, so a half-migrated instance keeps both
      engines acting on the same entity. The generator emits it after the
      `syncedId` step and no longer refuses `actions` wholesale; every other
      rule type, an unresolvable rule, and a `before`-timed fetch_file are still
      refused BY NAME.
      STILL TO DO HERE: re-measure all 240 once #1526 is deployed, and record
      the number that comes back — not one derived from it.
      THE SWEEP WAS ATTEMPTED 2026-08-22 AND COULD NOT RUN. #1526 was deployed
      (checkout moved to development, `occ upgrade` clean, `needsDbUpgrade`
      false, the command present), and then EVERY synchronization failed to be
      READ — before any refusal could be judged:
        occ:  SchemaNotInRegisterException — slug "application" is not carried
              by register "openconnector", raised while the caller had asked
              for "synchronization"
        HTTP: Register not found: 'openconnector'
      That was not this change. It is an OpenRegister defect: `setSchema()`
      leaves a pending raw ref on a SHARED service, `find()` calls
      `setRegister()` before setting its own schema, and the leftover ref is
      re-resolved inside a register that has nothing to do with it. Diagnosed,
      reproduced in a unit test that fails on development, fixed and merged as
      openregister#2790.
      ⚠️ THE FIX IS NOT YET DEPLOYED HERE, so the number is still unmeasured.
      The instance's openregister checkout carries 10+ unmerged commits from
      another session, and taking their working tree out from under them is not
      worth a measurement. Whoever deploys openregister development next
      unblocks this: the sweep is one loop over
      `occ openconnector:synchronization-to-flow <uuid> --json` across the 240.
      RECORD WHAT COMES BACK. Do not derive it, do not scale a sample — the two
      wrong numbers this file has already carried (8.3%, ~99%) both came from
      treating a partial or non-running measurement as a proportional one. A
      sweep that cannot READ its subjects measures nothing at all.
