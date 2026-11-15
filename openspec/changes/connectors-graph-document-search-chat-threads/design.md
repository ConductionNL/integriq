# Design: connectors-graph-document-search-chat-threads

The second part of `connectors-graph-document-search`; its design D1 to D3 is in that change.

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
