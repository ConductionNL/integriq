<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# Documents to the case system

Filinq can send a document to your case system, such as Open Zaak. Integriq makes the call. You link two synchronizations once, and every waiting document goes out on its own.

This page is for the administrator who links them.

## The two synchronizations

| Synchronization | Sends | Source schema | State field | Mapping |
|---|---|---|---|---|
| `filinq-case-system-delivery` | a document filinq generated and stored | filinq `caseSystemDelivery` | `deliveryStatus` | `case-system-delivery-to-zgw-document` |
| `filinq-redacted-writeback` | an anonymised copy of a document that came from the case system | filinq `externalDocument` | `processingStatus` | `redacted-document-to-zgw-document` |

Both ship switched off. They have no source schema yet, and the sources they send to are off.

## What happens to a document

1. Filinq puts the object in `ready_for_writeback`. Objects in any other state are skipped.
2. Integriq creates a new document in the Documenten API.
3. When the API asks for parts, integriq uploads the file in those parts and unlocks the document. A Documenten API 1.0 takes the file in one go instead: set `inline` to `true`.
4. When the object has a `zaakUrl`, integriq relates the document to that case on the Zaken API.
5. Integriq writes the outcome onto the object. Success sets the state to `written_back` and `resultExternalId` to the document's address. Failure sets `writeback_failed` and `writeBackError` to the case system's message.

Integriq never updates, versions or replaces a document in the case system. A retry in filinq moves the object back to `ready_for_writeback`, and integriq creates the document then.

## Link the synchronizations

1. Set up the ZGW sources `zgw-set-documenten` and `zgw-set-zaken`: address, client id and secret. See [ZGW consumer sets](zgw-sets.md), steps 1 to 4.
2. Open `filinq-case-system-delivery` and choose filinq's register and its `caseSystemDelivery` schema as the source.
3. Open `filinq-redacted-writeback` and choose filinq's `externalDocument` schema as the source.
4. Open the mapping `redacted-document-to-zgw-document`. Replace the values for `bronorganisatie`, `auteur`, `taal` and `informatieobjecttype` with fixed values for your case system. An anonymised copy does not carry them, and the Documenten API refuses a document without them.
5. Turn both sources on.

## Where the file comes from

| Synchronization | File |
|---|---|
| `filinq-case-system-delivery` | the first file attached to the delivery |
| `filinq-redacted-writeback` | the file whose id is in `resultFileRef`, set in `targetConfig.zgwDocument.fileIdField` |

An object without its file is not sent. It reads `writeback_failed` with the reason.

## What the anonymised copy looks like

The title ends in "(geanonimiseerd)". The description names the original document and the date of processing. The copy is related to a case only when the object carries a `zaakUrl`.

## Send to a StUF-ZDS case system

A case system that speaks StUF-ZDS instead of the ZGW APIs gets the same document in two messages, as ZDS 1.2 describes:

1. `genereerDocumentIdentificatie`: the case system hands out the document's identificatie.
2. `voegZaakdocumentToe`: integriq adds the document with that identificatie to the case, with the file in the message.

The identificatie is written back. Use `{{ response.identificatie }}` in the write-back, for example `"resultExternalId": "{{ response.identificatie }}"`. A fault from the case system is written back as `writeback_failed`, with its code and message in `writeBackError`.

To send a synchronization over StUF-ZDS:

1. Set up a source of type `stuf-zkn` with `configuration.provider` set to `rest`, `baseUrl`, your own `organisatie`, the token or certificate, and `ontvangerOrganisatie` (plus `ontvangerApplicatie` when the case system asks for it). When the case system has separate addresses for the two services, set `vrijeBerichtenUrl` and `ontvangAsynchroonUrl`.
2. Choose that source as the synchronization's target.
3. In the target configuration, replace `zgwDocument` with `stufDocument`:

| Setting | Meaning |
|---|---|
| `documenttype` | the document type description the case type knows (`dct.omschrijving`), else the object's `documenttype` |
| `zaakIdentificatieField` | the field that holds the case's identificatie, default `zaakIdentificatie` |
| `fileIdField`, `fileName`, `fileId`, `objectId` | where the file is, as for `zgwDocument` |

A source in `log` mode sends nothing, so integriq refuses to deliver through it rather than write back an identificatie that does not exist. A document without a title, a case or a document type is not sent.

## Not yet covered

- Picking the destination per document. Each synchronization sends to one Documenten API.
