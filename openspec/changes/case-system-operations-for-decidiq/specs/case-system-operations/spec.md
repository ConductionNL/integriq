# case-system-operations Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- case-system-operations-for-decidiq

## Purpose

A `case-system` source answers five operations a meeting app calls (read a
case, list and read its documents, add a document, create a meeting case) and
maps them onto the ZGW Zaken and Documenten APIs, so the calling app never
builds a ZGW request itself.

## ADDED Requirements

### Requirement: A case-system source answers five operations in-process (REQ-CSO-001)

A source of type `case-system` MUST answer POST calls to
`/case-system/read-case`, `/case-system/list-documents`,
`/case-system/read-document`, `/case-system/add-document` and
`/case-system/create-case` through CallService without an HTTP request of its
own, with the bodies and answers of the table in the proposal, and MUST write
a call log for each like any other source.

#### Scenario: Reading a case by its number
- GIVEN a case-system source in mock mode holding case ZAAK-2026-0001
- WHEN CallService posts {reference: "ZAAK-2026-0001"} to /case-system/read-case
- THEN the answer is 200 with its url, identification and title
- AND a call log names the source
- @e2e exclude backend operation; covered by PHPUnit with the mock fixture

#### Scenario: An unknown operation is refused
- GIVEN a case-system source
- WHEN CallService posts to /case-system/delete-case
- THEN the answer is 404 with a message naming the five operations
- @e2e exclude backend operation; covered by PHPUnit

### Requirement: Adding a document maps kind and confidentiality onto ZGW (REQ-CSO-002)

add-document MUST create the informatieobject with the informatieobjecttype
the source's `kinds` table gives for the kind and the configured
vertrouwelijkheidaanduiding, then link it to the case; an unknown kind MUST
answer 422 naming the kind, and a failed link MUST remove the document again.

#### Scenario: A confidential decision is added
- GIVEN a source whose kinds table maps decision to a type url
- WHEN add-document is called with kind decision and confidential true
- THEN the Documenten API receives that type and vertrouwelijk, and the Zaken API receives the link
- @e2e exclude backend mapping; covered by PHPUnit against recorded ZGW requests

#### Scenario: A failed link leaves no orphan document
- GIVEN the Zaken API refuses the link
- WHEN add-document is called
- THEN the created document is deleted and the answer carries the refusal's status
- @e2e exclude backend compensation; covered by PHPUnit

### Requirement: A seeded zgw-zaken template links a connection at once (REQ-CSO-003)

Integriq MUST seed a source with slug `zgw-zaken` and type `case-system`,
disabled and with an empty configuration, so a connection declaring
`sourceTemplate: "zgw-zaken"` links to it through the existing link flow.

#### Scenario: decidiq's connection links
- GIVEN decidiq's connections.json declares a case-system connection with sourceTemplate zgw-zaken
- WHEN an administrator adds the integration
- THEN the connection is linked to the zgw-zaken source and probed
- @e2e tests/e2e/case-system-link.spec.ts
