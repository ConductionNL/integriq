# peppol-access-point-connector Specification

## ADDED Requirements

### Requirement: The outbound consumer reacts only to integriq's own event schema (REQ-007)

The outbound consumer MUST start a transmission for a created object whose register and schema are integriq's register and its `event` schema and whose `type` is `nl.conduction.peppol.outbound.requested`. It MUST compare the object's register and schema with their resolved ids and MUST also accept the slugs. It MUST ignore an object with the same `type` in any other register or schema. When the ids cannot be resolved it MUST log a warning and start nothing.

#### Scenario: shillinq hands over an e-invoice
- GIVEN shillinq saves an object in register `integriq`, schema `event`, with type `nl.conduction.peppol.outbound.requested` for invoice 2026-0142, and OpenRegister stamps numeric ids on it
- WHEN the object is created
- THEN a `peppol_transmission` for that invoice is queued
- @e2e exclude backend listener; covered by PHPUnit with numeric ids and a live check on a dev instance

#### Scenario: another register carries the same type
- GIVEN an object in another app's register whose payload has type `nl.conduction.peppol.outbound.requested`
- WHEN the object is created
- THEN no transmission is queued
- @e2e exclude backend listener; covered by PHPUnit

### Requirement: The access point receives the UBL document itself (REQ-008)

Before submitting, the connector MUST read the UBL named by `payloadFileUri` when it names a Nextcloud file by id (`nextcloud-file:<id>`) or by absolute path, and MUST pass the document's content to the access point. A reference that cannot be read MUST move the transmission to `failed` with a reason that names the reference, and MUST follow the existing retry and dead-letter path.

#### Scenario: an invoice stored in Files is transmitted
- GIVEN shillinq stored the UBL of invoice 2026-0142 in Nextcloud Files and names it in `payloadFileUri`
- WHEN the transmission is submitted
- THEN the access point receives the XML of that file, and the transmission moves to `sent` with the access point's transmission id
- @e2e exclude backend transmission; covered by PHPUnit against a recorded access point

#### Scenario: the reference cannot be read
- GIVEN a request whose `payloadFileUri` names a file that does not exist
- WHEN the transmission is submitted
- THEN the transmission is `failed` with "payload not readable" and the reference, and no call reaches the access point
- @e2e exclude backend transmission; covered by PHPUnit

### Requirement: An inbound document is stored where the receiving app can read it (REQ-009)

For a verified inbound-document notification, the connector MUST fetch the document from the access point with the brokered credential, MUST store it as a file on a `peppol_inbound_document` object in integriq's register, and MUST then emit `nl.conduction.peppol.inbound.received` with the sender, the document type, `payloadReference` set to that object's OpenRegister path, `payloadFileId` and `payloadFileName`. A fetch that fails MUST leave the object in status `fetch-failed` for a retry, MUST emit no event, and MUST NOT change the webhook's answer.

#### Scenario: a supplier invoice arrives over Peppol
- GIVEN a signed notification that supplier `0106:12345678` sent an invoice
- WHEN `POST /api/peppol/inbound` receives it
- THEN a `peppol_inbound_document` holds the UBL as a file, and the emitted event names its path and file id, so shillinq can read the invoice
- @e2e exclude backend webhook; covered by PHPUnit and Newman

#### Scenario: the access point fails on the fetch
- GIVEN a signed notification whose document fetch answers HTTP 500
- WHEN the notification is received
- THEN the webhook answers 200, the object is `fetch-failed`, and no inbound event is emitted until a retry succeeds
- @e2e exclude backend webhook; covered by PHPUnit
