---
kind: code
---

# Proposal: objecten-api-facade

## Summary

Serve the VNG Objecten API and Objecttypen API from integriq, as endpoint
configuration over OpenRegister registers and schemas. An objecttype is a
schema, an object is an object, the token and its per-objecttype
permissions are integriq's, and no leaf app ships a controller for either
standard.

## Motivation

Competitor gap register, row 12.3 "Objecten and Objecttypen API"
(`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, statutory, size M. Opened by the last sweep of the
OpenSpec phase.

The best competitor, verbatim from the register's `best` column:
"GZAC/Valtimo: `backend/zgw/objecten-api/`
(`_round2/compare/M1-functionality.md`)". The register's note on dossiq:
"nearest `dossiq_register.json#caseObject`". So dossiq models the domain
and nothing serves the standard.

The row is statutory. A Dutch municipality that buys a zaaksysteem is
expected to answer the Objecten and Objecttypen APIs, because that is
where the other suppliers in its landscape read and write the objects a
case refers to: a parking permit, a tree, a container, a report on a
street.

## Why integriq and not openregister

The register first put the row on openregister. The openregister lane
handed it here and wrote the argument down in its own umbrella: "ADR-091
§6 says 'ZGW, StUF, DSO, Notificaties ... belong in OpenConnector, even
where the endpoint happens to be unauthenticated'", and "openregister's
`specs/zgw-api-mapping` is a redirect stub whose only requirement is
'Consult the canonical zgw-api-mapping spec'". Both facts hold: the stub
carries `status: redirect` and one requirement, and ADR-091 §6 is
unambiguous that a national standard is not a leaf app's to encode.

ADR-091 §1 and §2 put the rest here too. The Objecten API authenticates
with `Authorization: Token <key>`, which is a credential check and not a
Nextcloud session, so the endpoint belongs to integriq and the work behind
it is OpenRegister configuration. ADR-108 already names openconnector as
the standing holder of the fleet's protocol surface.

openregister's half exists and is not rebuilt here: the Endpoint system
the ZGW routes already ride, the objects API, the schema export and the
RBAC that decides who may read what.

## Scope

- **Objecten API v2.** `GET`, `POST`, `PATCH`, `PUT` and `DELETE` on
  `/api/v2/objects`, with `type`, `data_attrs`, `date`,
  `registrationDate`, `ordering` and page-based pagination, plus the
  geometry search `POST /api/v2/objects/search`. Each objecttype maps to
  one OpenRegister register and schema by configuration.
- **Objecttypen API v2.** `GET /api/v2/objecttypes`,
  `/api/v2/objecttypes/{uuid}` and `/{uuid}/versions/{version}`, rendering
  `jsonSchema` from the OpenRegister schema and the version from the
  schema's version chain.
- **Authorisation.** A token with a permission per objecttype, read or
  read and write, evaluated before OpenRegister's own RBAC rather than
  instead of it. No Nextcloud session anywhere on the path.
- **Declaration.** A leaf app declares the objecttypes it publishes in its
  endpoint declaration (ADR-091 §4) and ships no controller.
- **Announcement.** A write announces on the `objecten` kanaal through
  `notificaties-api-connector`, in the standard notification body.
- **Throttling.** Every route is throttled under ADR-082.

## How dossiq consumes it

The register's `dossiq_half`: "expose caseObject types through it instead
of a dossiq controller". dossiq declares its `caseObject` types as
objecttypes in its endpoint declaration; the register and schema behind
each stay dossiq's, the wire shape and the token become integriq's. No
dossiq change is opened here: dossiq's umbrella
`competitor-parity-2026-09` already carries the row as a half it owes
against the owner's slug, and it is one declaration plus the deletion of
whatever controller answers today.

## ADRs

- ADR-091 §1, §2, §4 and §6: a credential-checking endpoint and a national
  standard are integriq's; the app declares, integriq owns.
- ADR-108: openconnector is the standing holder of the fleet's protocol
  surface.
- ADR-022: OpenRegister's objects, schemas and RBAC are consumed, not
  reimplemented.
- ADR-002: the response envelope and the error shape follow the standard
  the caller expects, which is VNG's, not ours.
- ADR-082: every public endpoint is throttled.
- ADR-064: the API key is custody material, resolved by reference.

## Existing specs it extends

`endpoint-runtime` (the dispatch this rides on),
`openconnector-register-schema` (the register and schema mapping),
`notificaties-api-connector` (the announcement) and
`zgw-version-translation` (the per-resource translator pattern it copies).

## Out of scope

- The Zaken, Documenten, Catalogi and Besluiten APIs. They have their own
  rows and their own owners; this change is Objecten and Objecttypen.
- Authoring objecttypes in a UI of ours. An objecttype is an OpenRegister
  schema and is authored where schemas are authored.
- Being an Objecten API client. Reading somebody else's Objecten API is a
  source and a synchronisation, which integriq already has.
