# Design: learniq-exchange-jobs-native

Kind: code. Read at integriq `development` 413357ec9 and learniq `development` a84b6273.

## Where it fits

| Piece | File | Today |
|---|---|---|
| Scheduler | `lib/BackgroundJob/JobTask.php` (every 300 s) → `JobService::run()` → `executeJob()` | runs every enabled due `job`, resolves `jobClass` from the container, writes `job_log` |
| Job actions | `lib/Action/*Action.php`, `run(array $arguments): array` | `SynchronizationAction` and friends |
| Dead letters | `sync_item_dead_letter`, `SyncItemDeadLetterService` | per-object sync failures, manual replay and discard |
| Mappings | `mapping` rows, `MappingService::executeMapping()` | Twig or dot-path rules |
| ADR-041 precedent | `DocumentRenderRequestedEvent` + `DocumentRenderRequestedListener` | typed command with result slot |
| Adapters | `RodService::sendBericht()`, `VerzuimloketService::sendMelding()`, `OsoService::sendExport()`, `UwlrEduVService::send*()`, `SwvHandoffSourceAdapter::handOffDossier()` | reached only over their own HTTP routes, or not at all |

## D1. The job is a native `job` row with exchange tags

`register.d/learniq-exchange-jobs.json` adds to `job` (version 1.2.0 to 1.3.0):

| Property | Type | Why |
|---|---|---|
| `exchangeTarget` | string, enum of the 14 targets | filter and route |
| `exchangeDirection` | string, `export` `import` `sync` | route |
| `ownerApp` | string | whose gate to ask, whose read model row |
| `ownerRef` | string | the owning app's opaque reference (`attendance-flag/<uuid>`) |
| `exchangeScope` | object | selectors and target parameters, never personal data |
| `exchangeMapping` | string | slug of the `mapping` row |
| `exchangeStatus` | string, `queued` `running` `succeeded` `partial` `failed` `refused` | status |
| `gateDecision` | object `{decision, code, reason, checkedAt}` | why a run went or did not |
| `exchangeResult` | object `{recordsProcessed, recordsAccepted, recordsRejected, runId, artefactRef}` | counts |
| `requestedBy`, `requestedAt`, `startedAt`, `finishedAt` | string | audit |
| `resubmissionOf` | string | the dead letter a resubmission job retries |
| `migratedFrom` | string | the legacy id a migrated job came from |
| `exchangeError` | string | why a job failed, when it failed as a whole |

`jobClass` is `OCA\Integriq\Action\ExchangeJobAction`, `singleRun` true, `isEnabled` true,
`interval` 0. The scheduler already treats that as "run once on the next pass, then disable".
`arguments` stays empty; `JobService::executeJob()` gains one line that passes the job's uuid to
the action as `_jobId`, next to the `_executionTrace` it already threads through.

Rejected alternative: a new `exchange_job` schema. D7 says reuse the native schemas, and a
second job table would split the job list, logs and scheduler.

## D2. The gate is an event the owning app answers

`ExchangeGateClient::ask(array $job): array` returns `{decision, code, reason, checkedAt}` plus
the allowed records (not persisted):

1. `ownerApp` empty: refuse `gate-owner-missing`.
2. `IAppManager::isEnabledForUser($ownerApp)` false: refuse `gate-app-absent`, no dispatch.
3. Dispatch `ExchangeGateRequestedEvent`; a throw: refuse `gate-error`.
4. Not answered: refuse `gate-unanswered`.
5. Answered `refuse`: that code (fallback `gate-refused`) and reason.
6. Answered `allow`: allow, with the records.

The contract names `GET /apps/<app>/api/exchange-gates/{jobId}`. The owning app serves it with
the same evaluation, for people and support. The runner does not call it: `JobTask` runs from
cron with no session, so a server-side GET would 401 and every job would be refused forever,
the exact failure ADR-041's context section records. This is the one place this design departs
from a literal reading of the lane brief, and the PR says so.

## D3. The records travel in the gate answer, never on the job

`job` and `sync_item_dead_letter` are default-open (`SchemaAuthorizationRatchetTest::KNOWN_OPEN`).
Putting a pupil's name on either would make it readable to every account on the instance. The
owning app already decides "what may leave" (D7 lists it among the domain gates), so it hands the
allowed records over in the `allow()` answer. The runner maps them and passes them to the
adapter in the same PHP process. The adapters' own message rows keep what they already keep
(the ROD adapter stores a BSN hash, not the BSN).

A rejection stores `errorCode`, `offendingFields`, `ownerRef` and `sourceKind`. Its `error`
text is the catalogue label, not the target's message, which DUO fills with names and dates.

## D4. The runner

`ExchangeJobAction::run($arguments)` loads the job by `_jobId` and hands it to
`ExchangeJobRunner::run(ObjectEntity $job): array`:

1. Not an exchange job, or already terminal: return a WARNING, touch nothing.
2. `ExchangeTargetDispatcher::supports(target, direction)` false: fail `no-handler`.
3. `exchangeMapping` set and not found: fail `mapping-missing`.
4. Save `running` and `startedAt`.
5. Ask the gate (D2). Refused: save `refused`, conclude.
6. Scope `recordIds` set (a resubmission): keep only those records.
7. Map each record (`MappingService::executeMapping`); a throw becomes a `mapping-failed` rejection.
8. `ExchangeTargetDispatcher::dispatch()` returns `{accepted: [...], rejected: [{recordId, sourceKind, errorCode, offendingFields}]}`.
9. Record rejections (D6), compute the status (all accepted or nothing to send: `succeeded`;
   none accepted: `failed`; otherwise `partial`), save, conclude.

The return value is the `job_log` entry: `SUCCESS` for succeeded, `WARNING` for partial and
refused, `ERROR` for failed, with a one-line message.

## D5. The dispatcher

`ExchangeTargetDispatcher` holds one private method per handled target and injects the adapter
services directly, so every call site is visible to static analysis (gate 57).

| target | call | per-record parameter, record first then scope |
|---|---|---|
| `bron-rod` | `RodService::sendBericht($berichtsoort, $kenmerk, $data)` | `berichtsoort`, default `inschrijving` |
| `leerplicht` | `VerzuimloketService::sendMelding($meldingType, $kenmerk, $data)` | `meldingType`, default `eerste-melding` |
| `oso` export | `OsoService::sendExport($kenmerk, $data)` | |
| `swv` | `SwvHandoffSourceAdapter::handOffDossier($receiverId, $data)` | `receiverId`, required: `source-missing` |
| `uwlr` export | `UwlrEduVService::sendUwlrExport($kenmerk, $subtype, $data)` | `subtype`, default `pupil` |
| `edu-v` | `UwlrEduVService::sendEduVExport($kenmerk, $dataService, $data)` | `dataService`, default `onderwijsdeelnemers` |
| `basispoort` | `UwlrEduVService::syncBasispoort($kenmerk, $data)` | |
| `entree-content` | `UwlrEduVService::syncEntreeContent($kenmerk, $data)` | |

Kenmerk: `<jobId>:<recordId>`, so a later acknowledgement names both. A `*TranslationException`
becomes `translation-failed` with the field the message names; any other throw becomes
`send-failed`.

## D6. Rejections are dead letters

`register.d/learniq-exchange-jobs.json` adds to `sync_item_dead_letter` (1.0.0 to 1.1.0):
`exchangeJob` (uuid), `ownerApp` and `exchangeTarget` (copied from the job so a list filters
without a join), `errorCode`, `offendingFields` (array), `ownerRef`, `sourceKind`,
`correctionDeadlineAt` (date), `discardReason`, `correctedAt`, `correctedBy`.

`ExchangeRejectionService`:
- `record(job, rejection)`: writes a `failed` dead letter with `phase` `exchange`.
- `resubmit(id, actor)`: requires `failed`, creates a job copying target, direction, owner,
  mapping and scope with `recordIds: [originId]` and `resubmissionOf: id`, sets `replayed`.
- `reopen(id, errorCode, offendingFields)`: the resubmission rejected the record again: back to
  `failed`, attempt appended, `retryCount` up.
- `waive(id, actor, reason)`: non-empty reason, `discarded`, `discardedBy`, `discardedAt`.

`SyncItemDeadLetterService::replayMessage()` gains a first branch: a row with `exchangeJob` goes
to `ExchangeRejectionService::resubmit()` (resolved lazily from the container, like its existing
`getSynchronizationService()`), because re-running a synchronization it does not have would fail.

## D7. Vocabulary as seed rows

Error code catalogues are `mapping` rows keyed by code. Each value is
`{label, labelEn, category, severity}`. `ExchangeErrorCodeCatalogue::resolve(target, code)`
reads `learniq-exchange-error-codes-<target>` and then `learniq-exchange-error-codes-integriq`,
and falls back to the bare code. They are never passed to `MappingService`.

`learniq-exchange-rejection-status` is a real value mapping (old status to new) and is what the
migration listener applies to `history.rejections[].status`.

### The 23 mapping slugs (shared with learniq's `data-exchange-to-integriq`)

| learniq DataMappingProfile seed | slug |
|---|---|
| BRON/ROD learner export | `learniq-bron-rod-export-learner` |
| OSO transfer dossier | `learniq-oso-export-dossier` |
| Leerplicht notification export | `learniq-leerplicht-export-melding` |
| SWV zorgvraag dossier | `learniq-swv-export-zorgvraag` |
| Zermelo timetable import | `learniq-timetable-import-zermelo` |
| Untis timetable import | `learniq-timetable-import-untis` |
| Xedule timetable import | `learniq-timetable-import-xedule` |
| TimeEdit timetable import | `learniq-timetable-import-timeedit` |
| LVS results import | `learniq-lvs-results-import-uwlr` |
| OSO overstapdossier import | `learniq-oso-import-dossier` |
| UWLR pupil export | `learniq-uwlr-export-pupil` |
| UWLR group export | `learniq-uwlr-export-group` |
| UWLR teacher export | `learniq-uwlr-export-teacher` |
| UWLR results import | `learniq-uwlr-import-results` |
| Edu-V Onderwijsdeelnemers | `learniq-edu-v-export-onderwijsdeelnemers` |
| Edu-V Onderwijsgroepen | `learniq-edu-v-export-onderwijsgroepen` |
| Edu-V Onderwijsmedewerkers | `learniq-edu-v-export-onderwijsmedewerkers` |
| Basispoort sync | `learniq-basispoort-sync-learner` |
| Entree content hand-off | `learniq-entree-content-sync-learner` |
| ParnasSys migration import | `learniq-migration-import-parnassys` |
| ESIS migration import | `learniq-migration-import-esis` |
| Magister migration import | `learniq-migration-import-magister` |
| SOMtoday migration import | `learniq-migration-import-somtoday` |

Conversion rules: for an export the key is the target field and the value the learniq field
(`"voornamen": "givenName"`); for an import the key is the learniq field and the value the
external field. `date-iso8601` becomes `{{ field|date('Y-m-d') }}` (export) or
`{{ field|date('c') }}` (import). `bsn-to-pseudonym` maps from `eckId`, never from
`bsnEncrypted`. `cohort-to-brin` maps from `schoolBrin`, which the owning app resolves in its
gate answer. `passThrough` is false on all of them.

## D8. Read model

`ExchangeReadModel` reads `job` rows filtered on `ownerApp` (and `exchangeTarget`,
`exchangeStatus`, `ownerRef`), bounded to 200, and enriches each with the open rejection count
(one bounded query on `sync_item_dead_letter` filtered on `ownerApp` and `failed`, counted per
job). The detail read adds the job's rejections and its latest `job_log` line; the list does not,
to keep a page at two queries. `ExchangeController` serves the six routes with `#[NoAdminRequired]` and an
`ActionAuthService::requireAction()` call as the first statement after the session check.

## Declarative versus imperative

| Behaviour | Path | Why |
|---|---|---|
| Job status | imperative (runner writes `exchangeStatus`) | a cross-app, external integration run (ADR-031 exception: external integration) |
| Gate | imperative, typed event | cross-app command (ADR-041) |
| Rejection lifecycle | imperative service on `sync_item_dead_letter` | the dead letter schema already has an imperative replay and discard service; a declared lifecycle next to it would be two sources of truth |
| Open rejection count | computed in the read model | spans two schemas; OpenRegister aggregations count within one |
| Notifications | none here | the owning app notifies from `ExchangeJobConcludedEvent` |

## Seed Data

The fragment seeds rows only for `mapping`: the 23 learniq mappings above, one status
translation, and four error code catalogues (`bron-rod` BRON-101, BRON-102, BRON-201, BRON-205;
`oso` OSO-301; `leerplicht` LP-401; `integriq` the eleven runner codes of the contract). No
seed `job` or `sync_item_dead_letter` rows: an exchange job without an owning app would only
ever be refused, and a dead letter without a job is meaningless. The DUO codes are illustrative
starter data, as they were in learniq; DUO owns the real list.

## Trade-offs

- Mapping rows as code tables bend the `mapping` schema. The alternative, a new `code_list`
  schema, contradicts D7's "reuse the native schemas". Revisit when
  `mapping-formats-and-lookups` ships `lookup()` against a register schema.
- `bsn-to-pseudonym` is kept as learniq wrote it: the ROD mapping fills DUO's `bsn` field from
  the ECK iD, because learniq never releases the BSN (its payload builder strips
  `bsnEncrypted`). DUO identifies a pupil by BSN, so live ROD traffic needs a learniq privacy
  decision first. That is a gate decision in learniq, not a mapping change, and it waits on the
  DUO certificate anyway (D9).
- The SWV hand-off client had no container binding, so nothing could build the SWV facade. This
  change binds `SwvHandoffClient` to its dormant mock, the only implementation that exists.
- The migration presets for ParnasSys, ESIS, Magister and Somtoday already exist as column
  mappings for the file migration engine (`lib/migration-mapping-presets.seed.json`). The
  `learniq-migration-import-*` rows are the field mapping learniq owned; they do not replace the
  column presets.

## Risks

See the proposal. One more: a job left `running` by a PHP fatal stays `running`. The runner
treats a `running` job it is asked to run again as terminal-safe: it returns a WARNING and does
not run twice. An administrator re-queues it from the jobs screen.
