# Tasks: Decidesk-targeted RIS import bundle

## 1. Sources

- [x] 1.1 `configurations/decidesk-ris-import/sources/ibabs-ris-v1.json` — iBabs RIS REST API, apikey auth, empty `headers.Authorization` + `organisatieId` placeholders, `isEnabled: false`.
- [x] 1.2 `configurations/decidesk-ris-import/sources/notubiz-ris-v1.json` — Notubiz public read API (`https://api.notubiz.nl`), empty `organisation_id` + optional `oauth.*` placeholders, `isEnabled: false`.

## 2. Mappings (RIS payload → decidesk OR object shape)

- [x] 2.1 iBabs Vergadering → Meeting
- [x] 2.2 iBabs Agendapunt → AgendaItem
- [x] 2.3 iBabs Besluit → Decision (`decisionType: motion`)
- [x] 2.4 iBabs Stem → Vote
- [x] 2.5 Notubiz Event → Meeting
- [x] 2.6 Notubiz Agenda Item → AgendaItem
- [x] 2.7 Notubiz Decision → Decision (`decisionType: motion`)
- [x] 2.8 Notubiz Vote → Vote

## 3. Synchronizations (targetType register/schema → decidesk)

- [x] 3.1 iBabs vergaderingen → decidesk Meetings
- [x] 3.2 iBabs agendapunten → decidesk AgendaItems
- [x] 3.3 iBabs besluiten → decidesk Decisions
- [x] 3.4 iBabs stemmen → decidesk Votes
- [x] 3.5 Notubiz events → decidesk Meetings
- [x] 3.6 Notubiz agenda_items → decidesk AgendaItems
- [x] 3.7 Notubiz decisions → decidesk Decisions
- [x] 3.8 Notubiz votes → decidesk Votes

## 4. Validation + docs

- [x] 4.1 All bundle JSON parses and matches the existing `configurations/` bundle shape (sources/mappings/synchronizations key parity vs `xxllnc-zoekendpoint-woo`).
- [x] 4.2 Bundle README documents per-instance wiring (credentials, real register/schema ids, per-run path variables) as the only deferred runtime step.

## 5. Deferred (needs creds / separate change)

- [ ] 5.1 Per-municipality runtime ingestion run — needs real credentials + live RIS feed (cannot be exercised in CI).
- [ ] 5.2 GemeenteOplossingen RIS importer — tracked in `decidesk-ris-import-go-investigation` (needs-API-investigation).
