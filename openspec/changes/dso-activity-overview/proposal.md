# Proposal: dso-activity-overview

## Summary

A case app shows the DSO activities it receives on its own admin page (dossiq VTH, board DqVthInstellingen): per activity the case type it becomes, the variant (route) of that case type, and when an application last arrived, and below them the activities no row maps yet ("Nog niet gekoppeld: 1 aanvraag wacht"). Integriq stores the rows but keeps neither the variant nor when a row last matched, and the unmapped list is a separate read.

- `dso_activity_mapping` 1.1.0: a case type entry may name its `variant`, the route of that case type a case from this activity takes. The mapper hands it on with the case type, so the case system can start the case on that route.
- `GET /api/admin/dso-activities` (admin only): every row with `lastReceivedAt`, the newest `receivedAt` of a verzoek that matched it (latest 500 verzoeken), then one row per unmapped activity (`unmapped: true`, `waiting`, `lastReceivedAt`). Read only; the intake writes nothing new.

## Scope

- `lib/Settings/register.d/dso-activity-mapping.json`, `lib/Service/Dso/DsoActivityMapper.php`, new `lib/Service/Dso/DsoActivityOverview.php`, `lib/Controller/DsoActivityMappingController.php`, `appinfo/routes.php`, tests.
