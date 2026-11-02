## ADDED Requirements

### Requirement: A record says where it was synchronized from

`GET /api/provenance` with `register`, `schema` and `id` SHALL first read the record under the caller's OpenRegister rights and answer 404 when the caller cannot read it. For a readable record that no synchronization contract targets it SHALL answer `synced: false`. Otherwise it SHALL answer `source` (the source's name, via Integriq), `sourceRecord` (the contract's `originId`), `lastSynced`, the `synchronization` (id and name), an `owner` sentence, `recent` (the five newest contract log entries: `created`, `result`, `message`), `logTotal` and `logSummary`.

#### Scenario: a case synchronized from the case system

@e2e exclude needs a synchronization run into dossiq; covered by RecordProvenanceServiceTest and the dossiq tab e2e once seeded.

- **GIVEN** a contract targeting case 2026-0082 from origin ZAAK-2026-0082 of source "Zaaksysteem Centric", with 14 log entries
- **WHEN** a case handler who may read the case asks for its provenance
- **THEN** the answer names the source, ZAAK-2026-0082, the last synchronization, five log entries and a total of 14

#### Scenario: a record the caller cannot read

@e2e exclude a rights check; covered by RecordProvenanceControllerTest.

- **GIVEN** a record outside the caller's rights
- **WHEN** they ask for its provenance
- **THEN** the answer is 404 and no contract is read
