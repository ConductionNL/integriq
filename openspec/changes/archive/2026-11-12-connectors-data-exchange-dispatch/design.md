# Design: connectors-data-exchange-dispatch

Kind: code. Size M. Read at integriq `development` 92f282bc and learniq
`development`.

## Where it fits

| Piece | File | Today |
|---|---|---|
| learniq push caller | learniq `lib/Listener/DataExchangeRunHandler.php:134`, :614 | posts to a route that does not exist |
| learniq import caller | learniq `lib/Timetabling/TimetableImportHandler.php:126`, :218, :229 | same |
| ROD | `lib/Service/RodService.php:130` `sendBericht()`, route `appinfo/routes.php:201` | reached only over HTTP |
| Verzuimloket | `lib/Service/VerzuimloketService.php:125` `sendMelding()`, route :209 | same |
| OSO | `lib/Service/OsoService.php:130` `sendExport()`, route :218 | same |
| UWLR and Edu-V | `lib/Service/UwlrEduVService.php:136`, :162, :187, :212, routes :228-231 | same |
| SWV | `lib/Sources/Swv/SwvHandoffSourceAdapter.php:140` `handOffDossier()` | no caller |
| Rostering | `lib/Sources/Roster/RosterImportSourceAdapter.php:110` `importLessons()` | no caller |
| LVS | `lib/Sources/Lvs/UwlrResultImportSourceAdapter.php:113` `importResults()` | no caller |
| Event precedent | `lib/Event/DocumentRenderRequestedEvent.php`, `lib/AppInfo/Application.php:283-291` | the shape to copy |

## D1. A typed event, not an HTTP route

Neither side's path can simply be adopted: the route learniq calls never
existed, and integriq's per-adapter routes each take a different body. So
the choice was between a new HTTP route in the shape learniq posts, and an
ADR-041 typed event.

Chosen: the typed event.

- learniq's call already runs in-process, inside an `ObjectTransitionedEvent`
  listener on the same Nextcloud instance (`DataExchangeRunHandler.php:176`).
  An HTTP post from there is a loopback to the same server: it needs an API
  token stored in learniq's app config, a resolvable absolute URL, and a
  second PHP worker free for up to 120 seconds while the first one waits.
- ADR-041 decision 1: "Cross-app commands use typed `IEventDispatcher`
  events, not the integration registry, not server-side HTTP". It records
  that server-side HTTP between apps is how earlier delegations silently
  failed.
- Integriq already answers three sibling commands this way, and learniq
  already answers integriq events the same way on other paths.

Rejected: `POST /api/data-exchange/{target}/run` in learniq's body shape.
It would work, and it would keep the token, the loopback and the timeout,
and add a public-facing route whose only caller is the same server.

## D2. The contract learniq calls

`OCA\Integriq\Event\DataExchangeRequestedEvent extends OCP\EventDispatcher\Event`:

Constructor, all read-only:

- `sourceApp` (string): `learniq`.
- `jobId` (string): the `DataExchangeJob` uuid, used as correlation id.
- `target` (string): the job's `target`, such as `bron-rod`.
- `direction` (string): `export`, `import` or `sync`, as learniq's schema
  declares.
- `payload` (array): for an export, the records learniq built, the same
  array it posts today under `payload`.
- `scope` (array): for an import, the job's `scope`, the same array
  `TimetableImportHandler` posts today.
- `tenantId` (string).

Result slot, written by integriq:

- `setResult(array $result)` and `getResult(): ?array`, with the keys
  learniq reads at `DataExchangeRunHandler.php:351-370`: `runId`, `status`,
  `recordsProcessed`, `recordsAccepted`, `recordsRejected`,
  `validationReport`, `artefactRef`, plus `records` for an import, which
  `TimetableImportHandler.php:229` reads.
- `refuse(string $code, string $reason)` and `getRefusal(): ?array`, as
  `DocumentRenderRequestedEvent` has, for an unknown target, a disabled
  adapter or a missing source.
- `isHandled(): bool`, true once integriq wrote either.

learniq reads `getResult()` where it read the HTTP body, and treats a
refusal or an unhandled event as today's `null`: it fails the job with the
reason.

`OCA\Integriq\Event\DataExchangeConcludedEvent`, dispatched by integriq when
an acknowledgement for a job arrives: `sourceApp`, `jobId`, `target`,
`accepted` (bool), `signaalcode`, `description`, `receivedAt`. It is only
raised for a request that carried a `sourceApp`, per ADR-041.

## D3. The dispatcher

`lib/Service/DataExchange/DataExchangeDispatcher.php` holds a map from
target to handler, each handler a small class that adapts learniq's records
to one adapter:

| target | handler calls | records become |
|---|---|---|
| `bron-rod` | `RodService::sendBericht()` | one bericht per record, `berichtsoort` from the record |
| `leerplicht` | `VerzuimloketService::sendMelding()` | one melding per record |
| `oso` | `OsoService::sendExport()` | one export per dossier |
| `swv` | `SwvHandoffSourceAdapter::handOffDossier()` | one hand-off per dossier, receiver from the source |
| `uwlr`, `edu-v`, `basispoort`, `entree-content` | the matching `UwlrEduVService` send | one send per batch |
| `timetable-import` | `RosterImportSourceAdapter::importLessons()` | `records` in the result |
| `lvs-import-contract` | `UwlrResultImportSourceAdapter::importResults()` | `records` in the result |

The job id is passed as the `kenmerk` where an adapter takes one, so the
authority's acknowledgement names the job. For an import, the system comes
from `scope.systemId`; without it the dispatcher uses the single enabled
source of that family and refuses when there are several.

Per record isolation: one record that fails translation is counted as
rejected with its reason in `validationReport`, and the rest are sent, as
the synchronization engine does (REQ-008).

## D4. Acknowledgements become one concluded event

A listener on the four existing `*AcknowledgementReceivedEvent`s reads the
`kenmerk`, recognises a job id, and raises `DataExchangeConcludedEvent`. The
adapter events stay as they are for anyone listening to them now.

## D5. The action gates

The HTTP routes gate on actions such as `rod.push`, because anyone with a
session can reach them. The event is not an entry point a browser can reach:
it is raised by learniq's listener after learniq's own lifecycle guard
(`DataExchangeRunGuard`) allowed the job to run. The dispatcher still refuses
a target whose source is disabled or whose adapter's feature flag is off,
and records the refusal.

## Declarative versus imperative

Cross-app commands are imperative by nature and ADR-041 makes them typed
events. Nothing here is lifecycle on an OpenRegister object; learniq's job
lifecycle stays in learniq's schema.

## Seed data

No schema changes. The existing dormant source rows in `lib/sources.seed.json`
and the ROD, Verzuimloket, OSO and UWLR sources in mock mode are what a demo
dispatch reaches.

## Risks

- A large export sends many berichten inside one listener call. The adapters
  already record each send and retry failures from their own background jobs
  (`RodRetryJob`, `OsoRetryJob`, `VerzuimloketRetryJob`), so the listener only
  hands over and counts.
- Two apps editing one contract: the event's constructor and result keys are
  the contract. Changing them is a breaking change for learniq, stated in the
  event's docblock.
- learniq's half has to land for any row to move. Until it does, integriq's
  listener exists and nobody raises the event.

## D6. What was built elsewhere, and what D4 became (2 October 2026)

D1 to D3 and D5 were built by `learniq-exchange-jobs-native` in another shape: the job lives in
integriq, learniq raises `ExchangeJobRequestedEvent`, and `ExchangeTargetDispatcher` is the
D3 table. They are not built a second time.

D4 is built against that runner, not against a learniq-owned job:

- The kenmerk the dispatcher sends is `<jobId>:<recordId>`, so the listener splits it at the
  first colon and looks the job up with `ExchangeJobService::findJob()`. No separate record of
  dispatched job ids is kept: the job row is that record.
- The job's target must be one the acknowledging adapter carries (`ExchangeTargetCatalogue`
  adapter `rod`, `verzuimloket`, `oso` or `uwlr-eduv`). A ROD retour never touches an OSO job,
  even if a kenmerk collides.
- A rejecting retour becomes a rejection on the job through `ExchangeRejectionService::record()`
  with the signaalcode as `errorCode`. The record is already counted as sent; the retour is the
  authority's later no, and the correction loop (resubmit, waive) is where a person acts on it.
- The event is `ExchangeJobAcknowledgedEvent`, not the `DataExchangeConcludedEvent` of D2: the
  job concluded when its run ended (`ExchangeJobConcludedEvent`, REQ-009), and an
  acknowledgement is per record, possibly days later. It carries `recordId` for that reason.
- No owning app, no event: per ADR-041 a concluded-style event is only raised for a request an
  app made.

