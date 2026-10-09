# Design: peppol-readable-payloads-and-scoped-consumer

Kind: code. Size S to M. Read at integriq development `966d6458` on 2026-09-28.

## Context

- **The guard.** `PeppolOutboundConsumer::extractOutboundRequestedPayload()` (`lib/Service/PeppolOutboundConsumer.php:112-120`) returns null when `getRegister()` differs from `PeppolTransmissionService::REGISTER` (`integriq`, :60) or `getSchema()` differs from `event`. OpenRegister stores ids there (issue #1222, comment of 28 Sep), so every event is dropped. Before openregister declared the getters, the `method_exists()` probes skipped both checks and every register could start a transmission. Both states were wrong.
- **The id resolver that works.** `CloudEventListener::isSelfReference()` (`lib/EventListener/CloudEventListener.php:192`) compares `(string)$object->getSchema()` with ids from `EventService::getSelfSchemaIds()`, resolved once per process.
- **Outbound payload.** `attemptSubmission()` (`lib/Service/PeppolTransmissionService.php:249-263`) passes `payloadFileUri` as the `payload` argument; `RestPeppolAccessPointProvider::submitDocument()` (`lib/Service/Peppol/RestPeppolAccessPointProvider.php:103-122`) posts it as `payload`. The access point receives a path, not a document. The spec (REQ-003) already says the connector resolves the UBL from `payloadFileUri`.
- **Inbound.** `PeppolController::inbound()` passes `senderPeppolId`, `documentType` and `payloadReference` to `handleInboundDocument()` (:390), which emits `nl.conduction.peppol.inbound.received` with the reference unchanged. That reference is the access point's, reachable only with the access point's credential, which only integriq holds.
- **Files on objects.** `OpenFormulierenIntakeService` fetches an attachment and stores it with OpenRegister `FileService::addFile()` (:407), which returns a Nextcloud file with an id.
- **Shillinq's reference.** shillinq `sales-einvoice-exchange` D2 stores the outbound UBL in Nextcloud Files and passes its path as `payloadFileUri`.

## D1. Compare ids, resolved once

The consumer resolves the ids of register `integriq` and schema `event` once per process, through the same resolver `CloudEventListener` uses, and compares them as strings with `getRegister()` and `getSchema()`. A slug is still accepted, so a future OpenRegister that stamps slugs keeps working. When the ids cannot be resolved the consumer logs a warning and matches nothing: failing closed is the safe direction for a guard that starts an outbound transmission.

## D2. Submit the bytes

`payloadFileUri` names a Nextcloud file either by id (`nextcloud-file:<id>`) or by absolute path (`/<userId>/files/...`). The service reads it through `IRootFolder` and passes the UBL content to `submitDocument()`; the interface docblock stops saying "or a reference". A reference that does not resolve moves the transmission to `failed` with "payload not readable: <reference>", which follows the existing retry and dead-letter path.

## D3. Fetch, store, then announce

`PeppolAccessPointProviderInterface` gains `fetchInboundDocument(configuration, reference): string`. `RestPeppolAccessPointProvider` fetches it with the brokered credential; `LogPeppolAccessPointProvider` returns a fixture UBL. `handleInboundDocument()` creates a `peppol_inbound_document` object (`senderPeppolId`, `documentType`, `accessPointReference`, `receivedAt`, `status`), attaches the UBL with `FileService::addFile()`, and emits `nl.conduction.peppol.inbound.received` with `payloadReference` set to the object's OpenRegister path, `payloadFileId` and `payloadFileName`. A fetch that fails stores the object with status `fetch-failed`, emits nothing, and a retry picks it up; the webhook still answers 200 (REQ-005).

Alternative considered: write the file into a user's Nextcloud Files. Rejected: integriq has no user to own it, and the receiving app would need that user's path.

## Declarative versus imperative

| Behaviour | Path | Rationale |
|---|---|---|
| Consumer scoping | Imperative, the existing listener | A guard on an event. |
| Inbound document record | Declarative schema with an `authorization` block for administrators and the `event` readers | The record and its file are data. |
| Fetching from the access point | Imperative, the provider seam | An external call with a brokered credential. |

## Seed data

The `log` provider's fixture: an inbound UBL invoice from `0106:12345678` (Leverancier Voorbeeld B.V., EUR 1,210.00), stored as `peppol_inbound_document` with its file.

## Risks

- [An access point deletes a document after the first fetch] the object keeps the file, and a retry after a partial failure reads the stored file, not the access point.
