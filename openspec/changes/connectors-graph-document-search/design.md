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

## D4. The thread of a chat hit (amendment 2026-10-05, row 1.16)

`DocumentFetchRequestedEvent` gains an optional `context` (int, default 5,
maximum 25). For a `chatMessage` handle the result gains `thread`:

```
thread: {
  platform: 'microsoft-teams' | 'nextcloud-talk',
  conversation: { id, name, kind: 'channel' | 'chat' | 'group' | 'one-to-one' | 'public' },
  participants: [ { id, label } ],
  messages: [ { id, at, actorLabel, text, isHit } ]
}
```

and `content` is a plain text transcript: a header with platform,
conversation name, participants and the fetch time, then the messages in
order with the hit marked. `mimeType` is `text/plain`.

Teams. A channel hit reads `GET /teams/{teamId}/channels/{channelId}/messages/{id}`
and its `replies`, and the channel's `members`. A chat hit reads
`GET /chats/{chatId}/messages` ordered by `createdDateTime`, paged until
`context` messages on each side of the hit are found or the paging cap of 4
pages is reached, and `GET /chats/{chatId}/members`. All with the requester's
delegated grant. Messages whose `messageType` is not `message`, or that carry
`deletedDateTime`, are dropped.

## D5. The Talk source (amendment 2026-10-05, row 1.16)

`TalkMessageSource` lives in integriq and talks to Talk only through these
calls, each guarded by `class_exists()` and read on the Talk release that
matches the Nextcloud versions in `appinfo/info.xml`:

- Search: `OCA\Talk\Search\MessageSearch::search(IUser $user, ISearchQuery $query): SearchResult`,
  resolved from the server container, with integriq's own `ISearchQuery`
  implementation (term, limit, cursor). Each entry's attributes give
  `conversation` (room token), `messageId`, `actorType`, `actorId` and
  `timestamp`. A date range is applied to `timestamp` after the call.
- Participation: `OCA\Talk\Manager::getRoomForUserByToken(string $token, ?string $userId): Room`,
  which throws when the person is not a participant. Answer
  `not-a-participant`.
- Participants: `OCA\Talk\Service\ParticipantService::getParticipantsForRoom(Room $room): array`.
- Context: the public `OCP\Comments\ICommentsManager::getCommentsWithVerbForObjectSinceComment('chat', (string)$room->getId(), ['comment', 'object_shared'], $messageId, 'desc'|'asc', $context, false)`,
  once in each direction.

The handle is `chatMessage:talk:{token}:{messageId}`. Talk is not a
connection with credentials, so it needs no source record: it is offered
whenever Talk is installed, and `DocumentSearchRequestedEvent` gains
`platforms` (default both) to choose.
