# Document search in Microsoft 365

When a Woo request comes in, the officer has to find what the organisation holds on the subject. Much of it sits in SharePoint and OneDrive. Dossiq owns the request and does the gathering; integriq is the seam that searches Microsoft 365 and fetches a hit, live, without keeping a copy.

## What it does

| Command | What it answers |
|---------|-----------------|
| Search | Hits for search terms in a period, across the entity types asked for, each with a handle to fetch it by. |
| Fetch | One hit's file name, type and content, read from Microsoft 365 at that moment. |

Every search is live: integriq builds no index and stores neither the hits nor the fetched content.

## Setting up the search

1. In Microsoft Entra ID, register an application for integriq and give it the Microsoft Graph permissions in the table below. Grant admin consent.
2. Register the application's credential with the OpenRegister credential broker, with Microsoft Graph (`https://graph.microsoft.com`) as its base URL.
3. Set the app config key `microsoft-365_credential_id` to the uuid of that broker credential. Without it, every search answers the notice `not-connected` and nothing is called.
4. Optionally set `microsoft365_search_region` to the Graph search region of the tenant. It defaults to `EUR`; an application grant needs one.
5. Link the connection to the app that searches (dossiq), so it can dispatch the commands. An app without a linked connection gets the notice `not-linked` on a search and nothing on a fetch.
6. For mail and chat, each person connects their own Microsoft account in the credential broker (a personal credential, see `sources-per-user-oauth`) with the delegated permissions below. The provider slug of those accounts is read from `microsoft365_delegated_provider`, default `microsoft-entra`.

### Microsoft Graph permissions

| What is searched | Grant | Permission |
|------------------|-------|------------|
| Files in SharePoint and OneDrive (`driveItem`) | Application, through the broker credential | `Files.Read.All` and `Sites.Read.All` |
| Mail (`message`) | Delegated, as the requesting person | `Mail.Read` |
| Teams chat and channel messages (`chatMessage`) | Delegated, as the requesting person | `Chat.Read` and `ChannelMessage.Read.All` |

Mail and chat are searched as the person who asks, never with the application grant, so a search finds only what that person may read. The grant is that person's own connected account at the provider: their own, enabled, personal credential, never another person's and never an organisation credential. A person with no such account gets the file hits plus the notice `delegated-grant-missing`, rather than an empty list that would read as "nothing found". A person with more than one gets `delegated-grant-ambiguous`: integriq does not guess which account to use.

A mail search filters the period on the received date, a chat search on the sent date. A delegated search sends no search region.

## The contract for a calling app

The commands are two events in `OCA\Integriq\Event`, dispatched through Nextcloud's `IEventDispatcher`. Each carries the calling app, the connection key and the user id; the answer is read with `getResult()` after the dispatch.

### `DocumentSearchRequestedEvent`

| Argument | Meaning |
|----------|---------|
| `terms` | The search terms. |
| `from`, `to` | Optional period, `Y-m-d`, on the last modified date. |
| `entityTypes` | `driveItem`, `message`, `chatMessage`; empty means all. |
| `limit` | At most this many hits, 1 to 500, default 50. |

The result is `hits`, `moreCount` (how many more matched than the limit) and `notices`. A hit carries `sourceSlug`, `remoteId` (the handle), `title`, `mimeType`, `sizeBytes`, `modifiedAt`, `modifiedByLabel`, `path`, `previewUrl`, `snippet`, `score` and `entityType`.

### `DocumentFetchRequestedEvent`

Takes the `remoteId` of a hit as its handle. The result is `fileName`, `mimeType` and `content`. A file comes back as itself, a mail as its MIME source (`.eml`, `message/rfc822`) and a chat message as its Graph JSON (`application/json`). Mail and chat are fetched with the person's own account. An app without a linked connection, a handle that names nothing, or a mail or chat handle for a person without their own account gets no result.

### Notices

| Notice | Meaning |
|--------|---------|
| `not-linked` | The calling app has no linked connection. Nothing is searched. |
| `not-connected` | No broker credential is configured. Nothing is called. |
| `delegated-grant-missing` | Mail or chat was asked for, and the person has not connected their own Microsoft account. File hits still come back. |
| `delegated-grant-ambiguous` | Mail or chat was asked for, and the person has more than one connected Microsoft account. File hits still come back. |
| `not-permitted` | Microsoft 365 refused the search: check the permissions above and the admin consent. |
| `search-failed` | Microsoft 365 answered with an error or an unreadable body. |

Every search and fetch is logged with the calling app, the connection, the user and the terms or handle.
