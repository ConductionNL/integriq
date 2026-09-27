---
kind: code
depends_on: []
---

# Proposal: connectors-data-exchange-dispatch

## Summary

learniq runs every data exchange by posting to
`/apps/openconnector/api/sources/{target}/run` on its own instance. Integriq
serves no such route, under either app name, so every job fails. The
adapters that would carry the traffic (ROD, Verzuimloket, OSO, UWLR, SWV,
rostering and LVS) are in integriq, some behind their own routes with their
own payload shapes and some with no caller at all. This change gives learniq
one contract to call: a typed `DataExchangeRequestedEvent` that integriq
answers by routing each target to its adapter, with a synchronous result
slot and a concluded event when the authority's acknowledgement arrives.

## Why

This change covers four rows, all from sibling matrices.

- `learniq:gov-push-data-to-another-system`, "Push learner data to a
  national register or another system", rated no and none for learniq with
  integriq as owner. Two competitors rate it yes:
  - totara: https://totara.help/docs/what-are-webhooks "Webhooks allow your
    Totara site to automatically send information to another system when
    something happens".
  - ispring-learn: https://ispringhelpdocs.com/ispring-learn/rest-api-10684924.html
    "The REST API provides access to most iSpring LMS functions"; webhooks
    at https://ispringhelpdocs.com/ispring-learn/webhook-62863671.html.
  The decision: "Learniq's data-exchange job calls api/sources/{target}/run
  on integriq, which integriq does not serve, so every run fails. The ROD,
  Verzuimloket, OSO and UWLR adapters have routes of their own in their own
  payload shapes; the rostering, SWV and LVS adapters have no caller at
  all." (Corrected after this change's code reading; the first wording said
  no adapter had a trigger.)
- `planninq:sib-learniq-att-import-a-timetable`, "Import a timetable from
  your scheduling software". Two competitors rate it yes:
  - untis: https://untisroostersoftware.eu/webuntis-home/ and
    https://www.untis.at/warum-untis/ueber-das-produkt/schnittstellen
    "Durch unsere XML-Schnittstelle können wir auch flexibel Import- und
    Export-Funktionalitäten zur Verfügung stellen".
  - timeedit: https://www.academy.timeedit.com/product-updates/198856754 "It
    is now possible to import reservations with Capacity and Size on them.
    This works across XML importer and both SOAP and REST API".
  The decision: "The roster adapter exists (integriq-adapter-rostering-imports)
  but learniq's import calls api/sources/timetable-import/run, which
  integriq does not serve."
- `learniq:att-import-a-timetable`, the same capability in learniq's matrix.
  It rides with the planninq row.
- `learniq:att-report-absence-to-authority`, "Report persistent absence
  onward to the authority". It rides with `learniq:gov-push-data-to-another-system`:
  learniq's `AttendanceFlagReportGuard` gates the report, which travels as a
  `leerplicht` data-exchange job over the same broken path.

No demand row for any of them.

## What learniq calls today

Read on learniq `development`:

- `lib/Listener/DataExchangeRunHandler.php:134`
  `OPENCONNECTOR_RUN_PATH = '/apps/openconnector/api/sources/%s/run'`.
  `callOpenConnector()` (:614) posts `{"payload": [...]}` with a Bearer
  token from `learniq.openconnector_api_token` and a 120 second timeout, and
  expects `{runId, status, recordsProcessed, recordsAccepted,
  recordsRejected, validationReport, artefactRef}` (:86-90, used at :351-370).
  It runs inside an `ObjectTransitionedEvent` listener when a
  `DataExchangeJob` moves to `running` (:176-205).
- `lib/Timetabling/TimetableImportHandler.php:126`
  `OPENCONNECTOR_RUN_PATH = 'api/sources/%s/run'` with target
  `timetable-import`, posting `{"scope": ...}` (:218) and reading
  `records` from the answer (:229).
- Both files carry a comment, verified by learniq against integriq in
  September, that the route never existed and that correcting the app name
  alone would only change a 404 into a different failure.

## What integriq already has

- Push routes with their own shapes: `POST /api/rod/berichten`
  (`appinfo/routes.php:201`, `{berichtsoort, kenmerk, payload}`),
  `POST /api/verzuimloket/berichten` (:209), `POST /api/oso/export` (:218)
  and four UWLR and Edu-V routes (:228-231), each behind an action gate such
  as `rod.push` (`lib/Controller/RodController.php:102`).
- Services behind them: `RodService::sendBericht()`
  (`lib/Service/RodService.php:130`), `VerzuimloketService::sendMelding()`
  (`lib/Service/VerzuimloketService.php:125`), `OsoService::sendExport()`
  (`lib/Service/OsoService.php:130`) and the four `UwlrEduVService` sends.
- Adapters with no caller at all: `RosterImportSourceAdapter::importLessons()`
  (`lib/Sources/Roster/RosterImportSourceAdapter.php:110`),
  `SwvHandoffSourceAdapter::handOffDossier()`
  (`lib/Sources/Swv/SwvHandoffSourceAdapter.php:140`) and
  `UwlrResultImportSourceAdapter::importResults()`. `git grep` finds each only
  in its own file and `lib/sources.seed.json`.
- Acknowledgement events per adapter: `RodAcknowledgementReceivedEvent`,
  `VerzuimloketAcknowledgementReceivedEvent`, `OsoAcknowledgementReceivedEvent`
  and `UwlrEduVAcknowledgementReceivedEvent` in `lib/Event/`.
- The ADR-041 precedent: `DocumentRenderRequestedEvent`,
  `DeliveryRequestedEvent` and `DigitalPostSendRequestedEvent`, registered in
  `lib/AppInfo/Application.php:283-291`.

## What this change builds

1. `OCA\Integriq\Event\DataExchangeRequestedEvent`: provenance, target,
   direction, job id, payload or scope, and a result slot in the shape
   learniq already reads.
2. A listener and a dispatcher that route each target to its adapter:
   `bron-rod`, `leerplicht`, `oso`, `swv`, the UWLR and Edu-V targets,
   `timetable-import` and the LVS import.
3. `OCA\Integriq\Event\DataExchangeConcludedEvent`, raised when an
   authority's acknowledgement for a job arrives, carrying the job id and the
   outcome.
4. A refusal in the result slot for a target integriq has no adapter for,
   naming the target, instead of a silent failure.

## Out of scope

- The adapters' live wire bindings. Each keeps its own feature flag and
  certificate gate (the DUO software-vendor certificate for ROD, Verzuimloket
  and OSO).
- Targets integriq has no adapter for: `surfconext`, `hr` and `ooapi-catalog`
  get the refusal. `ooapi-catalog` is OpenCatalogi's.
- The existing HTTP routes. They stay for other callers.

## Sibling half

learniq's half, which this change does not build:

- In `DataExchangeRunHandler::callOpenConnector()` and
  `TimetableImportHandler::callOpenConnector()`, replace the HTTP post with:
  a `class_exists('OCA\Integriq\Event\DataExchangeRequestedEvent')` check that
  fails the job closed when integriq is absent, `dispatchTyped()` of the event,
  and a read of its result slot. The `openconnector_api_token` setting and
  the `OPENCONNECTOR_RUN_PATH` constants go away.
- A listener on `DataExchangeConcludedEvent` that filters on
  `getSourceApp() === 'learniq'` and records the authority's outcome on the
  job, idempotently.
- `lib/Settings/connections.json` entries `data-exchange` and `timetable`
  lose their `available: false`.
