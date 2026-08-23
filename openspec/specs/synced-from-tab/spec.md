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

