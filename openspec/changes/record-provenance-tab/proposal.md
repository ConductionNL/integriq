# The record says where it was synchronized from

## Why

Board `integriq/IqGesynchroniseerdVan` draws a "Synced from" tab on a dossiq case: the source system, the record there, when it was last synchronized, who owns the fields, and the last log entries for this record, with a link to the full log in Integriq. "Every app on OpenRegister shows where a record comes from and which connections touched it." integriq knows this (a `synchronization_contract` per record, with its log), but nothing answers it for one record, so no app can show it.

## What changes

- `GET /api/provenance?register=&schema=&id=` answers for one OpenRegister record: whether a synchronization wrote it, the source and its name, the record id in the source, the last synchronization moment, the synchronization, an ownership sentence, the five newest contract log entries and how many there are. The caller's right to read the record is checked first, under OpenRegister's own rights; a record the caller cannot read answers 404.
- Apps show it as configuration: a tab whose widgets read this endpoint with `requiresApp: integriq` (dossiq `CaseDetail` first).

## Impact

`lib/Service/RecordProvenanceService.php`, `lib/Controller/RecordProvenanceController.php`, `appinfo/routes.php`, tests. The dossiq tab is a manifest change in dossiq.
