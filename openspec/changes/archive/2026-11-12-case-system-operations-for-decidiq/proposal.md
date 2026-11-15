---
kind: code
---

# Proposal: case-system-operations-for-decidiq

## Summary
decidiq exchanges meeting documents with the organisation's case system
(rows plt-23 and plt-24 in decidiq's matrix, change
`platform-case-system-document-exchange` on decidiq). It declares a
`case-system` connection with `sourceTemplate: "zgw-zaken"` and calls
integriq's CallService on the linked source, one POST per operation. No
integriq source template by that slug answers those operations today:
`lib/Settings/configurations/zgw-zaken.json` is a consumer set (pull and push
of zaken into OpenRegister), not an operation surface.

This change adds a source of type `case-system` that answers five
operations in-process, the way a `soap` source is answered by SOAPService
inside CallService, and maps them onto the ZGW Zaken and Documenten APIs.
Mapping onto a national standard is integriq's job (precedent:
hand-woo-diwoo-to-integriq); decidiq never learns a ZGW url shape.

## The five operations (decidiq's contract, unchanged)

| endpoint | body | answer |
|---|---|---|
| /case-system/read-case | {reference} (case number or address) | {url, identification, title}; no url = not found |
| /case-system/list-documents | {case} (case address) | {documents: [{url, name}]} |
| /case-system/read-document | {document} (document address) | {name, content} (content base64) |
| /case-system/add-document | {case, name, kind, content (base64), confidential (bool), ground (string)} | {url} |
| /case-system/create-case | {kind: "meeting", title, date (Y-m-d)} | {url, identification} |

A status of 400 or more is a refusal whose `message` decidiq shows to the
griffier. `kind` is one of agenda, item-document, decision, decision-list,
minutes, proof-package.

## What changes
- A seeded source with slug `zgw-zaken` and type `case-system`, disabled
  until an administrator names the Zaken and Documenten sources it uses and
  the kind table. `ConnectionProbeService::linkTemplate()` already links a
  connection to a seed by slug.
- `CaseSystemOperations` answers the five operations through the two named
  ZGW sources (CallService, so call logs, auth and rate limits apply).
- A mock mode on the source answers from fixtures, so decidiq's e2e test
  `tests/e2e/case-system-exchange.spec.ts` can run on the dev instance.

## Out of scope
StUF-ZKN (a later change; the same five operations, another adapter behind
the same source type). Pulling cases into OpenRegister stays
`zgw-connectors-for-dossiq`.

## Rows
- integriq `nl-zgw-zaken` and `nl-zgw-documenten` keep their change
  (`zgw-connectors-for-dossiq`, the consumer sets); this change serves them in
  part and is named in their note when it is built.
- decidiq plt-23 and plt-24 (owner decidiq) depend on it.
