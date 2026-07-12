---
status: done
---

# vng-klantinteracties-adapter Specification

## Purpose

OpenConnector serves the VNG Klantinteracties API (OpenKlant 2.x, OpenAPI
v0.8.0) — `klantcontacten`, `partijen`, `betrokkenen`, `digitaleadressen`,
`actoren`, `onderwerpobjecten`, `internetaken`, `bijlagen` and the composite
`POST /maak-klantcontact` — as a **packaged, ADR-015 slug-referenced
configuration set** (Endpoints, Mappings, Rules, Consumers) over pipelinq's
canonical English schema.org CRM schemas. No Dutch-specific storage schema is
introduced: the Dutch API is a mapping leaf over international storage
(pipelinq ADR-001 — "data storage uses international standards; Dutch government
standards are an API mapping layer"). This capability is the OpenKlant-2 interop
that municipal KISS-based tenders make mandatory — per Specter research
2026-07-12 the biggest strategic gap for the CRM/KCC line. It composes the
generic gateway features added to `endpoint-runtime`, `mapping-and-search` and
`rule-pipeline`. Consumed by the pipelinq `vng-klantinteracties-leaf` change.
See ADR-008 (polymorphic targetType), ADR-015 (configuration export/import),
and hydra ADR-031 (external-integration exception).

@e2e exclude backend gateway capability (configuration set + endpoint dispatch) — covered by PHPUnit/Newman, no browser UI

**OpenSpec changes**
- `vng-klantinteracties-adapter` (done) — added the packaged VNG Klantinteracties
  configuration set (Endpoints/Mappings/Rules; Consumer shipped separately —
  see Notes), the composite `maak-klantcontact` transactional endpoint, the AVG
  BSN policy (hash-only inbound, no raw outbound), and KISS read/write interop
  (partijIdentificator filters + expand=). Fully packaged for `klantcontacten`,
  `partijen`, `betrokkenen`, `digitaleadressen`, and the composite
  `maak-klantcontact`; `actoren`/`onderwerpobjecten`/`internetaken`/`bijlagen`
  deferred pending the sibling pipelinq `vng-klantinteracties-leaf` change's
  schemas (see Notes).
  Archived at `openspec/changes/archive/2026-07-12-vng-klantinteracties-adapter/`.

## Requirements

### Requirement: Packaged VNG Klantinteracties configuration set (REQ-001)

The system MUST ship the VNG Klantinteracties dialect as a self-contained
OpenConnector configuration set — Endpoints (one per VNG resource plus the
composite `maak-klantcontact`), input and output Mappings (VNG ↔ canonical
schema.org), Rules (composite fan-out, AVG BSN policy, self-URL/HAL,
`referentienummer`), and at least one Consumer — exported through
`ConfigurationService` as an ADR-015 slug-referenced OpenAPI-Specification
document. Every cross-entity reference in the exported document MUST be expressed
as a slug (not a local UUID), and consumer credentials MUST be redacted to
placeholders.

@e2e exclude backend configuration set (ConfigurationService export/import + endpoint dispatch) — covered by PHPUnit/Newman, no browser UI

#### Scenario: Config set exports as a slug-referenced OAS document
- GIVEN the packaged VNG Klantinteracties Endpoints, Mappings, Rules and Consumer exist in the `openconnector` register
- WHEN an operator exports the configuration set via `ConfigurationService`
- THEN the produced OAS document references every Endpoint→Mapping and Endpoint→Rule link by slug (e.g. `vng-klantcontacten` → `vng-klantcontact-in`)
- AND the Consumer's key material is redacted to a placeholder, not a live secret

#### Scenario: Config set imports into a clean environment
- GIVEN a target environment with pipelinq's `ticket`/`client`/`contact`/`task` schemas present
- WHEN the VNG Klantinteracties OAS configuration document is imported
- THEN the Endpoints, Mappings, Rules and Consumer are recreated with slugs resolved back to local UUIDs and the VNG surface responds under `/api/endpoint/klantinteracties/...`

### Requirement: Composite maak-klantcontact endpoint is transactional (REQ-002)

The system MUST expose `POST /maak-klantcontact` as an Endpoint guarded by a
composite fan-out Rule that, from one request body, creates a `klantcontact`
plus its related `betrokkenen`, `digitaleadressen` and `onderwerpobjecten` as a
single logical transaction. If any child write fails, the whole operation MUST
roll back so no orphaned `klantcontact` is persisted, and MUST return a single
error response.

@e2e exclude backend composite endpoint — covered by PHPUnit/Newman, no browser UI

#### Scenario: Composite create fans out and returns the klantcontact
- GIVEN a `POST /maak-klantcontact` body containing a klantcontact, a betrokkene and a digitaalAdres
- WHEN the composite Rule runs
- THEN a `ticket` (ticketType=contactmoment), a linked `contact` (betrokkene) and its `email`/`phone` (digitaalAdres) are created, and the response is the created klantcontact with an absolute `url` and a `referentienummer`

#### Scenario: Child write failure rolls back the whole composite
- GIVEN a `POST /maak-klantcontact` where the digitaalAdres write fails validation
- WHEN the composite Rule runs
- THEN no `klantcontact` (ticket) is left persisted and a single error response is returned

### Requirement: AVG BSN policy — hash inbound, never reconstruct outbound (REQ-003)

The system MUST, on every path handling a `partijIdentificator` of BSN type,
validate the BSN (11-proef) and SHA-256-hash it via pipelinq's BRP flow before
any storage, and MUST NEVER render a raw BSN outbound — outbound `partij`
representations MUST omit the raw `objectId` or return a hash-backed identity.
This is a documented, intentional deviation from VNG's raw-BSN expectation.

@e2e exclude backend AVG policy rule — covered by PHPUnit, no browser UI

#### Scenario: Inbound BSN is validated and hashed before storage
- GIVEN a `partij` create carrying `partijIdentificator` of soort BSN with value `999993653`
- WHEN the AVG BSN policy Rule runs before the write
- THEN the BSN passes 11-proef, is SHA-256-hashed, and only the hash-backed identity is persisted (no raw BSN stored)

#### Scenario: Invalid BSN is rejected before any write
- GIVEN a `partij` create whose `partijIdentificator` BSN fails 11-proef
- WHEN the AVG BSN policy Rule runs
- THEN the request is rejected with a validation error and nothing is persisted

#### Scenario: Outbound partij never exposes a raw BSN
- GIVEN a stored partij whose identity is a hashed BSN
- WHEN the partij is rendered outbound
- THEN the response omits the raw BSN `objectId` (or returns a hash-backed identity), never reconstructing the raw value

### Requirement: KISS read/write interop with partijIdentificator filters and expand (REQ-004)

The system MUST support the KISS read/write subset — `klantcontacten`,
`betrokkenen`, `digitaleadressen` — including list queries filtered by
`partijIdentificator` (translated onto OpenRegister search) and `expand=`
relation embedding, returning VNG-shaped resources with absolute self-URLs.

@e2e exclude backend interop — covered by Newman, no browser UI

#### Scenario: List partijen filtered by partijIdentificator returns VNG shape
- GIVEN stored partijen with hashed BSN identities
- WHEN a client requests `GET /partijen?partijIdentificator__codeSoortObjectId=bsn&expand=digitaleAdressen`
- THEN the filter is translated onto OpenRegister search, `digitaleAdressen` are embedded, and each partij carries an absolute `url` self-link

#### Scenario: Klantcontact read/write round-trips through canonical storage
- GIVEN a klantcontact created via the VNG endpoint
- WHEN it is read back via `GET /klantcontacten/{uuid}`
- THEN the canonical `ticket` fields are mapped back to the VNG `klantcontact` shape (onderwerp, kanaal, plaatsgevondenOp) with a stable absolute `url`

## Non-Functional Requirements

- **Performance:** `expand=` embedding bounds its OpenRegister search cost (documented depth cap 2) so a single list call does not fan out unboundedly.
- **Accessibility:** N/A — backend gateway capability, no browser UI.
- **Internationalization:** Dutch and English are supported (hydra ADR-007) for the endpoint runtime itself; the new gateway-mechanic error messages (filter translation, composite fan-out, AVG BSN) are raw English `Exception` messages, not yet localised — flagged as a follow-up.

## Acceptance Criteria

- [x] The VNG Klantinteracties config set exports and re-imports as a slug-referenced OAS document (ADR-015) — Endpoints/Mappings/Rules; Consumer ships as a separate seed file (see Notes).
- [x] `POST /maak-klantcontact` is transactional (rollback on child failure) — `CompositeFanoutRule`, fully unit-tested.
- [x] No raw BSN is ever stored or rendered outbound; inbound BSNs are 11-proef-validated and SHA-256-hashed.
- [x] `partijIdentificator` filters and `expand=` return correct VNG-shaped resources with absolute self-URLs.

## Notes

- **Consumer is not part of the ADR-015 OAS document.** `ConfigurationService` has
  no `ConfigurationHandler` for the `consumer` schema — only `source`, `mapping`,
  `rule`, `endpoint`, `synchronization`, `job` round-trip through
  `importConfiguration()`. The `vng-kiss-consumer` definition ships as
  `configuration/vng-klantinteracties-consumer.seed.json` and must be created via
  the Consumers UI/API after the OAS document is imported.
- **`actoren`, `onderwerpobjecten`, `internetaken`, `bijlagen` are not packaged
  in this pass** — their canonical schema.org field mappings depend on schemas
  the sibling pipelinq `vng-klantinteracties-leaf` change has not yet defined.
  The generic gateway mechanics this capability composes are dialect-agnostic
  and ready to serve them; adding them is a config-only follow-up.
- **BRP flow delegation.** REQ-003 as originally proposed described hashing "via
  pipelinq's BRP flow." pipelinq's BRP verification flow is a leaf-side (sibling
  repo) concern not accessible from openconnector; this producer-side
  `AvgBsnPolicyRule` performs the 11-proef checksum + SHA-256 hash directly
  (dialect-agnostic gateway policy), which does not require a BRP lookup. Full
  BRP-backed verification remains the leaf's responsibility.
- Depends (cross-repo, not via `depends_on`) on the pipelinq
  `vng-klantinteracties-leaf` change for the canonical schema contract, the full
  AVG BSN/BRP flow, and the actor-bridge schema.
- The generic mechanics this capability composes are specified under
  `endpoint-runtime` (REQ-EP-006/007), `mapping-and-search` (REQ-006/007), and
  `rule-pipeline` (REQ-RULE-006/007).
