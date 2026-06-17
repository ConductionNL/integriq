---
kind: config
status: needs-api-investigation
---

# Proposal: GemeenteOplossingen (GO) RIS importer — API investigation

> **STATUS: needs-API-investigation.** This is a tracked, ready-to-build backlog
> item, NOT an implementation. No GO connector is fabricated here because the GO
> RIS API has not yet been investigated. Do not build a Source/Mapping until the
> open questions below are answered against a real GO instance.

## Problem

The decidesk RIS import bundle (`decidesk-ris-import-bundle`) covers iBabs and
Notubiz. GemeenteOplossingen (GO) is the third major Dutch raadsinformatiesysteem
vendor, but unlike iBabs/Notubiz there is **no existing connector service** in
OpenConnector and **no documented API surface** in this repo. We must not
fabricate endpoints or field names — they have to be investigated first.

## What a GO RIS importer would need (to be built once investigated)

Mirroring the iBabs/Notubiz bundle, the eventual GO bundle would add:

- A **Source** for the GO RIS API (auth model TBD — investigate whether GO offers
  a public read API like Notubiz or a keyed API like iBabs; empty credential
  placeholders, ADR-003 zero-knowledge).
- **Mappings** GO RIS payload → decidesk `Meeting` / `AgendaItem` / `Decision`
  (`decisionType: motion`) / `Vote`.
- **Synchronizations** with `targetType: register/schema` → `decidesk/<schema>`,
  one per (object type).

## Open questions to investigate (blocking)

1. **Base URL + product**: Which GO product exposes RIS data (e.g. iBabs is now
   part of GO's portfolio — confirm whether GO RIS == iBabs API or a distinct GO
   API)? What is the production base URL?
2. **Auth model**: Public read (like Notubiz) or keyed/OAuth2 (like iBabs)? What
   per-municipality identifiers are required?
3. **Endpoints**: The read endpoints for vergaderingen, agendapunten,
   besluiten/moties and stemmen, and their pagination/results envelope (the
   `resultsPosition`/`idPosition` for the sync config).
4. **Payload field names**: Source field names per object so the Twig mappings can
   be written (titel/datum/locatie equivalents; status→outcome enum values;
   vote-value vocabulary).
5. **Coverage**: Whether GO exposes individual votes (stemmen) or only aggregate
   tallies (affects whether a Vote mapping is feasible).

## Out of scope

- Any GO Source/Mapping/Synchronization JSON (deliberately omitted until the API
  is investigated — fabricating one would commit unverified endpoints).
