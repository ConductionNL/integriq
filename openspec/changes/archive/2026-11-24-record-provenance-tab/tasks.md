# Tasks: record-provenance-tab

- [x] 1. `RecordProvenanceService::provenance()` and `GET /api/provenance` with the read check first. Verify: `tests/Unit/Service/RecordProvenanceServiceTest.php`, `tests/Unit/Controller/RecordProvenanceControllerTest.php` (fail on development: the classes do not exist).
- [x] 2. dossiq `CaseDetail` tab "Synced from" over this endpoint, `requiresApp: integriq` (dossiq repo). Built: dossiq #6908 (manifest tab with `appInstalled: integriq` and `synced: true`, `tests/vitest/caseSyncedFromTab.spec.js`), archived in dossiq #6909.
