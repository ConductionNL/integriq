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
