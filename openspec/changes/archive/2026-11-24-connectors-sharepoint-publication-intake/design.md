# Design: connectors-sharepoint-publication-intake

Kind: code. Size M. Read at `development` 92f282bc.

## Where it fits

| Piece | File | Today |
|---|---|---|
| Adapter | `lib/Service/Adapter/DocumentCms/SharePointOnlineAdapter.php:153` `listDocuments()`, :195 `fetchDocument()`, :252 `list()` | only OpenRegister's integration surface calls it |
| Legacy template | `configurations/sharepoint-woo/{sources,synchronizations,rules,mappings}/` | numeric ids, empty secret fields, old publication fields |
| Packaged sets | `lib/Settings/configurations/zgw-*.json`, `lib/Service/Zgw/ZgwSetCatalogue.php:47` | the modern shape this set copies |
| Store card | `lib/Service/CatalogRegistryService.php:113` `PROVIDER_CATEGORY_OVERRIDES['sharepoint-online']` | Enable only |
| Engine | `synchronization-engine` REQ-004 (files), REQ-015 (approval gate) | reused |
| Target | OpenCatalogi `publication` 0.0.4 | `title` required, `publicationDate` makes it live |

## D1. A packaged set over Graph and the broker

The new set, `lib/Settings/configurations/sharepoint-publications.json`,
follows the `zgw-*.json` shape: a slug, a source template, the synchronization
and mapping slugs, `storageStrategy`, `writesBack: false` and a target chosen
by the operator.

- Source: type `api`, location `https://graph.microsoft.com/v1.0`,
  `configuration.authentication.credentialRef` from the broker, and three
  plain settings: `siteId`, `driveId` and `folderPath`.
- Synchronization: lists the children of the folder, one publication per
  subfolder (a Woo dossier), expanding `listItem` fields so the library's own
  columns reach the mapping. Each subfolder's files are the documents.
- Mapping: `title` from the dossier's title column, else the folder name;
  `summary` and `description` from their columns when present; the
  OpenCatalogi catalogue from the setup; a `source` reference holding the
  SharePoint web URL; and no `publicationDate`.

Rejected: reusing `configurations/sharepoint-woo/`. It reads SharePoint's
legacy REST API with a token built from embedded client secret fields, it
references objects by numeric id so it only imports into an empty instance,
and it writes `published` and `status: Concept`, which OpenCatalogi's
`publication` no longer has. Fixing it would replace every file in it.

Rejected: a new adapter method that writes publications. That makes the
adapter an orchestrator; the sync engine already fetches, maps, dedupes and
attaches files (REQ-004), and the adapter stays the thing that knows Graph.

## D2. Files go on the publication, in any run

`fetchDocument()` writes into the current user's Files and gives up without a
session (:200). A synchronization runs from cron, so every file would be
lost. The set instead lists each document's `@microsoft.graph.downloadUrl`
as a file reference on the mapped object, and REQ-004 fetches it through
`CallService` and attaches it to the publication object.

The broker needs an acting user for a sessionless call
(`lib/Service/BrokeredCallService.php`, its header names "sessionless calls
against a broker without acting-user support" as a configuration error). The
setup records the administrator who configured it as the acting user, per
ADR-099, and the dialog says so.

## D3. The setup dialog on the Store card

The SharePoint card in the Store gets a "Route to publications" action that
opens `src/modals/SharePointPublicationSetupModal.vue`. It walks four
choices, each loaded from Graph through a new session route,
`GET /api/sharepoint/lookup`, on `SharePointSetupController`, which calls the
adapter's brokered request:

1. Credential: the broker picker the source form already uses.
2. Site: `GET /v1.0/sites?search={text}`.
3. Library and folder: `GET /v1.0/sites/{siteId}/drives` and the folder's
   children.
4. Target: an OpenCatalogi catalogue, read from OpenRegister.

On save it instantiates the set with those values through
`ConfigurationService::importConfiguration()`
(`lib/Service/ConfigurationService.php:1037`), the import behind
`POST /api/configurations/import` (`appinfo/routes.php:606`), and offers
"Run now", which is
`POST /api/synchronizations/{id}/run` (`appinfo/routes.php:405`).

An optional "Review before import" switch sets
`sourceConfig.requiresApproval`, so a first run pauses at REQ-015.

## D4. Draft, never live

The mapping never writes `publicationDate`. OpenCatalogi reads a past date as
live, so a mapping that copied a SharePoint "published" column would publish
a Woo document on the first run, before any editor saw it. The design keeps
the one irreversible step in OpenCatalogi.

## D5. The legacy template is marked deprecated

The matrix records the configuration import UI as the way
`configurations/sharepoint-woo/` is reached today. Its entries get a
deprecation note naming `sharepoint-publications`, and the
import warns when it is chosen. Removing the folder is a follow-up once no
instance still imports it.

## Declarative versus imperative

The set is configuration: a source, a synchronization, a mapping, packaged
declaratively. The dialog is a thin screen over Graph lookups. Nothing adds
lifecycle behaviour to a schema.

## Seed data

No schema changes in integriq. The set ships as a template, not as seeded
objects. The mock register gains one demo source and synchronization in mock
mode with a canned Graph response of two dossiers and three documents, so
the Synchronizations page shows a working example.

## Risks

- Graph permissions: listing sites needs `Sites.Read.All` or a site-scoped
  grant. The dialog names the permission when Graph refuses.
- Large libraries: the synchronization pages through Graph's `@odata.nextLink`
  under REQ-002 and REQ-009, so an incomplete fetch never deletes a draft.
- A dossier renamed in SharePoint arrives as a changed title on the same
  publication, because the origin id is the SharePoint item id, not the name.
