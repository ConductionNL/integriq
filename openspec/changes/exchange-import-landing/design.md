# Design: exchange-import-landing

## Architecture Overview

```
ExchangeJobRunner::run(jobId)
  gate: ExchangeGateRequestedEvent -> owning app allow(records)   (unchanged)
  mapping row per record                                          (unchanged)
  ExchangeTargetDispatcher::dispatch(..., direction=import)
    -> ExchangeRecordsReceivedEvent -> owning app accept(count, rejected)
  runner stores rejections, ends the job from the answer
```

The raw records reach integriq the same way export records do: in the gate answer
(`allow(records)`). For an import the owning app holds the raw input (an uploaded migration
file, a received OSO dossier, an LVS export). Integriq maps it with the job's mapping row
(`learniq-lvs-results-import-uwlr`, `learniq-oso-import-dossier`,
`learniq-migration-import-*`) and hands the mapped records back. Integriq never stores them.

## The contract a learniq change answers

### Event

- Class: `OCA\Integriq\Event\ExchangeRecordsReceivedEvent` (extends `OCP\EventDispatcher\Event`).
- Dispatched with `IEventDispatcher::dispatchTyped()`. Register a listener with
  `IRegistrationContext::registerEventListener(ExchangeRecordsReceivedEvent::class, <Listener>::class)`.
  Guard with `class_exists()` so learniq runs without integriq.
- A listener MUST check `getOwnerApp() === 'learniq'` and return without answering otherwise.

### Payload (getters)

| getter | type | meaning |
|---|---|---|
| `getJobId()` | `string` | integriq job uuid |
| `getOwnerApp()` | `string` | owning app id, e.g. `learniq` |
| `getTarget()` | `string` | `lvs-results`, `oso` or `migration-import` |
| `getDirection()` | `string` | always `import` |
| `getOwnerRef()` | `string` | the owner's reference given when the job was requested, `''` when none |
| `getScope()` | `array<string, mixed>` | the job's scope as requested (selectors, `recordIds`, parameters) |
| `getRecords()` | `array<int, array{recordId: string, sourceKind: string, data: array<string, mixed>}>` | the records after the mapping row; `data` keys are the owner's field names |

When the scope has `recordIds`, only those records are handed over (same filter as exports).

### Answer

`accept(int $acceptedCount, array $rejected = []): void`

- `$rejected` is a list of `{recordId: string, errorCode: string, offendingFields?: string[]}`.
- `errorCode` is the owner's code (for example `LVS-UNKNOWN-PUPIL`). Codes the owner wants
  labelled go in an `learniq-exchange-error-codes-<target>` catalogue row; an uncatalogued code
  shows as the bare code.
- `offendingFields` names fields. NEVER a value: rejections are stored as dead letters and shown
  in the status panel.
- The first `accept()` counts; later calls are ignored. A rejection whose `recordId` was not
  handed over, or whose `errorCode` is empty, is dropped. The accepted count is clamped to
  `count(records) - count(rejected)` and to at least 0.
- Getters for integriq: `isAnswered()`, `getAcceptedCount()`, `getRejected()`.

### Outcome

| answer | job status | stored |
|---|---|---|
| accepted = handed over | `succeeded` | nothing |
| 0 < accepted < handed over | `partial` | one rejection per rejected record |
| accepted = 0 | `failed` | one rejection per rejected record |
| no `accept()` call, or the listener threw | `failed`, `exchangeError` = `no-owner-answer: ...` | nothing |
| zero records handed over and answered | `succeeded` (0 of 0) | nothing |

`ExchangeJobConcludedEvent` follows as for every run.

### No answer: why a new code

`no-handler` means integriq cannot run the target and direction; an administrator cannot fix it.
`no-owner-answer` means integriq handed the records over and the owning app stayed silent: the
app is too old, its listener is not registered, or it threw. That is the owning app's to fix, so
it gets its own code (catalogued in `learniq-exchange-error-codes-integriq`, severity
`blocking`). A job that ends this way can be re-requested once the listener exists.

### Timing

Synchronous and in-process, inside the job run (a background job, no user session). The listener
MUST finish its writes and then call `accept()` before returning. It runs as the background job
user: it MUST NOT rely on a session user and MUST use its own system-level write path. A
throwable escaping the listener is caught by integriq and ends the job `no-owner-answer`; the
listener's partial writes are not rolled back by integriq.

### Idempotency on retry

A job runs once: a job that is not `queued` does not run again. A resubmission or a new job for
the same input hands the same `recordId`s again. The owning app MUST upsert by
(`target`, `recordId`) or by its own natural key, so a second delivery changes nothing it
already took.

## Nextcloud Integration

- Events: new `ExchangeRecordsReceivedEvent`.
- Services: `ExchangeTargetDispatcher` gains `IEventDispatcher` and `LoggerInterface`;
  `ExchangeJobRunner` reads `acceptedCount` from the outcome when present.

## Security Considerations

Records carry personal data. They pass in memory only, as for exports. Rejections hold codes and
field names only (the event drops anything else). The listener's own authorisation applies to
what it writes.

## File Structure

```
lib/Event/ExchangeRecordsReceivedEvent.php            (new)
lib/Service/Exchange/ExchangeTargetDispatcher.php     (import landing)
lib/Service/Exchange/ExchangeJobRunner.php            (accepted count from the outcome)
lib/Settings/register.d/learniq-exchange-jobs.json    (no-owner-answer code, row 1.1.0)
tests/Unit/Event/ExchangeRecordsReceivedEventTest.php
tests/Unit/Service/Exchange/ExchangeTargetDispatcherTest.php, ExchangeJobRunnerTest.php
```

## Seed Data

No schema changes. The catalogue row `learniq-exchange-error-codes-integriq` gains
`no-owner-answer` and moves to version 1.1.0.

## Declarative-vs-imperative decision

Not applicable: an in-process integration hand-off between two apps (ADR-031 external
integration exception), no lifecycle, aggregation or notification.
