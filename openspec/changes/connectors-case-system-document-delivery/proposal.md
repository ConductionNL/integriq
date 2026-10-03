---
kind: code
depends_on: []
---

# Proposal: connectors-case-system-document-delivery

## Summary

filinq records every generated or anonymised document that belongs in the municipality's case system as a delivery waiting in `ready_for_writeback`, and nothing picks it up: integriq has no push that creates a document in a case system. This change adds it. integriq watches filinq's deliveries, creates the document in the case system over the ZGW Documenten API or StUF-ZDS, uploads the file, relates it to the case when one is named, and writes the outcome back onto the delivery. The write-back of an outcome onto the object that started a push is a general engine feature other pushes can use.

## Why

The owner-moves pass of 2026-09-28 handed this half to integriq from two filinq changes on filinq `development` that share one contract. It is `build` by the decision rule, and its rows carry tender demand.

- filinq `generate-store-in-case-system`, Cross-app dependencies: "integriq: a push synchronisation on `caseSystemDelivery` objects in `ready_for_writeback` that creates an EnkelvoudigInformatieObject (ZGW Documenten API) or a StUF-ZDS document with the delivery's metadata, uploads the file, relates it to the case when `zaakUrl` is set, and sets `written_back` with `resultExternalId`, or `writeback_failed` with `writeBackError`."
- filinq `zgw-document-bridge`, REQ-DDZGW-005: "OpenConnector's push synchronization performs the external write and records the outcome by setting `processingStatus = written_back` + `resultExternalId` (success) or `writeback_failed` + `writeBackError` (failure)", as a new informatieobject titled with the suffix "(geanonimiseerd)".

Rows:

- humaniq `td-dms-link`, "Store generated HR documents automatically in the organisation's document management system." Tender demand: Delft Support E4 and E22, "koppeling met het DMS, STUF-ZDS of NEN-ISO 16175".
- filinq `con-zgw`, "Exchange documents with a zaaksysteem through the ZGW Documenten API." SmartDocuments ("Zaaksysteem integrations: Corsa, Verseon, Decos, Centric, PinkRoccade native couplings") and Decos JOIN ("Documenten API-standaard v1.x", https://www.softwarecatalogus.nl/pakket/join-zaak-en-document) rate yes.

ADR-091 decision 6 puts ZGW and StUF shapes in integriq, and filinq's bridge records "No ZGW/StUF client code in Filinq".

## What integriq already has

- Intern-to-extern push synchronizations triggered by an object in a register and schema (`openspec/specs/synchronization-engine/spec.md` REQ-001), with a trigger condition.
- ZGW version translation for informatieobjecten (`lib/Service/ZgwVersion/InformatieObjectTranslator.php`), which does not handle chunked upload (`bestandsdelen`).
- The ZGW connector configuration `lib/Settings/configurations/zgw-documenten.json` from the open change `zgw-connectors-for-dossiq`. It names a synchronization `zgw-documenten-push` and a mapping `object-to-zgw-document` (:20, :24) that are defined nowhere; its write-back (REQ-ZGWC-003) pushes edits of documents that came from the case system and sets a conflict, and does not create a new document from a queued delivery.
- A StUF-ZKN client (`lib/Service/StufZkn/StufZknClient.php`, `send()` at :196) with mTLS, whose outbound path sends `zakLk01` only (`openspec/specs/stuf-zkn-bridge/spec.md` REQ-003).
- No write-back of a push outcome onto the source object: `SynchronizationService` stamps outcomes on target objects only.

## What this change builds

1. Outcome write-back: a push synchronization may declare fields on the source object to set on success (a status and the external id) and on failure (a status and the error), written once per attempt.
2. The ZGW leg: create an EnkelvoudigInformatieObject with the delivery's metadata, upload the file (in parts when the Documenten API asks for it), and relate it to the case with a ZaakInformatieObject when `zaakUrl` is set.
3. The StUF-ZDS leg: the document sent as `voegZaakdocumentToe` over the existing StUF client.
4. Seeded synchronizations for filinq's `caseSystemDelivery` and for the redacted `externalDocument` write-back, which define `zgw-documenten-push` and `object-to-zgw-document`.

## Out of scope

- Deciding which document goes where, and retries from filinq's panel (filinq).
- Updating or replacing a document already in the case system.
- NEN-ISO 16175 file export.

## Impact

- Changed: `lib/Service/SynchronizationService.php` (outcome write-back), `lib/Service/ZgwVersion/InformatieObjectTranslator.php` (parts upload), `lib/Service/StufZkn/` (document message), `lib/Settings/configurations/zgw-documenten.json`.
- New: seeded mappings and synchronizations for the two filinq outbound objects.

## Cross-project dependencies

- filinq `generate-store-in-case-system` and `zgw-document-bridge` define the objects and their states. humaniq renders with output mode `both` so filinq files and delivers its documents.

## Risks

- A document created twice after a timeout. Before creating, the push looks for an informatieobject with the delivery's identificatie at the same bronorganisatie and reuses it.
