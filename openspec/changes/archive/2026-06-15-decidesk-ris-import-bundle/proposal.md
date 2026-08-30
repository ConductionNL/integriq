---
kind: config
---

# Proposal: Decidesk-targeted RIS import bundle (iBabs + Notubiz)

## Problem

OpenConnector already ships iBabs and Notubiz connector *services*
(`IBabsConnectorService`, `NotuBizConnectorService`, from the archived
`ibabs-notubiz-connector` change), but they are oriented at procest zaak-flows
(pushing collegevoorstellen, retrieving besluiten into procest). There is no
declarative configuration bundle that pulls raadsinformatie (RIS) — vergaderingen,
agendapunten, besluiten/moties and stemmen — *into decidesk*.

decidesk's `Meeting`, `AgendaItem`, `Decision` and `Vote` schemas are already
aligned to OpenRaadsinformatie (ORI) / Popolo, so they are the natural import
target. `Motion`/`Amendment`/`Resolution` were folded into the unified `Decision`
schema (`decisionType`) in the decision-model refactor, so a RIS motion/besluit
maps to `Decision` with `decisionType: motion`.

## Proposed Change

Add a declarative OpenConnector configuration bundle under
`configurations/decidesk-ris-import/` (same shape as `xxllnc-zoekendpoint-woo/`):

- **2 Sources** — iBabs (apikey + organisatie-id placeholders) and Notubiz
  (public read API + optional OAuth2 placeholders). Empty credentials,
  `isEnabled: false` (ADR-003 zero-knowledge — no creds committed).
- **8 Mappings** — RIS payloads → decidesk OR object shapes: iBabs
  Vergadering→Meeting, Agendapunt→AgendaItem, Besluit→Decision, Stem→Vote; and
  the four Notubiz equivalents.
- **8 Synchronizations** — `targetType: register/schema`, `targetId:
  decidesk/<schema>`, one per (source × object type), so the sync writes straight
  into decidesk's OR objects.

These are declarative JSON config files imported via the OpenConnector import
flow; no PHP glue is required (the connector services already exist).

## Out of scope / deferred

- **Runtime ingestion** needs per-municipality credentials + a live RIS feed and
  cannot be exercised in CI. Per-instance wiring (creds, real register/schema ids,
  per-run path variables) is documented in the bundle README.
- **GemeenteOplossingen (GO) RIS** — no spec and the API needs investigation; a
  separate proposal (`decidesk-ris-import-go-investigation`) tracks it as a
  ready-to-build, needs-API-investigation backlog item.
