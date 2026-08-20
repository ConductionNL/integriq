# Tasks — kiss-kcc-bridge

## 1. Data model

### Task 1: Declare the `kiss_klantcontact` schema
- **spec_ref**: `openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md#requirement-pull-sync-of-klantcontacten-with-a-persisted-cursor`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN a `kiss_klantcontact` schema exists with a `direction` enum `pulled|pushed`, `caseReference`/`caseObjectType`, and raw `betrokkenen`/`onderwerpobjecten` arrays
  - GIVEN the register's schemas list WHEN compared to `components.schemas` THEN every declared schema slug (including `kiss_klantcontact`) is listed
  - GIVEN `source.type`'s documented recognised values WHEN read THEN `kiss` is listed alongside `sms`/`peppol`/`psd2`/`payment`
- [x] Implement
- [x] Test

## 2. Provider abstraction

### Task 2: Add the Klantinteracties provider interface with log and REST bindings
- **spec_ref**: `openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md#requirement-klantinteracties-provider-abstraction-with-log-and-rest-bindings`
- **files**: `lib/Service/Kiss/KlantinteractiesProviderInterface.php`, `lib/Service/Kiss/LogKlantinteractiesProvider.php`, `lib/Service/Kiss/KlantinteractiesClient.php`, `lib/Exception/KissProviderException.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: log` (or absent) WHEN a pull runs THEN an empty page is returned with no HTTP call and no credential read
  - GIVEN `configuration.provider: rest` WHEN a pull runs THEN `GET {baseUrl}/klantcontacten` is called with `expand=betrokkenen,onderwerpobjecten`, `sorteer=registratiedatum`, and (when a cursor exists) `registratiedatum__gte`
  - GIVEN a `rest` create/link call WHEN dispatched THEN `Authorization: Token <decrypted-token>` is sent and the KISS-assigned `uuid` is extracted from the response
- [x] Implement
- [x] Test

## 3. Sync orchestration

### Task 3: Add KissSyncService (pull sweep with cursor + per-record isolation, push orchestration)
- **spec_ref**: `openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md#requirement-pull-sync-of-klantcontacten-with-a-persisted-cursor`
- **files**: `lib/Service/KissSyncService.php`
- **acceptance_criteria**:
  - GIVEN a page of new klantcontacten WHEN pulled THEN each is upserted as a `kiss_klantcontact` record (create for a new KISS `uuid`, update-in-place for an already-seen one)
  - GIVEN one malformed klantcontact in an otherwise-good page WHEN pulled THEN it is logged and skipped while the rest of the page still processes, and the cursor still advances to the page's max `registratiedatum`
  - GIVEN no active KISS source WHEN `pullAll()` runs THEN it returns 0 with no exception escaping the sweep
  - GIVEN a push request with a `caseReference` WHEN `pushKlantcontact()` runs THEN KISS's `createKlantcontact` then `linkOnderwerpobject` are both called, and the local mirror record is persisted `direction=pushed`
- [x] Implement
- [x] Test

### Task 4: Map onderwerpobjecten to a case reference; hash raw BSNs before storage
- **spec_ref**: `openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md#requirement-mapping-onderwerpobjecten-to-a-case-reference`
- **files**: `lib/Service/KissSyncService.php`
- **acceptance_criteria**:
  - GIVEN an onderwerpobject with `codeObjecttype` containing `zaak` WHEN mapped THEN `caseReference`/`caseObjectType` are populated from it
  - GIVEN no onderwerpobjecten, OR none matching the case marker (a foreign object type) WHEN mapped THEN `caseReference`/`caseObjectType` are both `null` and the raw array is preserved verbatim
  - GIVEN a betrokkene with `partijIdentificator.codeSoortObjectId: bsn` WHEN persisted THEN the stored `objectId` is a SHA-256 hash, never the raw BSN
- [x] Implement
- [x] Test

## 4. REST surface + scheduled job

### Task 5: Add KissController (push) + routes
- **spec_ref**: `openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md#requirement-push-endpoint-registering-a-klantcontact-and-linking-a-case`
- **files**: `lib/Controller/KissController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated session WHEN `POST /api/kiss/klantcontacten` is called with `{onderwerp, kanaal, ...}` THEN `{id, localUuid}` is returned
  - GIVEN a request missing `onderwerp` or `kanaal` WHEN posted THEN HTTP 400 `missing_fields` is returned before the sync service is called
  - GIVEN no active KISS source WHEN posted THEN HTTP 503 `not_configured` is returned — never a 500 crash
- [x] Implement
- [x] Test

### Task 6: Add KissPullJob (hourly TimedJob) + info.xml registration
- **spec_ref**: `openspec/changes/kiss-kcc-bridge/specs/kiss-kcc-bridge/spec.md#requirement-pull-sync-of-klantcontacten-with-a-persisted-cursor`
- **files**: `lib/Cron/KissPullJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN no KISS source is configured WHEN the job runs THEN it completes as a no-op (0 processed), no exception, no error log
  - GIVEN a sweep-level exception WHEN the job runs THEN it is caught and logged — never wedges the cron pipeline
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --strict` passes (this change only — see below)
- [x] Manual testing against acceptance criteria (sandbox `log` provider path) — exercised via the PHPUnit suite (72 new tests: KlantinteractiesClientTest, LogKlantinteractiesProviderTest, KissSyncServiceTest, KissPullJobTest, KissControllerTest; full suite 875/875 green after `composer install` closed a pre-existing unrelated vendor gap — `symfony/dom-crawler` was declared in composer.lock but absent from the rsynced vendor/ snapshot)
- [x] Code review against spec requirements — self-reviewed; see Deviations below
- [x] `composer lint`, `composer cs:check` (phpcs — 0 errors on all new files), `phpmd` (0 violations), `phpstan` (0 errors) all green on the new files

## Deviations

- **Credential storage does not use `credentialRef`/`BrokeredCallService`
  (mirrors notifynl-sms-channel's identical, already-accepted deviation).**
  KISS's token IS a static secret `credentialRef` could express (unlike
  NotifyNL's computed JWT) — the choice here is narrower: avoiding the
  optional-class dependency on OpenRegister's credential broker keeps
  `KlantinteractiesClient` self-contained and unit-testable exactly like
  `RestNotifyNlProvider`. Full analysis in design.md.
- **No inbound webhook (unlike Peppol/NotifyNL's `inbound()`).** No live KISS
  instance was available to verify whether KISS even offers a webhook
  mechanism for klantcontact changes; the cursor-based pull job is a
  strictly-safer default requiring zero KISS-side configuration. See
  design.md "Alternatives considered".
- **No `GET /api/kiss/klantcontacten` read endpoint for sibling apps.** Not
  required by the documented cross-app contract (procest only needs to
  push); pulled records are queryable via OpenRegister's generic object API
  like any other schema — see design.md "How procest should consume this".
- **`source.type = 'kiss'`** was added as a new recognised (free-form, per
  the schema's own documented extensibility) value, alongside
  `peppol`/`psd2`/`sms`/`payment`.
- **Pre-existing vendor gap fixed in passing (encountered while running the
  full test suite):** `symfony/dom-crawler` (+ `symfony/css-selector`,
  `masterminds/html5`) were declared in `composer.lock` but not present in
  the `vendor/` directory rsynced into this worktree from the canonical
  checkout — `composer install` closed the gap (no `composer.lock` change;
  purely a vendor/ population fix, `vendor/` is gitignored so this leaves no
  trace in the diff). Unrelated to this capability's own requirements.
