---
kind: code
depends_on: []
---

# Proposal: learniq-exchange-jobs-native

## Summary

Integriq becomes the home of learniq's data exchange jobs. A learniq job for DUO ROD, OSO, the
Verzuimloket, UWLR, Edu-V, Basispoort, an SWV or any of the other learniq targets is now an
integriq `job` row tagged with its target and direction, run by integriq's own scheduler, mapped
by an integriq `mapping` row, and logged in integriq's `job_log`. A rejected record is an
integriq `sync_item_dead_letter` row with a correction loop (resubmit, waive). Before a job runs,
integriq asks the app that owns it whether it may run, and the owning app answers allow or
refuse with a reason. Apps read their jobs' status through one read model.

## Motivation

Decision D7 (Ruben, 2026-09-27) moves data exchange out of learniq: "jobs, mapping profiles,
rejections and error codes use integriq's own job, mapping, synchronization and log schemas.
Learniq keeps the domain gates (consent, statutory completeness, what may leave) and a
read-only status panel." D25 (same day) says it is built now, in round 3.

Today learniq declares `DataExchangeJob`, `DataMappingProfile`, `ExchangeRejection` and
`ExchangeErrorCode` in its own register and posts a finished payload to
`/apps/openconnector/api/sources/{target}/run`, a route integriq never served
(`openspec/changes/connectors-data-exchange-dispatch/proposal.md`, "What learniq calls
today"). So every learniq exchange fails, and the eight adapters merged on 2026-09-26/27
(ROD #2176, Verzuimloket #2181, OSO #2182, UWLR and Edu-V #2183, SWV #2179, LVS #2160,
rostering #2166, SLO #2198) have no native way to be driven by learniq.

Integriq lacks four things to carry these jobs natively:

1. A way to tag a job with a learniq target and direction, and a runner that knows what to do
   with it.
2. The rejection and error code vocabulary learniq carried.
3. A gate hook: learniq's domain gates (OSO parent review, partner approval, teldatum
   confirmation, consent, statutory completeness, what may leave) must still decide whether a
   job runs, now that the job lives elsewhere.
4. A read model an app can query for its own jobs.

Corpus: `market-intelligence/learniq/_round1/compare/decisions.md` D3, D7, D9, D25;
`_round1/compare/change-plan.md` section "integriq (8 changes)" rows 115 to 122; the
five learniq contract changes merged on 2026-09-27 (`lvs-import-contract`,
`oso-inbound-contract`, `uwlr-eduv-basispoort-contract`, `entree-surfconext-sso-contract`,
`data-mapping-profile-presets`), whose `DataMappingProfile` seeds become this change's mapping
seeds.

## Affected Projects

- [x] Project: `integriq`: job schema tags, dead letter correction fields, mapping seeds, the
  exchange runner, the gate client, three typed events, the read model and its routes.
- [ ] Project: `learniq`: consumer. Its change `data-exchange-to-integriq` retires the four
  schemas, answers the gate and reads the read model. Built by the same lane, in its own PR.

## Scope

### In Scope

- Extend the native `job` schema with exchange tags: `exchangeTarget` (the fourteen learniq
  targets), `exchangeDirection`, `ownerApp`, `ownerRef`, `exchangeScope`, `exchangeMapping`,
  `exchangeStatus`, `gateDecision`, `exchangeResult` and the request and run timestamps.
- Extend the native `sync_item_dead_letter` schema so it carries an exchange rejection:
  `exchangeJob`, `errorCode`, `offendingFields`, `ownerRef`, `sourceKind`,
  `correctionDeadlineAt`, `discardReason`.
- Seed the vocabulary as integriq rows: error code catalogues per target and the rejection
  status translation, as `mapping` rows; the 23 learniq mapping profiles as `mapping` rows
  with stable `learniq-*` slugs.
- `ExchangeJobAction`, the job class that runs an exchange job: resolve the handler, ask the
  gate, apply the mapping, hand the records to the adapter, record rejections, conclude.
- The gate contract: the owning app answers `ExchangeGateRequestedEvent` in process, and serves
  the same decision at `GET /apps/<app>/api/exchange-gates/{jobId}`. Integriq fails closed when
  the owning app is absent, not enabled, silent or throws.
- `ExchangeJobRequestedEvent` (an app asks integriq to carry a job, also used to migrate old
  rows with their history) and `ExchangeMappingRequestedEvent` (an app upserts its own mapping).
- `ExchangeJobConcludedEvent` when a job reaches a terminal state.
- Handlers for the export targets whose adapters exist: `bron-rod`, `leerplicht`, `oso`
  (export), `swv`, `uwlr`, `edu-v`, `basispoort`, `entree-content`.
- The read model: `GET /api/exchange/jobs`, `GET /api/exchange/jobs/{id}`,
  `GET /api/exchange/rejections`, `GET /api/exchange/targets`, plus
  `POST /api/exchange/rejections/{id}/resubmit` and `.../waive`, each behind an ADR-023 action.

### Out of Scope

- Import landing. `timetable-import` goes to planninq under D10 (lane r3-rostering), and
  `lvs-results`, the OSO import direction and `migration-import` need a records hand-back to
  the owning app that learniq has no landing code for yet. Their jobs are accepted, tagged and
  gated, and conclude `failed` with the code `no-handler` until that follow-up exists.
- `surfconext`, `hr` and `ooapi-catalog`: no adapter exists (`ooapi-catalog` is OpenCatalogi's).
  Same `no-handler` outcome.
- Acknowledgement correlation: turning a later ROD, OSO, Verzuimloket or UWLR retour into a
  rejection on the job. The kenmerk this change sends (`<jobId>:<recordId>`) makes it possible;
  `connectors-data-exchange-dispatch` D4 owns the listener.
- Live wire traffic. Every adapter keeps its own feature flag and certificate gate (D9).

## Approach

Everything rides on integriq's existing job machinery: `JobTask` runs every enabled due `job`
every five minutes through `JobService::executeJob()`, which resolves `jobClass` from the
container and writes a `job_log` row. An exchange job is a single-run job whose `jobClass` is
`OCA\Integriq\Action\ExchangeJobAction`. Cross-app calls are typed events per ADR-041, never
server-side HTTP: the gate is an event the owning app answers, and the HTTP route of the same
name is the owning app's read surface for people. Schema changes are one ADR-037 fragment.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/learniq-exchange-jobs.json` (new fragment, additive).
- `lib/Service/JobService.php`: one argument (`_jobId`) passed to job actions.
- `lib/Service/SyncItemDeadLetterService.php`: replay of an exchange rejection resubmits it.
- `lib/actions.seed.json`: `exchange.read`, `exchange.resubmit`, `exchange.waive`.
- `appinfo/routes.php`, `lib/AppInfo/Application.php`: six routes, two listeners.
- New code under `lib/Action/`, `lib/Event/`, `lib/EventListener/`, `lib/Controller/`,
  `lib/Service/Exchange/`.

## Cross-Project Dependencies

- learniq `data-exchange-to-integriq` consumes every contract in `contract.md`. It lands after
  this change; until then nothing raises the events and the new code is idle.
- `connectors-data-exchange-dispatch` (proposal, 2026-09-27) designs the same target to adapter
  routing for a learniq-owned job. D7 moved the job into integriq, so its learniq half is
  superseded; its D4 acknowledgement listener still applies and plugs into this runner.

## Risks

### Risk 1: A gate over HTTP would fail every job
**Severity:** High. **Mitigation:** the runner never calls the owning app over HTTP. A cron run
has no user session, so a server-side GET to `/apps/learniq/api/exchange-gates/{id}` would be
refused and every job would fail closed forever (the defect class ADR-041 records). The runner
dispatches `ExchangeGateRequestedEvent`; the HTTP route serves the same decision to people.

### Risk 2: Pupil data stored in a world-readable schema
**Severity:** High. **Mitigation:** `job` and `sync_item_dead_letter` are default-open
(`SchemaAuthorizationRatchetTest::KNOWN_OPEN`). The payload therefore never lands on the job:
the owning app hands the records over in the gate answer, in process, and they go straight to
the adapter. A rejection stores the code, the field names and an opaque `ownerRef`, never the
record or the target's free-text message.

### Risk 3: Mapping rows used as code tables
**Severity:** Low. **Mitigation:** the error code catalogues are `mapping` rows whose values are
objects, read by `ExchangeErrorCodeCatalogue`, never executed as a transformation. Each row's
description says so.

## Rollback Strategy

Revert the PR. The fragment is additive: removed properties stay in stored objects and are
ignored. Exchange jobs already created stay as disabled single-run jobs whose `jobClass` no
longer resolves, which `JobService` logs as an error without affecting other jobs.

## Open Questions

- Who may read the exchange status in learniq's panel. `exchange.read` defaults to `admin`
  like every other integriq action; an administrator grants learniq's coordinator groups in
  Admin settings > Integriq > Action authorization. Recorded as an assumption in the PR.
