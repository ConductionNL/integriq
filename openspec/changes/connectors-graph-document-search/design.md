# Design: connectors-graph-document-search

Kind: code. Size M. Read at integriq development `966d6458` and dossiq development on 2026-09-28.

## Context

- **Adapters.** `SharePointOnlineAdapter` reads drive children (:154) and content (:196), declares `document-fetch` and `document-list` (:140-142), and writes a fetched file into the signed-in user's Files (:65), returning null without a session (:200-203). `Microsoft365Adapter` reads `/v1.0/me/events` and `/v1.0/me/messages` (:122-124) with capabilities `calendar-read` and `mail-metadata-read` (:36-37).
- **Spec.** `document-cms-connectors` REQ-DCC-002 names `search-federation`; REQ-DCC-004 defines the hit envelope; REQ-DCC-006 says a document kept on the Conduction side lands with filinq, never as an integriq-owned file. `saas-productivity-connectors` REQ-SPC-004 adds `entityType`, `recordKey`, `actorLabel`.
- **dossiq's caller.** dossiq D-2: `POST /api/cases/{id}/woo/sources/search {source, terms, from, to}` dispatches integriq's search; D-3: an integriq result is fetched through integriq and written into the case folder by dossiq; D-4: provenance keeps the integriq connection id and the location.
- **Delegated grants.** `sources-per-user-oauth` gives a source a per-user OAuth grant.

## D1. One search, three entity types

`Microsoft365Adapter::search(source, terms, from, to, entityTypes, limit, userId)` posts to Graph `/search/query` once per grant kind and answers REQ-DCC-004 hits: `title` (name or subject), `path` (site and folder, or mailbox, or team and channel), `modifiedAt`, `snippet`, `entityType` (`driveItem`, `message`, `chatMessage`), and `remoteId` as the fetch handle `{entityType}:{driveId}:{itemId}` or `{entityType}:{id}`. It answers at most `limit` hits and `moreCount`. The manifest declares `search-federation`.

## D2. Which grant searches what

Drive items are searched with the source's application grant from the broker, so a Woo search covers the organisation's sites. Mail and chat are searched with the requesting person's delegated grant (`sources-per-user-oauth`), so a mailbox search stays within what that person may read in Microsoft 365. A person without a delegated grant gets file hits and a notice `delegated-grant-missing` for mail and chat. A refused permission answers `not-permitted` with the permission name.

## D3. Typed commands

`DocumentSearchRequestedEvent(sourceApp, connectionKey, userId, terms, from, to, entityTypes, limit = 50)` resolves the app's linked Microsoft 365 connection and answers `{hits, moreCount, notices}`. `DocumentFetchRequestedEvent(sourceApp, connectionKey, userId, handle)` answers `{fileName, mimeType, content}` for a drive item, or the message rendered as `.eml` for mail and as text with its metadata for chat. integriq stores nothing (REQ-DCC-006); dossiq writes the file.

## Declarative versus imperative

Imperative adapter and commands: a live external search with two grant kinds.

## Seed data

None beyond fixtures: a recorded Graph search answer with one hit of each type, and a recorded drive item download.

## Risks

- [Graph throttles a broad search] the adapter honours Graph's retry-after answer once and otherwise returns what it has with a notice.
