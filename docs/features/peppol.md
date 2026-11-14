# Peppol e-invoicing

## Overview

Integriq is the Peppol connection of the fleet. A sibling app (shillinq for
sales invoices and purchase orders) hands integriq a UBL document to send, and
receives the UBL documents that arrive for the organisation. Integriq talks to
the Peppol Access Point through a configured `source` of type `peppol`; the
Access Point's credential stays in integriq's credential broker.

| Provider | `configuration.provider` | What it does |
|----------|--------------------------|--------------|
| Sandbox | `log` | No network call. Every submission gets a `MOCK-PEPPOL-<n>` id; every inbound fetch answers a fixture invoice from `0106:12345678` (Leverancier Voorbeeld B.V., EUR 1,210.00). |
| Generic REST | `rest` | Calls `configuration.baseUrl` with the credential named by `configuration.authentication.credentialRef`. |

## Sending a document

The producing app saves a CloudEvent object in register `integriq`, schema
`event`, with type `nl.conduction.peppol.outbound.requested` and data:

| Field | Meaning |
|-------|---------|
| `sourceApp` | The producing app, e.g. `shillinq` |
| `objectUri` | The object being sent; with `documentType` the idempotency key |
| `recipientPeppolId` | `scheme:identifier`, e.g. `0106:12345678` |
| `documentType` | e.g. `ubl-invoice-2.1` |
| `payloadFileUri` | Where the UBL document is in Nextcloud Files |

`payloadFileUri` takes two forms:

- `nextcloud-file:<id>`: the Nextcloud file with that id;
- `/<userId>/files/<path>`: an absolute path inside a user's Files.

Integriq reads that file and submits its content, not its reference. A
reference that cannot be read fails the transmission with `payload not
readable: <reference>`, and the transmission follows the retry and dead-letter
path. Only an object in integriq's own register and `event` schema starts a
transmission; the same type in any other register or schema is ignored.

Each transmission is a `peppol_transmission` object; every status change emits
`nl.conduction.peppol.delivery.status` with `objectUri`, `transmissionId`,
`status`, `timestamp` and `detail`.

## Receiving a document

The Access Point announces an inbound document on the signed webhook
`POST /api/peppol/inbound`. Its reference to the document is reachable only
with the Access Point's credential, so integriq:

1. fetches the document from the Access Point (`rest`: a document id is read
   from `{baseUrl}/documents/<id>`; an absolute URL only when it is on the
   Access Point's own host);
2. stores it as a file on a `peppol_inbound_document` object
   (`senderPeppolId`, `documentType`, `accessPointReference`, `receivedAt`,
   `status`, `fileId`, `fileName`);
3. emits `nl.conduction.peppol.inbound.received` with:

| Field | Meaning |
|-------|---------|
| `senderPeppolId` | The sender, `scheme:identifier` |
| `documentType` | The UBL document type |
| `payloadReference` | The stored object: `integriq/peppol_inbound_document/<uuid>` |
| `payloadFileId` | The Nextcloud file id of the stored UBL |
| `payloadFileName` | Its file name |

A fetch or store that fails leaves the object `fetch-failed` with the reason
in `detail`, and emits nothing; the webhook still answers 200. A redelivered
notification for the same `accessPointReference` retries it on the same
object; a redelivery of a document already `stored` changes nothing.
