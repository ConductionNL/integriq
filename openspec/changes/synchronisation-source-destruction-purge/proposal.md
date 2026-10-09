---
kind: code
depends_on: []
---

# Proposal: synchronisation-source-destruction-purge

## Summary

When a source system destroys a document under its retention schedule, the
publication made from it must go too: withdrawn, and its file and metadata
removed, not parked in a trash where it can be restored. Today integriq
notices only on the next full run that the record is gone, and then soft
deletes it, which OpenRegister does on purpose so the object and its files
can come back. This change adds a purge that removes the object and its
files for good, a destruction notice that triggers it at once instead of
waiting for the next full run, and a record of every purge that outlives the
purged object.

## Why

Row `opencatalogi:lc-source-destroyed`, "Withdraw a publication and remove its
file and metadata automatically when the document is destroyed in the source
system", rated partial for OpenCatalogi, built, with integriq as owner. It
comes from OpenCatalogi's matrix. Demand: tender
https://www.tenderned.nl/aankondigingen/overzicht/407973, wish VPB-10. One
competitor rates it yes:

- ckan: "on every harvest run the RDF harvester compares the GUIDs in the
  source with those in the database and flags the missing ones for deletion
  (ckanext/dcat/harvesters/rdf.py:84-125), then calls package_delete for them
  (:270-276) ... package_delete is a soft delete (the dataset sits in
  /ckan-admin/trash until purged)."

dkan rates it partial: orphaned datasets are unpublished and "the metadata
and any localized file stay in the site".

The decision names the missing half: "files and metadata are purged, not soft
deleted, when the source destroys the document, and a destruction notice
triggers it without waiting for the next full run."

## What integriq already has

- Garbage collection on a full run: `SynchronizationService::deleteInvalidObjects()`
  (`lib/Service/SynchronizationService.php:3832`), called at :2797 only when
  the run is not incremental (:2795), behind the fetch completeness and
  deletion ratio guards.
- A declared disappearance policy: `DisappearancePolicy`
  (`lib/Service/Ownership/DisappearancePolicy.php`) with `delete` as default,
  `markEnded` and `keepAndFlag`, applied at `SynchronizationService.php:4099`.
- The delete itself: `updateTargetOpenRegister()` case `delete`
  (`SynchronizationService.php:4953-4959`) calls
  `$objectService->deleteObject(uuid: ...)` with no `permanent` argument, so it
  is a soft delete.
- Inbound ZGW notifications: `NotificatiesSubscriberController::callback()`
  (`lib/Controller/NotificatiesSubscriberController.php:259`) hands them to
  `NotificatiesSubscriberService::handleInboundNotification()`
  (`lib/Service/NotificatiesSubscriberService.php:495`), which only re-emits
  them as a CloudEvent. A `destroy` notification changes no synchronized
  object.

What OpenRegister offers, on its `development` branch:
`ObjectService::deleteObject()` takes `bool $permanent = false`
(`lib/Service/ObjectService.php:2858-2867`). `DeleteObject::delete()`
destroys the bound Nextcloud folder and files only on a permanent delete
(`lib/Service/Object/DeleteObject.php:262-302`), and its soft delete
"intentionally LEAVES the bound Nextcloud folder/files in place" (:363-364).

## What this change builds

1. A fourth disappearance policy, `purge`: the target object is deleted with
   `permanent: true`, which removes its files.
2. A destruction notice path: a ZGW `destroy` notification, or a signed
   destruction call on a synchronization, purges the one object whose
   contract names that source record, at once.
3. A purge record on the synchronization log that names what was purged,
   when and on which notice, because the object itself no longer exists to
   carry it.
4. A refusal that stays visible: an object OpenRegister will not delete
   permanently because another object depends on it is reported, never
   quietly soft deleted instead.

## Out of scope

- OpenCatalogi's public surfaces. A purged object is gone from OpenRegister,
  so it is gone from every surface reading it.
- Destruction of objects nobody synchronized. A local record is not the
  source's to destroy.
- Changing the default. `delete` stays the default policy; a publication
  synchronization opts into `purge`.

## Sibling half

OpenCatalogi builds nothing for the purge. Its matrix row is owned by
integriq. OpenCatalogi may later show the purge records from the
synchronization log on its own publication history; that is not required
for the row.
