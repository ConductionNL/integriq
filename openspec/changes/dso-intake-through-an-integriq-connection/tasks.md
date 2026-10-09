## 1. The connection

- [x] 1.1 Give `DSOSignatureVerifierService::verify()` the trust configuration as an argument (mode, HMAC secret, PEM chain) instead of reading app config, and verify every existing `DSOSignatureVerifierServiceTest` case still passes with the configuration passed in
- [x] 1.2 Build `Service\Dso\DsoConnection::authenticate()`: find the one `dso-stam` consumer (engine read, `_render: false`), verify, resolve and check the account, check `create` and `update` on `dso_verzoek`. Verify in PHPUnit each outcome: valid account returned; bad signature throws `DsoSignatureException`; `no_connection`, `no_account`, `account_unknown`, `account_disabled`, `account_lacks_rights` each throw `DsoConnectionUnavailableException` with that reason
- [x] 1.3 Refuse a second `dso-stam` consumer on save, and verify it with a test against the real schema fragment
- [x] 1.4 Correct the `consumer.userId` description in `integriq_register.json` to "the Nextcloud account this consumer acts as", and verify `authorizeApiKey()` behaviour is unchanged (`AuthorizationServiceTest` green)

## 2. Intake as the account

- [x] 2.1 Make `DSOController::receiveRequest()` call `DsoConnection::authenticate()` and run ingest inside `ObjectService::runAs($account, …)`. Verify through the controller: a signed push with a valid account calls ingest once, with the account active inside the callback and the previous user restored after (also when ingest throws)
- [x] 2.2 Map the D7 table onto responses: 401 for a bad signature, 503 with the named error for each `DsoConnectionUnavailableException` reason. Verify each row through the controller, red before the change
- [x] 2.3 Prove nothing is written when rights are missing: with an account that has `create` but not `update`, the endpoint answers 503 and `saveObject()` is never called. Red before the change (today the first save runs)
- [x] 2.4 Match a retry in `DsoIngestService::ingest()` by `verzoekId` (and `volgnummer`): `mapped` or `failed` found means no write and 202; `received` found means finish it; none found means create. Verify all three in PHPUnit
- [x] 2.5 Add `dso_verzoek.receivedVia` (`consumer`, `account`) to both registers, written by ingest, and verify a save keeps it through `RegisterSchemaValidator` against the real schema
- [x] 2.6 Send the admin notification for each 503 reason, at most once per reason per hour, and verify the throttle in PHPUnit

## 3. The attachment job as the account

- [x] 3.1 Queue `FetchDsoAttachmentsJob` with `actingUserId`, and verify the argument shape in `DsoIngestServiceTest`
- [x] 3.2 Run the fetcher inside `ObjectService::runAs()` for that user. Verify: the user is active during `fetchPending()` and restored after, also on a throw
- [x] 3.3 With an unknown or disabled `actingUserId`, verify the job writes nothing, logs an error naming verzoek and account, and notifies the admins; the entries stay `pending`
- [x] 3.4 Read the DSO source as an engine read (`_rbac: false`, `_render: false`) on the attachment path, and verify a non-admin account gets the token (`DsoAttachmentFetcherTest`)

## 4. Admin configuration

- [x] 4.1 Let `DsoPkiSettingsController` read and write the `dso-stam` consumer instead of app config, including `userId`. Verify GET never returns a secret or a private key
- [x] 4.2 Validate `userId` on save: unknown user, disabled user and a user without `create`/`update` on `dso_verzoek` are refused with a field error; an `admin` group member saves with a warning. Verify each in PHPUnit
- [ ] 4.3 Add an account picker (`NcSelectUsers` with `inputLabel`) and the one-line state to `src/views/admin/DsoPkiSettings.vue`, with en and nl strings through `l10n/*.json` and `npm run l10n:build`. Verify with the e2e test `tests/e2e/dso-connection-settings.spec.ts` (written; it runs in the nightly Playwright job, not locally)

## 5. Migration

- [x] 5.1 Build repair step `MigrateDsoStamConnection`: create the consumer from the `dso_pki_*` keys when none exists, `userId` empty, then notify the admins once. Verify: keys present creates one consumer; a second run creates nothing; no keys creates nothing
- [x] 5.2 Verify `git grep -n "runAsSystem" lib/` lists only the repair steps, and `git grep -n "_rbac: false" lib/Service/Dso lib/Service/DsoIngestService.php lib/BackgroundJob/FetchDsoAttachmentsJob.php lib/Controller/DSOController.php` lists only `find()` reads, no `saveObject()`

## 6. Proof

- [x] 6.1 On a throwaway instance (Nextcloud 35 + postgres, `appstoreenabled=false`, the HMAC setup of `~/memcap-work/fleet-appdir/integriq-dso/live`): create a non-admin account `dso-intake` with `create`/`update` on `dso_verzoek`, set it on the connection, push a signed verzoek with three bijlagen anonymously, run cron. Verify: 202, one record, `receivedVia.account` is `dso-intake`, the audit trail names `dso-intake` for create and update, three files stored, nothing under `DSO-verzoeken`
- [x] 6.2 On the same instance: clear the account and push again. Verify 503 `dso_account_unavailable`, no new record, one admin notification. Push the first verzoek a second time and verify no second record
- [x] 6.3 On the same instance: start from app config only (no consumer), run `occ maintenance:repair`, and verify one `dso-stam` consumer with the old trust and an empty account, plus one admin notification
- [x] 6.4 Run `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint` once before push, and record the exit codes in the PR body
