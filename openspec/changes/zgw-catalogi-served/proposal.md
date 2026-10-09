---
kind: code
depends_on: []
---

# Proposal: zgw-catalogi-served

## Summary

Serve the read half of the VNG Catalogi API (`catalogussen` and `informatieobjecttypen`) over the OpenRegister schema that holds the organisation's document types, so a Documenten API needs no second catalogue.

- Rows: Woo row 17.18 "The product serves the catalogue a standard document store needs, so no second catalogue is kept" (not statutory).
- Wave: 2.
- Depends on: `filinq/document-register` (outside this plan; https://github.com/ConductionNL/filinq/issues/481) for the `documentType` schema, and reuses `integriq/objecten-api-facade` (no issue; https://github.com/ConductionNL/integriq/tree/development/openspec/changes/objecten-api-facade).
- Decision: none governs this row directly; the TOOI source for the information categories follows D3 (2026-10-05), the TOOI lists live once, in OpenRegister's concept register.

Build rules: openspec/woo-build-rules.md

## Overview

Integriq serves the read half of the VNG Catalogi API for document types:
`catalogussen` and `informatieobjecttypen`, as endpoint configuration over the
OpenRegister schema that holds the organisation's document types (filinq's
`documentType`). A Documenten API, ours or another supplier's, can then point
each document's `informatieobjecttype` at a URL we serve, and the organisation
keeps one list of document types instead of a second catalogue. The Woo
information categories travel on each type as `informatieobjectcategorie`,
and a starter set gives one type per Woo category.

## Why

Woo capability row 17.18, "The product serves the catalogue a standard
document store needs, so no second catalogue is kept". Our column reads `no`:
"nothing serves a ZGW Catalogi API (informatieobjecttypen) that a Documenten
API could use. Nearest: integriq lib/Settings/configurations/zgw-catalogi.json
is a consumer set that pulls case types FROM an external Catalogi API
(writesBack false), and opencatalogi CaseTypeCatalogueService imports a
published case type catalogue; neither is served as a catalogue". The gap
register names the missing half: "Serve a ZGW Catalogi API
(informatieobjecttypen, including the Woo information categories) over
OpenRegister so a Documenten API can reference it and no second catalogue is
kept". Build plan: new spec, wave 2, size S.

## Why integriq

ADR-091 section 6 puts national standards (ZGW, StUF, DSO, Notificaties) in
integriq, and `objecten-api-facade` already serves the Objecten and
Objecttypen APIs this way: a credential-checked endpoint over OpenRegister
configuration, no controller in a leaf app. This change copies that pattern
(`lib/Service/Objecten/`, design D7 of that change) for one more component.
`ZgwSetCatalogue` names components, never app ids; this change keeps that
rule: the operator binds the facade to a register and schema, and no code
names filinq.

## What changes

1. A binding: an admin-only `catalogi_binding` configuration in the integriq
   register naming the catalogue (`domein`, `rsin`, `naam`), and the register
   and schema that hold document types, with a field map from that schema to
   the `InformatieObjectType` resource. A default field map for filinq's
   `documentType` (`name` to `omschrijving`, `confidentiality` to
   `vertrouwelijkheidaanduiding`, `category` to `informatieobjectcategorie`,
   `active` to `concept` inverted, `identifier` kept).
2. Read endpoints, Catalogi API 1.3 shape: `GET /catalogi/api/v1/catalogussen`,
   `/catalogussen/{uuid}`, `/informatieobjecttypen`,
   `/informatieobjecttypen/{uuid}`, with the filters `catalogus` and `status`
   (`definitief` default, `concept`, `alles`) and the paged envelope `count`,
   `next`, `previous`, `results`. Every `url` is the absolute URL we serve.
3. ZGW JWT authentication with the scope `catalogi.lezen` per client, through
   integriq's existing `jwt-zgw` auth. Writes answer 405. Concept types are
   served only to a client whose authorisation allows concept reads.
4. A Woo starter set: an admin action that creates, in the bound schema, one
   document type per Woo information category that has none yet, with
   `informatieobjectcategorie` set to the category's TOOI label. The category
   list is read from the TOOI informatiecategorie scheme in OpenRegister's
   concept register (decision D3: the TOOI lists live there once).

## What does not change

- The Catalogi consumer set (`zgw-catalogi.json`) that pulls case types from
  someone else's catalogue.
- `zaaktypen`, `besluittypen`, `statustypen`, `resultaattypen` and the rest of
  the Catalogi API. Row 17.18 needs the document types; case types are
  dossiq's catalogue and a separate row.
- Where document types are authored. filinq's `documentType` schema stays the
  one list; nothing is copied.

## Fail closed

- No binding, or a binding whose schema does not resolve: every route answers
  404 with a VNG problem body `catalogue-not-configured`. Nothing else leaks.
- A token without `catalogi.lezen`: 403, no rows.
- Any write method: 405.
- A document type with status concept is invisible unless the client may read
  concepts.
- The starter set never overwrites or deletes an existing type, and refuses
  to run when the TOOI scheme cannot be read, instead of seeding from a copy.

## App absent

- filinq absent: there is no `documentType` schema to bind, so the facade is
  unconfigured and answers `catalogue-not-configured`. An operator may bind
  any other schema with the same fields.
- The TOOI scheme absent from OpenRegister's concept register: the starter
  set refuses with `tooi-scheme-missing`; the endpoints keep serving whatever
  types exist.

## Dependencies

- `filinq/document-register` (0/10, outside this plan): it adds the
  `documentType` schema the default field map reads. Build after it lands, or
  test against a fixture schema with the same fields and record that in the
  PR body.
- `objecten-api-facade` (17 of 25 tasks done): its token, gateway and wiring
  pattern is reused, not rebuilt.
- Wave 2. No Ruben decision governs this row directly; the TOOI source follows
  D3.
