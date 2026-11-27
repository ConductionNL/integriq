# Tasks: connectors-hr-data-exchange

Kind: config. Size M. Halves for humaniq `payroll-external-bureau-handoff` and `talent-training-and-lms` (rows humaniq `pay-outsourced`, `td-lms-sync`, `tal-training`).

## Implementation tasks

### Task 1: Payroll templates and mutation mappings
- **spec_ref**: `openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-ready-payroll-handoff-reaches-the-bureau-and-its-payslips-come-back-req-hrx-001`
- **files**: `lib/Settings/register.d/loket-payroll-source.json`, `nmbrs-payroll-source.json`, `payroll-sftp-drop-source.json`, mappings in `lib/Settings/integriq_seed_data.json`
- **acceptance_criteria**:
  - GIVEN a handoff with three mutations WHEN it is mapped for each bureau THEN the output matches the recorded example of that bureau
- [x] Implement
  - Three ADR-037 fragments instead of entries in `integriq_seed_data.json`, like the Studytube set: `loket-payroll-source.json` (dormant `loket-payroll`, OAuth client credentials with the secret `loket-payroll-client-secret` by broker reference, and the mapping `payroll-handoff-to-loket-mutations`: one mutation per handoff mutation, the kind in Loket.nl's words, only the new values, the mutation id as external reference), `nmbrs-payroll-source.json` (dormant `nmbrs-payroll`, secret `nmbrs-payroll-client-secret`, mapping `payroll-handoff-to-nmbrs-changes`: one change per changed field with old and new value) and `payroll-sftp-drop-source.json` (dormant SFTP source `payroll-sftp-drop`, mapping `payroll-handoff-to-csv-rows`: file name `<loonheffingennummer>-<period>.csv`, one row per changed field). The mappings read the handoff with its `mutations` and `administration`; supplying those is Task 2's push. The bureaus' API paths and field names are marked in each source's description to check against the bureau's documentation before enabling.
- [x] Test (PHPUnit per mapping against its recorded example)
  - `tests/Unit/Settings/PayrollBureauSetsTest.php` maps `tests/fixtures/hr-data-exchange/payroll/handoff-2026-09.json` (Adviesbureau Kade B.V., September 2026, three mutations) with the real MappingService and compares it with `loket-mutations.json`, `nmbrs-changes.json` and `sftp-drop-file.json`. These examples are written to each bureau's format, not captured from a live bureau (no bureau account in the sandbox); also covered: each mutation delivered once, the period's first day for an undated mutation, a starter's empty old value, an empty handoff, dormant sources with brokered secrets.

### Task 2: Handoff push and payslip pull
- **spec_ref**: `openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-ready-payroll-handoff-reaches-the-bureau-and-its-payslips-come-back-req-hrx-001`
- **files**: synchronizations `payroll-handoff-push`, `payroll-payslip-pull` in the seed data
- **acceptance_criteria**:
  - GIVEN a handoff set to `klaargezet` WHEN the push succeeds THEN it reads `verzonden` with the delivery reference
  - GIVEN the bureau's return file with two payslips WHEN the pull runs THEN two `Payslip` objects carry the handoff id and the handoff reads `ontvangen`
- [x] Implement
  - `lib/Settings/register.d/payroll-handoff-exchange.json`: three unbound pushes, `payroll-handoff-push-loket`, `-nmbrs` and `-sftp`, each on condition `status = klaargezet` over the Task 1 mapping of its bureau, writing `verzonden`, `deliveryReference` and `deliveredAt` back on success and nothing on failure (D1); and the pickup `payroll-payslip-pull-sftp` with the mapping `payroll-return-row-to-payslip` into `humaniq/Payslip`, keyed on the bureau's payslip id, the handoff found through `lookup()` by loonheffingennummer and period (D2).
  - humaniq keeps the mutations as separate `PayrollHandoffMutation` objects pointing at the handoff, so the engine gained four generic pieces (spec delta `specs/synchronization-engine/spec.md`): `sourceConfig.embedRelated` (`RelatedObjectEmbedder`, REQ-HRX-003), `targetConfig.relatedWriteBack` applied once after a pull run through a normal save so humaniq's intake listener runs (`RelatedWriteBack`, REQ-HRX-004), the `sftp`/`ftps` target type writing the mapped rows as one CSV file through `RemoteFileClient` (`RemoteFileTarget`, REQ-HRX-005), and `{{ now }}` in every write-back (REQ-HRX-006).
  - Not seeded: payslip pulls from the Loket.nl and Nmbrs APIs, because neither publishes the payslip read to record an example from. The acceptance criteria name the return file, which is the SFTP pickup.
- [ ] Test (PHPUnit; one run against the SFTP container with humaniq installed on a dev instance)
  - PHPUnit done: `RelatedObjectEmbedderTest`, `RelatedWriteBackTest`, `RemoteFileTargetTest`, `SynchronizationPayrollExchangeTest` (the real engine: sftp/ftps delivery and write-back, refusal, delete, `{{ now }}`, embedding, the run-level write-back and a test run moving nothing) and `PayrollHandoffExchangeSetTest` (the stored handoff embedded and mapped to each bureau's example, the SFTP file, `return-2026-09.csv` with two payslips through the real pickup reader and lookup, the handoff moved to `ontvangen`). Open: the run against the SFTP container with humaniq installed, which needs a live instance.

### Task 3: Studytube people push and completions pull
- **spec_ref**: `openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-learning-platform-learns-who-works-where-and-finished-trainings-come-back-req-hrx-002`
- **files**: `lib/Settings/register.d/studytube-lms-source.json`, synchronizations `lms-people-push`, `lms-completions-pull`
- **acceptance_criteria**:
  - GIVEN a recorded completion WHEN the pull runs twice THEN one `TrainingRecord` with source `lms` exists
- [x] Implement
  - `lib/Settings/register.d/studytube-lms-source.json`: the dormant sources `studytube-lms` (OAuth client credentials, secret `studytube-lms-client-secret`) and `humaniq-learning-people` (humaniq's `GET /api/learning/people` through an integration account, app password `humaniq-learning-people-app-password`), the mappings `humaniq-person-to-studytube-user` (`uid` = the humaniq employee id) and `studytube-completion-to-training-record` (`gevolgd`, `lms`, `sourceRef` = the result id; no certificate leaves `validUntil` out), and the disabled synchronizations `lms-people-push` (incremental on `modified`, api to api `/users`) and `lms-completions-pull` (incremental on `updated_at`, keyed on the result id, into `humaniq/TrainingRecord`).
- [x] Test (PHPUnit against recorded Studytube answers)
  - `tests/Unit/Settings/StudytubeLmsSetTest.php` over `tests/fixtures/hr-data-exchange/studytube/`, each TrainingRecord validated against humaniq's contract (`training-record.schema.json`, copied from humaniq development). The answers follow the Studytube Public API v2 shape and are example answers, not captured from a live tenant: one run against a Studytube test tenant is owed (live pass).

### Task 4: Catalogue, docs and strings
- **spec_ref**: `openspec/changes/connectors-hr-data-exchange/specs/hr-data-exchange/spec.md#requirement-a-learning-platform-learns-who-works-where-and-finished-trainings-come-back-req-hrx-002`
- **files**: `lib/Service/CatalogRegistryService.php`, `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an upgrade WHEN the catalogue is materialised THEN the payroll templates show under `Payroll` and Studytube under `Education data`
- [x] Implement
  - Studytube half done: `studytube-lms` and `humaniq-learning-people` under `Education data` (`CatalogRegistryService::SLUG_CATEGORY_OVERRIDES`, `CatalogRegistryServiceTest::testTheLearningPlatformTemplatesAreEducationData`), `docs/features/hr-data-exchange.md`.
  - Payroll half done: `loket-payroll`, `nmbrs-payroll` and `payroll-sftp-drop` under `Payroll` (same override list), the payroll section of `docs/features/hr-data-exchange.md`. No new strings: the catalogue category names are not translated for the Studytube half either.
- [x] Test (PHPUnit on the catalogue)
  - `CatalogRegistryServiceTest::testThePayrollBureauTemplatesArePayroll`; the catalogue golden digests in `CatalogRegistrySeedGoldenTest` moved by the three new templates.

## Verification
- [x] `openspec validate connectors-hr-data-exchange --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
