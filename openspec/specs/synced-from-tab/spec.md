---
status: done
---

# synced-from-tab Specification

## Purpose
Renders a synced-from tab inside OpenRegister that shows the synchronization provenance of the current object. It resolves the per-object sync-contract sub-resource endpoint (preferring an injected apiBase, otherwise the OpenRegister API path scoped to the object's register, schema, and id), fetches and normalizes the provenance rows, and degrades quietly by flagging the surface unavailable on a 5xx or network failure rather than throwing.

@e2e exclude integration-helper internals (endpoint-URL resolution, response normalisation, quiet-degrade flag) for a sub-resource tab rendered inside OpenRegister — not an integriq SPA surface; covered by Vue component/unit tests

## Requirements
### Requirement: Resolve synced-from source endpoint (REQ-SYNC-001)
The system SHALL resolve the per-object synchronization sub-resource endpoint for the synced-from tab, preferring an injected `apiBase` when present and otherwise falling back to the OpenRegister API path, scoped to the current object's register, schema, and id under the `sync-contract` integration.

#### Scenario: apiBase is honoured when provided
- **GIVEN** an injected `apiBase`
- **WHEN** the endpoint is resolved
- **THEN** the endpoint MUST be built from that `apiBase` and the object's register/schema/id

#### Scenario: Falls back to OpenRegister API path
- **GIVEN** no injected `apiBase`
- **WHEN** the endpoint is resolved
- **THEN** the endpoint MUST be built from the OpenRegister API base path

### Requirement: Fetch synced-from rows (REQ-SYNC-002)
The system SHALL fetch the synchronization provenance rows for the current object from the resolved endpoint, normalize the response into a list of rows, and on a 5xx or network failure degrade quietly by flagging the surface unavailable rather than throwing.

#### Scenario: Successful fetch populates rows
- **GIVEN** an endpoint returning provenance data
- **WHEN** rows are fetched
- **THEN** the rows MUST be populated from the response

#### Scenario: Failure degrades quietly
- **GIVEN** an endpoint returning a 5xx or network error
- **WHEN** rows are fetched
- **THEN** the unavailable flag MUST be set and no error MUST propagate

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
