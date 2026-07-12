---
kind: code
depends_on: []
---

# Proposal: vng-klantinteracties-adapter

## Summary

Add the generic gateway machinery OpenConnector needs to front any VNG REST
dialect, and ship the packaged **VNG Klantinteracties** (OpenKlant 2.x,
OpenAPI v0.8.0) configuration set as the first consumer. Five dialect-agnostic
gateway capabilities are added — a composite transactional fan-out Rule type, VNG
list-filter + `expand=` query translation onto OpenRegister search, an absolute
self-URL / HAL `_links` rendering helper, PUT-all-mandatory vs PATCH-partial
enforcement, and `referentienummer` generation — plus a packaged, ADR-015
slug-referenced configuration set (Endpoints, Mappings, Rules, Consumers) that
maps the Klantinteracties API onto pipelinq's canonical English schema.org CRM
schemas. The Dutch API becomes a thin mapping leaf over international storage; no
Dutch-specific domain model is added to storage. Evidence: Specter research
2026-07-12.

## Motivation

VNG Klantinteracties / OpenKlant 2.x interop is, per the Specter insight, "the
biggest strategic gap" for the CRM/KCC product line — a procurement gate that
KISS-based municipal tenders make mandatory. Pipelinq already stores contact
moments, clients, contacts and tasks in canonical English schema.org-aligned
schemas; what is missing is the Dutch API surface (`klantcontacten`,
`partijen`, `betrokkenen`, `digitaleadressen`, `internetaken`, the composite
`POST /maak-klantcontact`) that KISS and other municipal front-ends speak.

The correct place for that surface is OpenConnector, not pipelinq: OpenConnector
already owns endpoint dispatch (ADR-008 polymorphic `targetType`), the mapping
engine, the rule pipeline, consumer authorization (JWT/apiKey), and ADR-015
configuration export/import. The `stuf-adapter`, `digikoppeling-adapter` and
`psd2-ais-bank-feed-connector` capabilities set the precedent: a Dutch/legacy
dialect is a packaged OpenConnector configuration plus the small amount of
generic gateway code it needs. This change follows that pattern and, crucially,
implements the generic pieces (filters, `expand=`, self-URLs, PUT/PATCH
semantics) so that every future VNG REST dialect — zaken, contactmomenten v1,
klachten — reuses them rather than re-implementing them.

This realises the pipelinq architecture principle (ADR-001,
`docs/Technical/architecture.md`): **"Data storage uses international standards.
Dutch government standards are an API mapping layer."**

## Affected Projects

- [x] Project: `openconnector` — new generic gateway features (composite Rule
  type, VNG filter/`expand=` translation, self-URL/HAL helper, PUT/PATCH
  enforcement, `referentienummer` generation) + the packaged VNG Klantinteracties
  configuration set (Endpoints/Mappings/Rules/Consumers as ADR-015 export config)
- [ ] Project: `pipelinq` — consumes this change via the sibling leaf change
  `vng-klantinteracties-leaf` (canonical schema contract + AVG BSN policy + actor
  bridge). Tracked separately; see Cross-Project Dependencies.

## Scope

### In Scope

- **Composite transactional fan-out Rule type** — a Rule that, on `POST
  /maak-klantcontact`, creates a `klantcontact` and its related `betrokkenen`,
  `digitaleadressen` and `onderwerpobjecten` in one logical transaction, rolling
  back partial writes on failure.
- **VNG list-filter + `expand=` query translation** — translate VNG query
  semantics (`partijIdentificator__codeSoortObjectId`, double-underscore lookup
  operators, `expand=` relation embedding) onto OpenRegister search filters and
  facets, in `mapping-and-search`.
- **Absolute self-URL / HAL `_links` rendering helper** — a generic output helper
  that renders absolute `url` self-links and HAL `_links` on emitted resources,
  as VNG clients expect absolute URLs as canonical identifiers.
- **PUT-all-mandatory vs PATCH-partial enforcement** — enforce VNG's contract
  that PUT requires all mandatory fields while PATCH is a partial update, in the
  endpoint dispatch pipeline.
- **`referentienummer` generation** — a helper/Rule that stamps a unique message
  reference on emitted resources/responses.
- **Packaged VNG Klantinteracties configuration set** — Endpoints, Mappings,
  Rules and Consumers for `klantcontacten`, `partijen` (+`partijIdentificatoren`),
  `betrokkenen`, `digitaleadressen`, `actoren`, `onderwerpobjecten`,
  `internetaken`, `bijlagen`, and the composite `maak-klantcontact`, exported as
  an ADR-015 slug-referenced OAS configuration document that ships with the app.
- **AVG BSN policy Rule** — validate + SHA-256-hash inbound `partijIdentificator`
  BSNs via pipelinq's BRP flow; never reconstruct a raw BSN outbound (omit
  `objectId` or return a hash-backed identity). A documented deviation from VNG's
  raw-BSN expectation.

### Out of Scope

- Any change to pipelinq's canonical storage schemas — that is the sibling leaf
  change's job (`vng-klantinteracties-leaf`). Storage stays international.
- The actor-registry bridge object (VNG `actor` ↔ Nextcloud UID assignee) — it
  lives on the pipelinq (leaf) side as a schema; this change only references the
  actor identity in mappings.
- Klantinteracties v1 (contactmomenten) and other VNG dialects (zaken, klachten)
  — the generic gateway features are built to serve them later, but no config set
  for them is shipped here.
- A dedicated SPA panel for the VNG config — it is managed through the existing
  Endpoints / Mappings / Rules / Consumers UIs.

## Approach

The five generic features extend the existing `endpoint-runtime`,
`mapping-and-search` and `rule-pipeline` capabilities rather than introducing a
parallel framework, mirroring how `psd2-ais-bank-feed-connector` builds on
`source-management` / `job-scheduling` / `events-cloudevents`. The VNG
Klantinteracties dialect is then expressed almost entirely as **configuration**:
Endpoints of `targetType: register/schema` pointing at pipelinq's register, with
input mappings (VNG → canonical) and output mappings (canonical → VNG), guarded
by Rules (composite fan-out, AVG BSN policy, `referentienummer`). The config set
is authored once and exported through `ConfigurationService` (ADR-015) as a
slug-referenced OAS document that ships as seed data. Details in design.md.

## New Dependencies

None. No new PHP packages or external services; the change reuses
`EndpointService`, `MappingService`, `RuleProcessingService`,
`ConfigurationService`, and consumer authorization.

## Impact

- `EndpointService` dispatch + response normalisation (self-URL/HAL rendering;
  PUT/PATCH semantics).
- `MappingService` / search filter compiler (VNG filter operators + `expand=`).
- `RuleProcessingService` + Rule types (composite fan-out; `referentienummer`;
  AVG BSN policy).
- The `openconnector` register gains the packaged VNG config objects (seed).
- Downstream: pipelinq (via `vng-klantinteracties-leaf`) and any municipal
  KISS/Klantinteracties client.

## Cross-Project Dependencies

This change is the **producer** half of a two-repo pair. The **consumer** is the
pipelinq change `vng-klantinteracties-leaf`, which adds the canonical-schema
mapping contract, the AVG BSN policy requirement, the actor-bridge schema, and a
`register.d/82-vng-klantinteracties.json` fragment referencing the Endpoint /
Mapping / Rule slugs defined here. Because the two live in separate repositories,
the dependency is documented here and in the leaf proposal rather than expressed
in `depends_on` frontmatter (which is single-repo only). The leaf change MUST NOT
be applied before the slugs in this change's configuration set are stable.

## Risks

### Risk 1: AVG / BSN raw-value leakage

**Severity:** High — **Mitigation:** The AVG BSN policy Rule is mandatory on
every path that touches `partijIdentificator`: inbound BSNs are validated
(11-proef) and SHA-256-hashed via pipelinq's BRP flow before storage; outbound
rendering NEVER reconstructs a raw BSN (the `objectId` is omitted or replaced by
a hash-backed identity). The deviation from VNG's raw-BSN expectation is
documented in the spec and design so it is a conscious, reviewable decision, not
an accident.

### Risk 2: Composite fan-out leaves partial data on failure

**Severity:** Medium — **Mitigation:** The composite `maak-klantcontact` Rule is
specified to be transactional — a failure in any child write (betrokkene,
digitaal adres, onderwerpobject) rolls back the whole operation and returns a
single error, so no orphaned `klantcontact` is left behind.

### Risk 3: Generic features over-fitted to Klantinteracties

**Severity:** Medium — **Mitigation:** The filter/`expand=`/self-URL/PUT-PATCH
features are specified against `endpoint-runtime` / `mapping-and-search` /
`rule-pipeline` as dialect-agnostic behaviour with Klantinteracties as the first
consumer, and reviewed against the zaken/contactmomenten-v1 dialects as future
consumers to avoid Klantinteracties-only assumptions.

### Risk 4: Slug drift between this config set and the pipelinq leaf

**Severity:** Low — **Mitigation:** The Endpoint/Mapping/Rule slugs are frozen in
this change's design.md and referenced verbatim from the leaf fragment; the leaf
change is gated on slug stability (see Cross-Project Dependencies).

## Rollback Strategy

The generic gateway features are additive and guarded by the presence of the VNG
config; removing the packaged configuration set (deleting the seeded
Endpoints/Mappings/Rules/Consumers, or not importing the OAS config document)
disables the VNG surface without affecting other endpoints. The code additions
are behind the new Rule types and output-helper opt-ins and can be reverted by
git revert of this change without touching existing endpoint behaviour.

## Open Questions

- Should `referentienummer` be a UUID or a KISS-style structured reference? The
  design assumes a UUIDv4 unless a municipality supplies a numbering scheme; the
  leaf change may refine this.
