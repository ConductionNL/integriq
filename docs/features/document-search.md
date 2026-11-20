# Document search in Microsoft 365 and Nextcloud Talk

When a Woo request comes in, the officer has to find what the organisation holds on the subject. Much of it sits in SharePoint and OneDrive, and part of it in chat: Teams channels and chats, and Nextcloud Talk conversations. Dossiq owns the request and does the gathering; integriq is the seam that searches these sources and fetches a hit, live, without keeping a copy. A fetched chat message comes with the conversation it sat in.

## What it does

| Command | What it answers |
|---------|-----------------|
| Search | Hits for search terms in a period, across the entity types asked for, each with a handle to fetch it by. |
| Fetch | One hit's file name, type and content, read from its source at that moment. A chat hit also carries its `thread`. |

Every search is live: integriq builds no index and stores neither the hits nor the fetched content.

## Setting up the search

1. In Microsoft Entra ID, register an application for integriq and give it the Microsoft Graph permissions in the table below. Grant admin consent.
2. Register the application's credential with the OpenRegister credential broker, with Microsoft Graph (`https://graph.microsoft.com`) as its base URL.
3. Set the app config key `microsoft-365_credential_id` to the uuid of that broker credential. Without it, every search answers the notice `not-connected` and nothing is called.
4. Optionally set `microsoft365_search_region` to the Graph search region of the tenant. It defaults to `EUR`; an application grant needs one.
5. Link the connection to the app that searches (dossiq), so it can dispatch the commands. An app without a linked connection gets the notice `not-linked` on a search and nothing on a fetch.

### Microsoft Graph permissions

| What is searched | Grant | Permission |
|------------------|-------|------------|
| Files in SharePoint and OneDrive (`driveItem`) | Application, through the broker credential | `Files.Read.All` and `Sites.Read.All` |
| Mail (`message`) | Delegated, as the requesting person | `Mail.Read` |
| Teams chat and channel messages (`chatMessage`) | Delegated, as the requesting person | `Chat.Read` and `ChannelMessage.Read.All` |

Mail and chat are searched as the person who asks, never with the application grant, so a search finds only what that person may read. The per-person grant comes with `sources-per-user-oauth`, which is not built yet. Until then a search that asks for mail or chat answers the file hits plus the notice `delegated-grant-missing`, rather than an empty list that would read as "nothing found".

### Nextcloud Talk

Talk is not a connection with credentials: when Talk is installed and enabled it is searched, with no setup. The search runs as the requesting person through Talk's own unified search provider, so it finds only conversations that person is in, and a conversation marked sensitive answers its snippet the way Talk's own search does. A fetch first asks Talk whether the person is still a participant; one who has left gets `not-a-participant` and no messages. The messages around a hit are read through Nextcloud's comments API.

Integriq reads Talk through `OCA\Talk\Search\MessageSearch::search()`, `OCA\Talk\Manager::getRoomForUserByToken()` and `OCA\Talk\Service\ParticipantService::getParticipantsForRoom()`, read on Talk 22.0.20 (Nextcloud 32) and 25.0.8 (Nextcloud 35). Without those classes Talk counts as not installed.

WhatsApp is not a source: there is no organisation-side archive to search.

## The contract for a calling app

The commands are two events in `OCA\Integriq\Event`, dispatched through Nextcloud's `IEventDispatcher`. Each carries the calling app, the connection key and the user id; the answer is read with `getResult()` after the dispatch.

### `DocumentSearchRequestedEvent`

| Argument | Meaning |
|----------|---------|
| `terms` | The search terms. |
| `from`, `to` | Optional period, `Y-m-d`, on the last modified date. |
| `entityTypes` | `driveItem`, `message`, `chatMessage`; empty means all. |
| `limit` | At most this many hits, 1 to 500, default 50, across all platforms together. |
| `platforms` | `microsoft-365` (also accepted as `microsoft-teams`) and `nextcloud-talk`; empty, the default, means both. Talk is only searched when `entityTypes` is empty or holds `chatMessage`. |

The result is `hits`, `moreCount` (how many more matched than the limit) and `notices`. A hit carries `sourceSlug`, `remoteId` (the handle), `title`, `mimeType`, `sizeBytes`, `modifiedAt`, `modifiedByLabel`, `path`, `previewUrl`, `snippet`, `score` and `entityType`. A chat hit also carries `platform` (`microsoft-teams` or `nextcloud-talk`) and `conversationId`. A Talk hit's handle is `chatMessage:talk:{token}:{messageId}`; a Teams chat handle is `chatMessage:teams:chat:{chatId}:{messageId}` or `chatMessage:teams:channel:{teamId}:{channelId}:{rootId}:{messageId}`, each id URL-encoded.

### `DocumentFetchRequestedEvent`

Takes the `remoteId` of a hit as its handle, and for a chat hit an optional `context`: how many messages to read on each side of it, 0 to 25, default 5. The result is `fileName`, `mimeType` and `content`. An app without a linked connection, or a handle that names no file or message, gets no result.

For a chat hit the result also carries `thread`, and `content` is a plain text transcript of it (`mimeType` `text/plain`): a header with the platform, the conversation name, the participants and the fetch time, then the messages in order with the hit marked `>>`. Store `thread` with the fetched item.

| Key of `thread` | Meaning |
|-----------------|---------|
| `platform` | `microsoft-teams` or `nextcloud-talk`. |
| `conversation` | `id`, `name` and `kind`: `channel`, `chat`, `group`, `one-to-one` or `public`. |
| `participants` | Each `id` and `label`. |
| `messages` | Up to `context` messages before the hit, the hit, and up to `context` after it, in time order. Each `id`, `at` (ISO 8601), `actorLabel`, `text` and `isHit`. System messages and deleted messages are never part of it. |

A thread is read with the same grant as the hit: the person's delegated grant for Teams (`delegated-grant-missing` until they have one), the person's own participation for Talk. A Teams chat is read at most four pages deep, so a hit far back in a long chat may come with fewer messages on its older side.

A refused fetch answers `error` instead, one of the codes below.

| Error | Meaning |
|-------|---------|
| `context-too-large` | `context` is above the maximum, named in `maximum` (25). Nothing is fetched. |
| `not-a-participant` | The person is no longer in the Talk conversation. No messages are read. |
| `talk-not-installed` | A Talk handle, and Talk is not installed. |
| `delegated-grant-missing` | A Teams chat handle, and the person has no delegated grant yet. Nothing is called. |
| `not-permitted` | Microsoft 365 refused the read. |
| `fetch-failed` | Microsoft 365 answered with an error or an unreadable body. |

### Notices

| Notice | Meaning |
|--------|---------|
| `not-linked` | The calling app has no linked connection. Nothing is searched. |
| `not-connected` | No broker credential is configured. Nothing is called. |
| `delegated-grant-missing` | Mail or chat was asked for, and the person has no delegated grant yet. File hits still come back. |
| `not-permitted` | Microsoft 365 refused the search: check the permissions above and the admin consent. |
| `search-failed` | Microsoft 365 or Talk answered with an error or an unreadable body. |
| `talk-not-installed` | Talk was asked for and is not installed. The Microsoft 365 hits still come back. |

Every search and fetch is logged with the calling app, the connection, the user and the terms or handle.
