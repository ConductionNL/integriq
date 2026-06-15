# Tasks: GemeenteOplossingen (GO) RIS importer — API investigation

> needs-API-investigation — investigation tasks only; no connector is built here.

## 1. API investigation (blocking — do before any build)

- [ ] 1.1 Confirm which GO product exposes RIS data and whether the GO RIS API is distinct from the iBabs API (GO acquired iBabs); record the production base URL.
- [ ] 1.2 Determine the auth model (public read vs keyed/OAuth2) and the per-municipality identifiers required.
- [ ] 1.3 Document the read endpoints for vergaderingen, agendapunten, besluiten/moties and stemmen, with their pagination/results envelope (`resultsPosition`, `idPosition`).
- [ ] 1.4 Capture sample payloads and the source field names per object type so Twig mappings can be written.
- [ ] 1.5 Determine whether GO exposes individual votes (stemmen) or only aggregate tallies.

## 2. Build (deferred — only after section 1 is answered)

- [ ] 2.1 Add a GO Source under `configurations/decidesk-ris-import/` with empty credential placeholders (ADR-003).
- [ ] 2.2 Add GO mappings → decidesk Meeting/AgendaItem/Decision/Vote.
- [ ] 2.3 Add GO synchronizations with `targetType: register/schema` → `decidesk/<schema>`.
