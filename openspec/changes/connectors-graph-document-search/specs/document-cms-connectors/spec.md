# document-cms-connectors Specification

## ADDED Requirements

### Requirement: A Microsoft 365 source answers a document search across files, mail and chat (REQ-DCC-008)

A Microsoft 365 source MUST answer a search for terms in a date range over drive items, mail messages and chat messages, as REQ-DCC-004 hits carrying `entityType` and a handle that fetches the hit. It MUST search files with the source's application grant and mail and chat with the requesting person's delegated grant, and MUST say so with a notice when that person has no delegated grant. It MUST answer at most the requested number of hits with a count of the rest, and MUST answer `not-permitted` with the missing permission when Microsoft 365 refuses.

#### Scenario: a Woo officer searches for a project name
- GIVEN a Microsoft 365 source with an application grant and the officer's own delegated grant
- WHEN she searches "Stationsplein" from 2025-01-01 to 2026-06-30
- THEN she gets a SharePoint document, a mail and a Teams message as hits with name, location, date and snippet, each with a fetch handle
- @e2e exclude backend search through a sibling app; covered by PHPUnit against recorded Graph answers

#### Scenario: no delegated grant
- GIVEN an officer who never connected her own Microsoft account
- WHEN she searches
- THEN she gets file hits and a notice that mail and chat need her own grant
- @e2e exclude backend search; covered by PHPUnit

### Requirement: A sibling app searches and fetches through typed commands (REQ-DCC-009)

Integriq MUST offer `DocumentSearchRequestedEvent` and `DocumentFetchRequestedEvent` that resolve the calling app's linked Microsoft 365 connection, run for a named person, and answer in the result slot without a Nextcloud session. A fetch MUST return the file name, type and content of the hit, and integriq MUST keep no copy.

#### Scenario: dossiq adds a found document to the case
- GIVEN dossiq's search returned a SharePoint document hit
- WHEN dossiq dispatches the fetch with that handle
- THEN it receives the document's name, type and content, and no file remains in integriq
- @e2e exclude backend command; covered by PHPUnit with the real event classes
