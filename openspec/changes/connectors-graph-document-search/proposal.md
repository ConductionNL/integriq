---
kind: code
depends_on: [sources-per-user-oauth]
---

# Proposal: connectors-graph-document-search

## Summary

A Woo officer gathering the documents for a Woo request has to search SharePoint, Teams and mailboxes by hand, outside the case. dossiq's search dialog is written and waits on integriq for the Microsoft 365 part: integriq can list and fetch documents in one SharePoint site, and cannot search. This change adds a search over Microsoft Graph across files, mail and chat messages, answering hits in the result envelope integriq's document connector spec already defines, and a fetch of any hit by its handle, both as typed commands.

## Why

The owner-moves pass of 2026-09-28 handed this half to integriq for opencatalogi's row `wr-search-sources`, which dossiq `woo-requests-gather-documents-from-sources` specifies. That change is merged on dossiq `development`, so the half is `build` by the decision rule, and the row carries tender demand.

dossiq `woo-requests-gather-documents-from-sources`, Sibling halves: "**ConductionNL/integriq** owes a document search operation over Microsoft Graph (`/search/query` across drive items, chat messages and mail) behind its credential broker, answering name, location, date, snippet and a fetch handle, and a fetch by that handle. Its nearest open change, `connectors-sharepoint-publication-intake`, adds a Graph adapter that lists and fetches SharePoint documents ... and does not search. To be specified in integriq." Its tasks mark the live path "blocked on integriq's search operation".

Row in the opencatalogi matrix: `wr-search-sources`, "Search the organisation's other systems (case system, SharePoint, Teams, mail) in one go to gather the documents for a Woo request." Owner dossiq. Demand: tender https://www.tenderned.nl/aankondigingen/overzicht/391449 (De Connectie market consultation, REQ1 and REQ11) and TenderNed 388421 (SWO De Wolden Hoogeveen, "Zoek en Vind applicatie (Woo)"). No competitor is rated yes.

## What integriq already has

- `SharePointOnlineAdapter` (`lib/Service/Adapter/DocumentCms/SharePointOnlineAdapter.php`): drive children and content for one site, capabilities `document-fetch` and `document-list`; `fetchDocument()` writes into the signed-in user's Files and returns null without a session.
- `Microsoft365Adapter` (`lib/Service/Adapter/Saas/Microsoft365Adapter.php`): `/me/events` and `/me/messages` metadata.
- The spec `document-cms-connectors`: capability `search-federation` (REQ-DCC-002) and the normalised hit envelope `{sourceSlug, remoteId, title, mimeType, sizeBytes, modifiedAt, modifiedByLabel, path, previewUrl, snippet, score}` (REQ-DCC-004); `saas-productivity-connectors` REQ-SPC-004 adds `entityType`, `recordKey`, `actorLabel`. No adapter declares search.
- Per-user OAuth for a source, specified by the open change `sources-per-user-oauth`.
- No search command and no `search/query` call anywhere in `lib/`.

## What this change builds

1. A Graph search on a Microsoft 365 source over drive items, mail messages and chat messages, answering REQ-DCC-004 hits with `entityType`, capped per call with a count of the rest.
2. `DocumentSearchRequestedEvent` and `DocumentFetchRequestedEvent`: a sibling app searches a linked connection and fetches a hit's content by its handle, without a Nextcloud session in integriq.
3. Files searched with the organisation's application grant; mail and chat searched with the requesting person's own delegated grant.

## Out of scope

- The dialog, the Nextcloud files and cases sources, adding to the case and provenance (dossiq).
- Indexing Microsoft 365 content. Every search is live.
- Other document systems. They follow REQ-DCC-004 in their own changes.

## Impact

- Changed: `lib/Service/Adapter/Saas/Microsoft365Adapter.php` (search and fetch by handle), its manifest capabilities.
- New: the two events and their listeners.

## Cross-project dependencies

- dossiq `woo-requests-gather-documents-from-sources` dispatches the search and fetch commands and caps each source at 50 rows.
- opencatalogi's row `wr-search-sources` is owned by dossiq; opencatalogi publishes what dossiq assesses.

## Risks

- A municipality grants no Graph permission. The search answers `not-permitted` naming the missing permission, and dossiq shows that instead of an empty list.

## Amendment 2026-10-05: a chat message comes with its conversation (Woo row 1.16)

Woo capability row 1.16, "Chat and messaging material is a source for a
request, and a message keeps the conversation it sat in". Our column reads
`no`: "integriq ingests mail (lib/BackgroundJob/MailboxPollJob.php,
lib/Controller/MailIntakeController.php, lib/Event/MessageReceivedEvent.php)
and carries a Berichtenbox adapter ... No chat or messaging platform is a
source anywhere in the three apps, and nothing keeps a conversation". The gap
register names the missing half: "When gathering material for a Woo request,
a chat or messaging platform (Teams, Talk, WhatsApp) is a searchable source
and a fetched message comes with its thread: channel, participants and the
surrounding messages." Build plan: amend this change, wave 2, size L. The
gathering itself is dossiq's (decision D1: dossiq owns the Woo request;
`dossiq/woo-requests-gather-documents-from-sources` is built as written).

What this amendment adds, on top of tasks 1 to 4 (none of which is built):

1. A thread on fetch. Fetching a Teams `chatMessage` hit answers the message
   with its conversation: the channel or chat (id, name, kind), the
   participants, and up to `context` messages before and after it (default
   5, maximum 25), each with id, time, author label and text, the hit marked.
   The rendered content is a plain text transcript with that header, so the
   case file reads on its own.
2. A Nextcloud Talk source. The same two commands search Talk messages and
   fetch a Talk hit with its conversation. The search runs as the requesting
   person, through Talk's own unified search provider, so it finds only
   conversations that person is in. The context is read through Nextcloud's
   public `ICommentsManager`, after Talk confirms the person is still a
   participant.
3. The hit envelope gains `platform` (`microsoft-teams` or `nextcloud-talk`)
   and `conversationId` for chat hits.

What it does not do:

- WhatsApp. There is no organisation-side archive to search: a WhatsApp
  Business API sees only its own business number's messages, and personal
  phones are outside any API. Row 1.16 names WhatsApp as an example; Teams and
  Talk are the platforms an organisation archives. The report says so.
- Indexing. Every search stays live.

Fail closed:

- A thread is read with the same grant as the hit: the requester's delegated
  grant for Teams, the requester's own participation for Talk. A person who
  is no longer in a Talk conversation gets the refusal `not-a-participant`
  and no messages.
- System messages and deleted messages are never part of a thread.
- A Talk conversation marked sensitive answers its search snippet cut to the
  term, as Talk's own search does.

App absent:

- Talk not installed: the Talk source answers the notice `talk-not-installed`
  and no hits. The Teams source is unaffected.
- dossiq not installed: nothing dispatches the commands, and nothing changes.
- No Microsoft 365 source linked: the Teams half answers the notice
  `no-connection`, as today.

Wave 2. Waits on `sources-per-user-oauth` (0/9) for the delegated grant, as
the original change does.
