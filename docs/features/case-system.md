<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

# Case system for meeting apps

A meeting app such as decidiq keeps its meetings and decisions itself, but the case and its documents belong in the municipality's case system. A source of type `case-system` is that link. Integriq answers it in-process and maps each request onto the ZGW Zaken and Documenten APIs, so the meeting app never talks ZGW itself.

This page is for the administrator who connects a meeting app to a case system.

## What it answers

| Operation | What happens in the case system |
|---|---|
| `read-case` | Fetches the zaak by its address, or looks it up by `identificatie`. Answers its address, identificatie and omschrijving. |
| `list-documents` | Lists the documents linked to the zaak, with their titles. |
| `read-document` | Reads one document and its content. |
| `add-document` | Stores the document in the Documenten API and links it to the zaak. When the link fails, the document is removed again. |
| `create-case` | Creates a zaak of the meeting case type, one per meeting. |

An unknown document kind answers 422 and names the kind. A ZGW error answers its own status with the message from its `detail`, never its full body.

## Connect it

Integriq ships one case-system source, `zgw-zaken` ("Zaken en Documenten (ZGW)"). It is off and empty. A connection that names the template `zgw-zaken`, such as decidiq's case-system connection, links to it at once.

1. Make two ordinary sources that point at your ZGW store: one for the Zaken API and one for the Documenten API, each with its own `jwt-zgw` authentication. See [ZGW consumer sets](zgw-sets.md) for the addresses and the credentials.
2. Open the `zgw-zaken` source. With the type "Case system (ZGW)", the editor shows its settings.
3. Pick the Zaken API source and the Documenten API source.
4. Fill in the meeting case type: the address of the zaaktype a new meeting gets.
5. Fill in your organisation's RSIN. It is written as `bronorganisatie` on every new zaak and document, and as the zaak's `verantwoordelijkeOrganisatie`.
6. Add one document type per kind the meeting app sends, for example `besluitenlijst`, each with the address of its informatieobjecttype.
7. Turn the source on.

## The settings

These live in the source's `configuration`. The editor writes them for you.

| Setting | Key | Default |
|---|---|---|
| Zaken API source | `zakenSource` | none, required |
| Documenten API source | `documentenSource` | none, required |
| Meeting case type | `meetingZaaktype` | none, required for `create-case` |
| Your organisation's RSIN | `bronorganisatie` | none, required for new zaken and documents |
| Document author | `auteur` | the source's name |
| Confidential documents are stored as | `confidentialAs` | `vertrouwelijk` |
| Public documents are stored as | `publicAs` | `openbaar` |
| Document type per kind | `kinds` | none, one entry per kind |
| Answer from test data | `mock` | off |

A missing Zaken or Documenten source answers 409 and names the setting that is empty.

An address the meeting app passes, such as a zaak or a document, is only followed when it lies under the address of the ZGW source you picked. So the source's credentials never reach another host.

## Try it without a case system

Turn on "Answer from test data". The source then answers from `lib/Settings/case-system-mock.json`: two cases with documents. A document you add or a case you create gets a new address and is kept for that one request only.

## Related

- [ZGW consumer sets](zgw-sets.md): reading a ZGW store into a register
- [Sources](sources.md)
