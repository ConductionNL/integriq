---
kind: config
---

# Proposal: zgw-connectors-for-dossiq

## Summary
Packaged connector sets that read a case, its documents, its type, its
decisions and its objects from an external ZGW store (Open Zaak, Objecten
API) into OpenRegister, keep them fresh through Open Notificaties, and write
changes back. A case app then shows a case that lives elsewhere as if it
were its own. Integriq ships the sets; the case app places them.

## Motivation
GZAC consumes external ZGW components through plugins
(`gz/pages/Admin-Plugins.md`, `gz/pages/ZGW-OpenZaak.md`,
`gz/pages/ZGW-Objecten.md`, `gz/pages/ZGW-OpenNotificaties.md`, under
`concurrentie-analyse/procest/_round2/`). dossiq serves ZGW over OpenRegister
(ZRC, ZTC, DRC, BRC, NRC controllers) but consumes nothing; its
`pluggable-integration-registry` is empty (finding B21, M3 ZGW row). The
analysis puts the connectors with integriq (D11).

Integriq has the parts: sources and synchronizations with the `external`
storage strategy, `notificaties-api-connector` for abonnementen, and
`zgw-version-translation` for 1.x versus 1.6 payloads. What is missing is the
packaged, slug-referenced set per component, the way
`vng-klantinteracties-adapter` packages one.

## Scope
- Six configuration sets, ADR-015 slug-referenced: `zgw-zaken`,
  `zgw-documenten`, `zgw-catalogi`, `zgw-besluiten`, `zgw-objecten`
  (Objecten and Objecttypen), `zgw-notificaties` (an abonnement per
  component feeding the existing pipe).
- Each set: a source template, synchronizations into a target register and
  schema the operator picks, mappings both ways that pass the store's own ZGW
  shape through (no translation; design D5).
- A pull on notification, so an external change shows within a minute.
- A write-back synchronization for the resources the case app may edit.

## Out of scope
- The case app's own ZGW schemas and its choice which schema to target.
- The Klanten and Contactmomenten components; `vng-klantinteracties-adapter`
  covers that domain.
