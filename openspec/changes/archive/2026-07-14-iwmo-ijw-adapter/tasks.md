# Tasks — iwmo-ijw-adapter

## 1. Data model

### Task 1: Declare the `iwmo_ijw_message` schema
- **spec_ref**: `openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-per-message-audit-persistence-and-isolated-retry-req-005`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the register loads THEN an `iwmo_ijw_message` schema exists with `direction` enum `outbound|inbound`, `berichttype`, `domain` enum `wmo|jw`, `status`, `ref`, `kenmerk`, `caseReference`, `error`
  - GIVEN the register's schemas list WHEN compared to `components.schemas` THEN every declared schema slug (including `iwmo_ijw_message`) is listed
  - GIVEN `source.type`'s documented recognised values WHEN read THEN `iwmo-ijw` is listed alongside `kiss`/`sms`/`peppol`/`psd2`/`payment`
- [x] Implement
- [x] Test

## 2. Provider abstraction

### Task 2: Add the IwmoIjw provider interface with log and REST bindings
- **spec_ref**: `openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-iwmoijw-provider-abstraction-with-log-and-rest-bindings-req-001`
- **files**: `lib/Service/IwmoIjw/IwmoIjwProviderInterface.php`, `lib/Service/IwmoIjw/LogIwmoIjwProvider.php`, `lib/Service/IwmoIjw/IStandaardenClient.php`, `lib/Exception/IwmoIjwProviderException.php`
- **acceptance_criteria**:
  - GIVEN `configuration.provider: log` (or absent) WHEN `send()` is called THEN a synthetic `MOCK-IWMO-<n>` ref is returned with no HTTP call and no credential read
  - GIVEN `configuration.provider: rest` WHEN `send()` is called THEN `Authorization: Bearer <decrypted-token>` is sent with the envelope XML as the raw body
  - GIVEN a non-2xx or transport failure WHEN `send()` is called THEN `IwmoIjwProviderException` is raised with a secret-free message
- [x] Implement
- [x] Test

## 3. Translators

### Task 3: Add OutboundBerichtTranslator (toewijzing/declaratie -> Wmo/Jw envelope) with literal-leak guard
- **spec_ref**: `openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-outbound-berichttype-translation-with-a-literal-leak-guard-req-002`
- **files**: `lib/Service/IwmoIjw/OutboundBerichtTranslator.php`, `lib/Exception/IwmoIjwTranslationException.php`
- **acceptance_criteria**:
  - GIVEN a complete toewijzing case object WHEN translated with `domain: wmo`/`domain: jw` THEN a `Wmo303`/`Jw303` envelope is returned carrying every required field
  - GIVEN a complete declaratie case object WHEN translated THEN a `Wmo321`/`Jw321` envelope is returned
  - GIVEN a missing required field WHEN translated THEN `IwmoIjwTranslationException` is raised naming the field and no XML is returned
  - GIVEN a rendered envelope WHEN scanned THEN no `{{`/`}}`/`%%UNRESOLVED%%` markers survive
- [x] Implement
- [x] Test

### Task 4: Add InboundRetourTranslator (retour XML -> OR case status update)
- **spec_ref**: `openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-inbound-retour-translation-to-an-or-case-status-update-req-003`
- **files**: `lib/Service/IwmoIjw/InboundRetourTranslator.php`
- **acceptance_criteria**:
  - GIVEN a Wmo304/Jw304 retour with `resultaat: akkoord` WHEN translated THEN `status: accepted` is returned
  - GIVEN a Wmo302 retour with no `resultaat` WHEN translated THEN `status: rejected` is returned (never a silent accept)
  - GIVEN Wmo305/Wmo307 retours WHEN translated THEN `status: care_started`/`care_stopped` with their timestamps are returned
  - GIVEN a Wmo322 retour WHEN translated THEN `status: invoice_processed`/`invoice_rejected` and `paymentReference` are derived from `betaalstatus`/`betalingReferentie`
  - GIVEN a retour with an empty/missing `kenmerk` WHEN translated THEN `IwmoIjwTranslationException` is raised before any OR write
- [x] Implement
- [x] Test

## 4. Sync orchestration

### Task 5: Add IwmoIjwSyncService (send, receive retour, retry, AVG redaction)
- **spec_ref**: `openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-per-message-audit-persistence-and-isolated-retry-req-005`
- **files**: `lib/Service/IwmoIjwSyncService.php`
- **acceptance_criteria**:
  - GIVEN a successful outbound send WHEN `sendBericht()` completes THEN an `iwmo_ijw_message` record is persisted `direction: outbound`, `status: sent`, with the provider's `ref`
  - GIVEN a provider exception WHEN `sendBericht()` runs THEN a `status: failed` record is persisted with `error` set, and the exception propagates to the controller
  - GIVEN a raw BSN in the push payload WHEN persisted THEN the `iwmo_ijw_message` record contains only its SHA-256 hash, never the raw value
  - GIVEN two failed rows, one erroring on retry WHEN `retryFailed()` runs THEN the failing row is logged/skipped and the other is still retried
  - GIVEN no active `type=iwmo-ijw` source WHEN `sendBericht()`/`receiveRetour()` run THEN `IwmoIjwProviderException` ("No active iWMO/iJW source...") is raised cleanly
- [x] Implement
- [x] Test

## 5. REST surface + scheduled job

### Task 6: Add IwmoIjwController (push + signed inbound retour) + routes
- **spec_ref**: `openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-push-endpoint-and-signed-inbound-retour-receiver-req-004`
- **files**: `lib/Controller/IwmoIjwController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an authenticated session and a configured source WHEN `POST /api/iwmo-ijw/berichten` is called with a complete payload THEN `{ref, berichttype}` is returned
  - GIVEN no active source WHEN posted THEN HTTP 503 `not_configured` is returned
  - GIVEN an unsigned/tampered `POST /api/iwmo-ijw/retour` WHEN received THEN HTTP 401 is returned with no state change
  - GIVEN a verified retour WHEN processing internally fails THEN the endpoint still responds `{received: true}` (never 500)
- [x] Implement
- [x] Test

### Task 7: Add IwmoIjwRetryJob (hourly TimedJob) + info.xml registration
- **spec_ref**: `openspec/changes/iwmo-ijw-adapter/specs/iwmo-ijw-adapter/spec.md#requirement-per-message-audit-persistence-and-isolated-retry-req-005`
- **files**: `lib/Cron/IwmoIjwRetryJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN no failed/pending `iwmo_ijw_message` rows WHEN the job runs THEN it completes as a no-op (0 retried), no exception, no error log
  - GIVEN a sweep-level exception WHEN the job runs THEN it is caught and logged — never wedges the cron pipeline
  - GIVEN the job IS wired in `info.xml` and routes exist AND a test proves the job actually invokes `IwmoIjwSyncService::retryFailed()` THEN the orphaned-capability rule is satisfied (not just declared)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate --strict` passes (this change only)
- [x] Manual testing against acceptance criteria (sandbox `log` provider path) — exercised via the PHPUnit suite
- [x] Code review against spec requirements — self-reviewed; see Deviations below
- [x] `composer lint`, `composer cs:check`, `phpmd`, `phpstan` clean on the new files; full suite diffed against the pristine `origin/development` baseline (1 pre-existing unrelated failure: `SynchronizationServiceTest::testHtmlSourceExtractsTableRowsViaCssSelectors`, missing `symfony/dom-crawler` in the rsynced vendor snapshot — present before this change, untouched by it)

## Deviations

- **No client-certificate (mTLS) transport auth.** The real GGk/VECOZO
  connection historically uses client certs, not a bearer token.
  `IStandaardenClient` implements token auth only — documented explicitly
  in design.md "Open Questions", not a silent omission. The provider seam
  isolates adding client-cert support later to `IStandaardenClient` alone.
- **Berichttype 301 (Verzoek om toewijzing) is out of scope** — only 303/321
  outbound and their retour family (302/304/305/306/307/308/322) are
  implemented; see design.md's berichttype table.
- **No live GGk/VECOZO instance was available to verify the message shape
  against** — every berichttype/field/envelope assumption is documented in
  design.md "Message-shape assumptions", grounded in the published
  iStandaarden berichttype naming convention and this app's own
  `kiss-kcc-bridge`/`vng-klantinteracties-adapter` precedent.
- **Credential storage does not use `credentialRef`/`BrokeredCallService`**
  — same reasoning as `kiss-kcc-bridge`'s identical, already-accepted
  deviation (self-containment, no optional-class dependency). Full analysis
  in design.md.
- **`source.type = 'iwmo-ijw'`** was added as a new recognised (free-form,
  per the schema's own documented extensibility) value.
