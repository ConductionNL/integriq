# VNG Klantinteracties packaged configuration

This folder (singular `configuration/`, distinct from the informal
Postman-paste `configurations/` folder at the repo root) ships the
machine-importable ADR-015 configuration set for the `vng-klantinteracties-adapter`
change.

- `vng-klantinteracties.oas.json` — a slug-referenced OAS `components` document
  (Endpoints, Mappings, Rules) importable via
  `ConfigurationService::importConfiguration()`. Every cross-entity reference
  (`inputMapping`, `outputMapping`, `rules[]`, `targetId` register/schema
  halves) is expressed as a slug; the import resolves slugs to local UUIDs.
- `vng-klantinteracties-consumer.seed.json` — the KISS Consumer definition.
  **Not** part of the OAS document: `ConfigurationService` has no
  `ConfigurationHandler` for the `consumer` schema (only endpoint / mapping /
  rule / source / job / synchronization round-trip through
  `importConfiguration()`). Create this Consumer separately after importing
  the OAS document, and replace the placeholder `publicKey` / `userId` with
  real values.

## Scope of this pass

Fully detailed (Endpoint + Mapping + Rule wiring): `klantcontacten`
(GET/POST/PUT/PATCH), `partijen` (GET/POST), `betrokkenen` (GET/POST),
`digitaleadressen` (GET/POST), and the composite `maak-klantcontact` (POST).

**Not packaged in this pass:** `actoren`, `onderwerpobjecten`, `internetaken`,
`bijlagen`, and DELETE on every resource. Their canonical schema.org field
mappings depend on schemas the sibling pipelinq `vng-klantinteracties-leaf`
change has not yet defined; inventing them here would be fabrication. The
generic gateway mechanics (composite fan-out, filter/expand translation,
self-URL/HAL, PUT/PATCH semantics, referentienummer, AVG BSN policy) are all
dialect-agnostic and ready to serve these resources once their schemas land —
adding them is then a config-only follow-up (per design.md's stated goal).

See `openspec/changes/archive/2026-07-12-vng-klantinteracties-adapter/tasks.md`
("Deviations") for the composite-rule/dispatch-ordering note on
`vng-maak-klantcontact`.
