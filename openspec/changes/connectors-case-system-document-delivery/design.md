# Design: connectors-case-system-document-delivery

Kind: code. Size M. Read at integriq development `966d6458` and filinq development `9fd042f8` on 2026-09-28.

## Context

- **filinq's outbound objects.** `caseSystemDelivery` (filinq `generate-store-in-case-system` D1, D2, D6) carries the stored file reference, `informatieobjecttype`, `vertrouwelijkheidaanduiding`, `bronorganisatie`, the destination `sourceId`, an optional `zaakUrl`, and `titel`, `bestandsnaam`, `formaat`, `taal`, `creatiedatum`, `auteur`; states `ready_for_writeback`, `written_back`, `writeback_failed`. The redacted copy (filinq `zgw-document-bridge` REQ-DDZGW-005) is an `externalDocument` with `processingStatus` and `resultFileRef`.
- **Push synchronizations.** A synchronization with `sourceType: register/schema` is triggered by that object and pushes to an outside source (`synchronization-engine` REQ-001); nothing writes the result onto the triggering object.
- **ZGW.** `InformatieObjectTranslator` translates informatieobject versions and states that chunked upload (`bestandsdelen`) is not handled (its header, :10). `zgw-documenten.json` names `zgw-documenten-push` and `object-to-zgw-document` without defining them.
- **StUF.** `StufZknClient::send()` sends an envelope with mTLS and extracts the reference; the outbound translator builds `zakLk01` only.

## D1. Outcome write-back is a synchronization setting

A push synchronization gains `writeBack: { onSuccess: { field: value-or-template }, onFailure: { ... } }`, evaluated against the target's answer (for example `{"processingStatus": "written_back", "resultExternalId": "{{ response.url }}"}` and `{"processingStatus": "writeback_failed", "writeBackError": "{{ error.message }}"}`). The engine writes these fields onto the source object after the attempt, through OpenRegister, without re-triggering the push (the write carries the event loop marker). A failure that will be retried writes nothing until the retry budget is spent.

Alternative considered: a flow step that writes back. Rejected: every push that hands something outside needs it, and the synchronization already knows the source object and the outcome.

## D2. The ZGW leg

`object-to-zgw-document` maps the delivery to an EnkelvoudigInformatieObject (`bronorganisatie`, `creatiedatum`, `titel`, `auteur`, `taal`, `formaat`, `bestandsnaam`, `informatieobjecttype`, `vertrouwelijkheidaanduiding`). The push creates it; when the Documenten API answers with `bestandsdelen`, it uploads the file in those parts and unlocks the object, else it sends the content inline. With `zaakUrl` set, it creates a ZaakInformatieObject on the Zaken API of the same source set. `resultExternalId` is the informatieobject URL.

## D3. The StUF-ZDS leg

For a StUF-ZDS destination the push builds a `voegZaakdocumentToe` message (ZDS 1.2) with the same metadata and the file inline, sends it through `StufZknClient::send()`, and takes the document identificatie from the answer as `resultExternalId`.

## D4. Two seeded synchronizations

`filinq-case-system-delivery`: source filinq `caseSystemDelivery`, trigger on `status = ready_for_writeback`, target chosen by the delivery's `sourceId`, write-back as D1. `filinq-redacted-writeback`: source filinq `externalDocument`, trigger on `processingStatus = ready_for_writeback`, title suffix "(geanonimiseerd)" and a reference to the original's identificatie, write-back as D1. Both disabled until an administrator links the case system source.

## D5. The seeds use their own mapping slugs and ship unbound

Decided 2026-10-04 while building Task 4. `object-to-zgw-document` and `zgw-documenten-push` were seeded by zgw-connectors-for-dossiq after this change was written: a pass-through mapping and a PATCH write-back for documents that came from the case system. Repurposing them would break that set, so the delivery seeds its own mappings, `case-system-delivery-to-zgw-document` and `redacted-document-to-zgw-document`.

Both synchronizations ship with an empty `sourceId`, like the ZGW set pushes, and target the dormant `zgw-set-documenten` and `zgw-set-zaken` sources. "Disabled" means exactly that: nothing triggers them until an administrator picks the filinq schema, and the sources are off. A `conditions` group on the state field keeps every other state out. "Target chosen by the delivery's `sourceId`" (D4) is not built: one synchronization sends to one Documenten API.

The redacted copy's file is the id in `resultFileRef`, read through `targetConfig.zgwDocument.fileIdField`. The delivery's file is the first file attached to it, until filinq names its file field. An `externalDocument` carries no `bronorganisatie`, `auteur`, `taal` or `informatieobjecttype`; the mapping reads them from the object when present, and the administrator fills them in otherwise.

## D6. The StUF-ZDS leg asks the case system for the identificatie first

Decided 2026-10-05 while building Task 3. A ZDS 1.2 `voegZaakdocumentToe_Lk01` answer is a plain Bv03 and carries no identificatie; the document's identificatie is handed out beforehand by `genereerDocumentIdentificatie_Di02` (answer Du02). So the push sends both: Di02, then an `edcLk01` with that identificatie, the metadata in ZDS element order, the file inline as `inhoud` (`StUF:bestandsnaam`, `xmime:contentType`), and `isRelevantVoor` (EDCZAK) to the case by its identificatie. "The returned identificatie" in REQ-CSD-002 is the Du02's.

- A push chooses the leg with `targetConfig.stufDocument` (`documenttype` for `dct.omschrijving`, `zaakIdentificatieField`, and the file settings of `zgwDocument`), against a `stuf-zkn` source read raw like CallService reads a source for dispatch.
- The mapping stays in ZGW vocabulary; the translator converts dates (`20261005`) and the confidentiality value (upper case). One mapping serves both legs.
- `StufZknClient::exchange()` returns the answer and refuses a Fo03 with its `code` and `omschrijving`, which are written back. `send()` keeps its behaviour for `zakLk01`.
- A source in `log` mode is refused: the log provider returns a made-up reference, and writing that back would claim a document the case system does not have.
- The fixtures are shaped after the ZDS 1.2 message examples; no live StUF-ZDS endpoint was available, and the envelope is not validated against the StUF XSD set (not in the repo). The first run against a real case system settles it.

## Declarative versus imperative

| Behaviour | Path | Rationale |
|---|---|---|
| Which objects are pushed and what is written back | Declarative synchronization configuration | Configuration. |
| ZGW create, parts upload, relation; StUF message | Imperative protocol code | National standards belong in integriq (ADR-091 decision 6). |

## Seed data

The two disabled synchronizations and the mapping. A recorded Documenten API exchange (create, two parts, unlock) and a recorded StUF answer as test fixtures.

## Risks

- [A case system rejects the informatieobjecttype] the delivery ends `writeback_failed` with the case system's message, and filinq's panel offers the retry.
