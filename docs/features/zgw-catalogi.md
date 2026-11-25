# ZGW Catalogi API (served)

Integriq serves the read half of the VNG Catalogi API 1.3 for document types:
`catalogussen` and `informatieobjecttypen`. A Documenten API, ours or another
supplier's, points each document's `informatieobjecttype` at a URL integriq
serves, so the organisation keeps one list of document types instead of a
second catalogue (Woo row 17.18).

## Routes

All under `/index.php/apps/integriq/catalogi/api/v1`:

| Method | Path | Answer |
| --- | --- | --- |
| GET | `/catalogussen` | The one bound catalogue, paged (`count`, `next`, `previous`, `results`); filters `domein`, `rsin` |
| GET | `/catalogussen/{uuid}` | The catalogue, listing its definitive informatieobjecttypen by URL |
| GET | `/informatieobjecttypen` | The document types, 100 per page; filters `catalogus` and `status` (`definitief` default, `concept`, `alles`) |
| GET | `/informatieobjecttypen/{uuid}` | One document type |
| POST, PUT, PATCH, DELETE | any of the above | 405, the catalogue is read only |

Every `url` and `catalogus` in an answer is the absolute URL served here.

## Configuration: the binding

An administrator creates one `catalogi_binding` object in the integriq register:

- `publishedUuid`, `domein`, `rsin`, `naam`: the catalogue.
- `typesRegister`, `typesSchema`: where the organisation's document types live (not `register` and `schema`: OpenRegister reserves those names in an object body and drops them).
- `fieldMap` (optional): which stored field fills which resource field. The
  default reads `name` → `omschrijving`, `confidentiality` →
  `vertrouwelijkheidaanduiding`, `category` → `informatieobjectcategorie`,
  `categoryUri` (the TOOI URI), `active` → `concept` (inverted), `identifier`,
  `validFrom` → `beginGeldigheid`. A leading `!` inverts a yes/no field.
- `clients`: the ZGW clients that may read, each with `clientId` (the JWT's
  `client_id`), `scopes` (`catalogi.lezen` reads) and `concepts` (whether it
  may read concept types).

## Access

Each request needs a ZGW JWT, verified like every `jwt-zgw` endpoint. In this order:

1. No binding, or a binding whose schema does not resolve: 404 `catalogue-not-configured`.
2. No or an invalid JWT: 401.
3. A client that is not listed or lacks `catalogi.lezen`: 403. No document type is read.

A concept type (inactive in the source schema) is left out of lists and answers
404 by uuid, unless the client may read concepts and asks `status=concept` or
`status=alles`.

## Woo starter set

`POST /index.php/apps/integriq/api/catalogi/woo-starter-set` (administrators
only) creates, in the bound schema, one document type per Woo information
category that no existing type carries yet (by TOOI URI or label). The
categories come from the TOOI `scw_woo_informatiecategorieen` scheme in
OpenRegister's concept register; no copy is kept in integriq. The answer gives
`created` and `skipped`. Existing types are never changed or deleted. When the
scheme cannot be read the run refuses with `tooi-scheme-missing` and creates
nothing.

On the integriq admin page, the **Woo starter set** section runs the same
action with one button and shows how many types it created and how many
categories already had one.

## Limits

- Every route is rate limited per client address: 600 requests a minute
  (`#[AnonRateLimit]` on `CatalogiApiController`, ADR-082). A write answers 405
  under the same limit, so probing for a writable route costs the same.

## In the connector catalogue

The catalogue lists this as one adapter card, *Catalogi API (document types)*,
under Common Ground APIs. It is always available: the routes exist on every
install, and they answer once an administrator creates the `catalogi_binding`.
