# Design: connectors-catalogue-expansion

Kind: code. Size M. Read at `development` 92f282bc.

## Where it fits

| Piece | File | Today |
|---|---|---|
| Store page | `src/manifest.json` page `Store`, route `/store`, schema `catalog_item`, `cardComponent: CatalogItemCard` | card grid with kind filters |
| Registry | `lib/Service/CatalogRegistryService.php:147` `collect()`; :172, :217, :321 the three lists; :447 `findSeedSourcePayload()`; :68 `TYPE_CATEGORY_LABELS`; :88 `SLUG_CATEGORY_OVERRIDES` | reads `register.d` only |
| Materialise | `lib/Repair/MaterializeCatalogItems.php` | one `catalog_item` per entry |
| Seeds | `lib/Settings/register.d/*.json`, 27 `source` objects | every one becomes a source object on import and a card |

## D1. A template library the import does not install

A `register.d` fragment is merged into the register at load (its README:
"per-OpenSpec-change register fragments are merged here at load"), so every
seeded source becomes a real `source` object on every install. That is right
for a dozen and wrong for several hundred: an administrator would open the
Sources page to find hundreds of disabled sources they never chose.

So templates live in a new directory, `lib/Settings/connector-templates/`,
one JSON file per template, grouped by folder (`backoffice/`, `saas/`,
`government/`). The file holds the same `source` payload a seed fragment
holds, plus an `x-template` block:

- `vendor` and `system`: who makes it and what it is called.
- `standard`: the interface integriq reaches it over, such as `StUF-BG 3.10`,
  `Haal Centraal BRP`, `CMIS 1.1` or `OpenAPI 3`.
- `verifiedAgainst`: the URL of the vendor's or the standard's published
  interface description the template was checked against.
- `tier`: `curated` or `generated`.

`CatalogRegistryService` gains a fourth list, `collectFromTemplates()`, and
`findSeedSourcePayload()` also reads the library, so the existing Instantiate
act in `CatalogItemDetailDialog` creates the source only when chosen.

Rejected: more `register.d` fragments. That grows every install's source
list with the catalogue.

## D2. Municipal back-office templates are checked, not guessed

The tender's list mixes systems with a published standard interface and
systems whose interface is a vendor contract. A template that guesses an
endpoint is worse than none, because a buyer reads a card as a promise.

So each system on the Stein list gets one of two outcomes, recorded in
`lib/Settings/connector-templates/backoffice/README.md`:

- A curated template, when a published interface exists and integriq speaks
  it. The template configures the existing capability: a StUF-BG source for
  the `stuf-adapter`, a Haal Centraal source, a CMIS source for the
  `document-cms-connectors` adapters, an iWMO source for the
  `iwmo-ijw-adapter`. `verifiedAgainst` must cite the document.
- A recorded reason, when no published interface is available to check
  against. The Store shows nothing for it, and the README names what a buyer
  needs from the vendor.

SmartDocuments is already an adapter and is not repeated.

## D3. A generated SaaS set from a pinned directory

Hundreds of hand-checked templates is years of work, and the competitors'
numbers come from connector marketplaces. The directory at
`https://api.apis.guru/v2/list.json` publishes OpenAPI descriptions for
thousands of APIs. A script, `scripts/generate-connector-templates.php`, reads a
pinned, committed snapshot of it and an allow-list,
`lib/Settings/connector-templates/saas/allow-list.json`, and writes one
generated template per allowed entry: name, base URL from `servers`, the
auth scheme from `components.securitySchemes`, the documentation URL and the
category from the directory's own tags.

The generated file names the auth scheme and never a credential. Any secret
goes through the broker (ADR-064) after Instantiate.

The allow-list starts with the services the buildiq row names, Google Sheets,
Salesforce and Slack, and grows by pull request. Nothing reaches the Store
that a person did not add to the allow-list.

**At build (29 Sep 2026).** The snapshot of 2026-09-29 holds 2,529 APIs.
Google Sheets (`googleapis.com:sheets`) and Slack (`slack.com`) are in it.
Its only Salesforce entry is `salesforce.local:einstein`, Einstein Vision and
Language, not the CRM API, so Salesforce ships as a curated template
(`saas/salesforce-rest.json`) checked against Salesforce's REST API developer
guide, not as a generated one. The committed snapshot is the trimmed index
(`snapshot/index.json`, title, categories, description URL and date per
entry) plus the trimmed description of each allow-listed entry
(`snapshot/specs/`): servers and security schemes, no scope lists.
`php scripts/generate-connector-templates.php refresh` re-pins it.

Rejected: fetching the directory at runtime. The Store would change under an
administrator, and an instance without internet would show nothing.

## D4. An honest count

`collectFromSeedFragments()` (:321) skips any source whose `@self.slug`
starts with `environment-`, because `environments-and-promotion.json`
seeds those as promotion targets, not connectors. When an adapter entry and a
template share a system, as `adapter:smartdocuments` (:265) and
`source-template:smartdocuments` do, the Store shows the adapter and the
template becomes its configure action. Every card carries its tier and the
Store has a quick filter per template tier (Checked templates, Generated
templates), so the count per tier is the filtered count and "hundreds" is
never claimed for generated starting points. The index page offers no count
per quick filter, so a header count would need a custom page, which the
page-type ratchet refuses. Materialising removes the cards the registry no
longer lists, so an upgraded install drops the placeholders a fresh one never
shows.

## Declarative versus imperative

The library and the Store are declarative: JSON templates read into
`catalog_item` objects. The generator is a build-time script, not runtime
behaviour.

## Seed data

`catalog_item` (`lib/Settings/register.d/catalog-item-schema.json`,
version 1.0.0) gains `tier` (`adapter`, `curated`, `generated`) and
`verifiedAgainst`, both strings, and moves to 1.1.0. Every curated
template in the library is itself the seed; the mock register gets no new
source objects, on purpose.

## Risks

- A generated template can point at an API that changed after the snapshot.
  The card says generated and the snapshot date, and the source test action
  shows the first call's answer.
- A vendor can object to its name on a card. A curated back-office template
  names the standard first and the vendor second.
- The allow-list decides what "common business software" means. It is a
  reviewed file, so the decision is visible.
