---
kind: code
depends_on: []
---

# Proposal: connectors-sharepoint-publication-intake

## Summary

Woo dossiers are often put together in SharePoint, and the competitors
publish them from there. Integriq has a Graph adapter that can list and
fetch SharePoint documents, but nothing in integriq starts it, a cron run
drops every file, and the only SharePoint to publication template is a
legacy OpenConnector export with empty secret fields. This change adds a
packaged SharePoint to publication set over Microsoft Graph and the
credential broker, a setup dialog on the Store card that picks the site,
library and folder, and a mapping that lands each folder as a draft
publication in OpenCatalogi with its documents attached.

## Why

This change covers two rows. One comes from a sibling matrix.

- `opencatalogi:int-sharepoint`, "Take documents for publication from
  SharePoint", rated no and none for OpenCatalogi with integriq as owner.
  Two competitors rate it yes:
  - xxllnc: https://xxllnc.nl/applicaties/publiceren/: "Met Publiceren of via
    een compatibel zaaksysteem / DMS (zoals xxllnc Zaken / SharePoint)
    verzamelen we moeiteloos de benodigde documenten voor publicatie".
  - iprox: https://iprox.nl/marketing/477/blog-woo-publiceren-koppeling:
    "Iprox heeft een kant-en-klare koppeling ontwikkeld tussen SharePoint en
    iprox.open. Woo-dossiers die in SharePoint zijn samengesteld, kunnen
    hiermee eenvoudig worden gepubliceerd".
- `integriq:con-sharepoint`, "Take documents from SharePoint", rated partial
  and built. Two competitors rate it yes:
  - n8n: "packages/nodes-base/nodes/Microsoft/SharePoint/v2/actions/file/index.ts:23
    'download' takes files from a SharePoint site".
  - mulesoft: "https://docs.mulesoft.com/sharepoint-connector/latest/index.md
    Microsoft SharePoint Connector 3.10 works with SharePoint files, folders
    and list items".
  The matrix note: the adapter "only runs when OpenRegister's integration
  surface calls list() with a siteId and a brokered credential; the hand-off
  to the document app is explicitly deferred".

No demand row. The decision records `opencatalogi:int-sharepoint` as the
same SharePoint adapter as `integriq:con-sharepoint`.

## What integriq already has

- `lib/Service/Adapter/DocumentCms/SharePointOnlineAdapter.php`:
  `listDocuments()` (:153) and `fetchDocument()` (:195) over Graph
  `sites/{siteId}/drive`, through the broker. `list()` (:252) returns nothing
  without a `siteId` filter. `fetchDocument()` writes into the signed-in
  user's Files folder `OpenConnector SharePoint Documents` (:65) and returns
  `null` when there is no session (:200), so a cron run keeps no file.
- `configurations/sharepoint-woo/`: a legacy template with a source whose
  `configuration.authentication` carries empty `client_secret` and
  `private_key` fields, a synchronization with `sourceId: "1"` and
  `targetId: "1/1"`, a `fetch_file` rule whose `@id` points at
  `apps/openconnector`, and a mapping onto `published` and `status:
  Concept`, fields OpenCatalogi's current `publication` schema does not have.
- File handling in the sync engine: `synchronization-engine` REQ-004 fetches
  referenced files through `CallService` and persists them on the object.
- A batch approval gate before writes: `synchronization-engine` REQ-015.

OpenCatalogi's `publication` schema (0.0.4, `lib/Settings/publication_register.json`
on ConductionNL/opencatalogi `development`) requires only `title`, and
describes `publicationDate` as "when set to a date in the past, this
publication is live and publicly accessible".

## What this change builds

1. A packaged set, `lib/Settings/configurations/sharepoint-publications.json`,
   slug-referenced like the ZGW sets: a Graph source with a broker
   reference, a synchronization over one library folder, and a mapping onto
   OpenCatalogi's `publication`.
2. A setup dialog from the SharePoint card in the Store: pick the credential,
   search and pick the site, the library and the folder, and the target
   catalogue. It creates the source, the synchronization and the mapping.
3. Documents attached to the publication object through the engine's file
   handling, in a cron run, not into a person's Files folder.
4. Draft only: the mapping never sets `publicationDate`, so nothing goes live
   until an editor publishes it in OpenCatalogi.
5. The legacy `configurations/sharepoint-woo/` template marked deprecated in
   the configuration import, pointing at the new set.

## Out of scope

- Publishing. The editor sets the publication date in OpenCatalogi.
- Writing back to SharePoint.
- Changing `SharePointOnlineAdapter` for its existing callers. OpenRegister's
  integration surface keeps calling `list()` as it does.

## Sibling half

OpenCatalogi builds nothing for the intake to work: a draft publication with
attachments is an ordinary `publication`. What OpenCatalogi may add, and this
change does not, is a filter on its publications list for "came from
SharePoint", reading the `source` reference the mapping writes.
