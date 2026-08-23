# Decidesk RIS import bundle

Integriq configuration bundle that imports **raadsinformatie (RIS)** data —
vergaderingen (meetings), agendapunten (agenda items), besluiten/moties
(motions/decisions) and stemmen (votes) — from the **iBabs** and **Notubiz**
raadsinformatiesystemen straight into **decidesk's** OpenRegister objects.

decidesk's `Meeting`, `AgendaItem`, `Decision` and `Vote` schemas are already
aligned to OpenRaadsinformatie (ORI) / Popolo, so they are the import target.
`Motion`/`Amendment`/`Resolution` were folded into the unified `Decision` schema
in the decision-model refactor, so RIS besluiten/moties map to
`Decision` with `decisionType: motion`.

## Contents

The JSON files here follow the Integriq `configurations/` convention (same
shape as `xxllnc-zoekendpoint-woo/`). They are meant to be imported via the
Integriq import UI / API (copy/paste into a Postman request that creates
that object type). **No credentials are committed** (ADR-003 zero-knowledge);
every source ships with empty placeholder credentials and `isEnabled: false`.

### sources/
- `ibabs-ris-v1.json` — iBabs RIS REST API. Placeholders: `headers.Authorization` (API key) + `organisatieId`.
- `notubiz-ris-v1.json` — Notubiz public read API (`https://api.notubiz.nl`). Placeholders: `organisation_id` + optional `oauth.*` block for gated feeds.

### mappings/
RIS payload → decidesk OR object shape (Twig mappings, lenient on source field names):
- iBabs: Vergadering→Meeting, Agendapunt→AgendaItem, Besluit→Decision, Stem→Vote
- Notubiz: Event→Meeting, Agenda Item→AgendaItem, Decision→Decision, Vote→Vote

### synchronizations/
`targetType: register/schema`, `targetId: decidesk/<schema>` so the sync writes
straight into decidesk's OR objects. One sync per (source × object type), 8 total.

## Per-instance wiring (the only deferred step — needs creds)

The mappings, syncs and sources are complete and import-ready. To actually run an
ingestion you must, **per municipality**:

1. Fill the source credentials (`headers.Authorization` + `organisatieId` for iBabs;
   `organisation_id` (+ optional OAuth2) for Notubiz) and set `isEnabled: true`.
2. Replace the `targetId` placeholder `decidesk/<schema>` with this instance's real
   OpenRegister register + schema ids (e.g. `42/137`). Integriq resolves
   `register/schema` by id pair; the `decidesk/<schema>` form documents intent.
3. Supply the per-run path variables (`{{vergaderingId}}`, `{{besluitId}}`,
   `{{event_id}}`, `{{decision_id}}`) for the child syncs.

Runtime ingestion cannot be exercised without per-municipality credentials and a
live RIS feed — that is the only part not validated here.
