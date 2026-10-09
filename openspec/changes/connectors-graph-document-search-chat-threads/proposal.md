---
kind: code
depends_on: [connectors-graph-document-search, sources-per-user-oauth]
---

# Proposal: connectors-graph-document-search-chat-threads

## Summary

Fetch a Teams chat hit with its conversation, and make Nextcloud Talk a searchable source as the requesting person.

- Rows: closes Woo row 1.16 "Chat and messaging material is a source for a request, and a message keeps the conversation it sat in" (not statutory) for Teams and Talk. WhatsApp is not specified: no organisation-side archive exists to search.
- Wave: 2.
- Depends on: `integriq/connectors-graph-document-search` (https://github.com/ConductionNL/integriq/issues/2541), the first part, and through it `integriq/sources-per-user-oauth` (no issue yet; https://github.com/ConductionNL/integriq/tree/development/openspec/changes/sources-per-user-oauth). Consumer: `dossiq/woo-requests-gather-documents-from-sources` (https://github.com/ConductionNL/dossiq/issues/3160).
- Decision: D1 (2026-10-05), dossiq owns the Woo request and does the gathering.

Build rules: openspec/woo-build-rules.md

## Why

This is the second part of `connectors-graph-document-search`. It was written on 2026-10-05 as an
amendment to that change and split off on 2026-10-06 so each part stays
within 20 tasks. The search and fetch commands and the Microsoft 365 search
(REQ-DCC-008 and REQ-DCC-009, tasks 1 to 4) live in the first part.

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

What this part adds, on top of tasks 1 to 4 of `connectors-graph-document-search` (none of which is built):

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
