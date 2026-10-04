Ruben approved the model on 2026-10-04: "same consumer model, after DSO", with the live proof first.

## 1. The connection

- [x] 1.1 Prove the failure live before changing code: a correctly signed submission without a login on a throwaway instance (`iof-live/commands.md` run-1a to run-1d)
- [x] 1.2 Let `DsoConnection::findConsumers()` take the type, `DsoAccountRights::missing()` the schema, and `DsoConnectionUnavailableException` and `DsoConnectionAlerts` a channel. Verify every DSO test stays green
- [x] 1.3 Build `OpenFormulierenConnection::authenticate()`: find the one `open-formulieren` consumer (engine read), verify with `WebhookSignatureService`, resolve the account, check `create` and `update` on `openformulieren_submission`
- [x] 1.4 Refuse a second `open-formulieren` consumer in `DsoStamConsumerListener`, and verify a DSO and an Open Formulieren consumer pass side by side

## 2. Intake as the account

- [x] 2.1 Make `OpenFormulierenController::inbound()` authenticate through the connection and run ingest inside `runAs()`. Verify through the real path (controller, connection, intake service, OpenRegister's `runAs()`): every submission write and every attachment file runs as the account, and the anonymous user is restored after
- [x] 2.2 Map D4 onto responses (401, 503 per reason, 503 `submission_not_stored`) and alert the admins on the Open Formulieren channel. Verify each row, red before the change
- [x] 2.3 Prove nothing is written when `update` is missing or the rights cannot be checked
- [x] 2.4 Match a repeated delivery by `submissionUuid`. Verify the second delivery writes nothing and fetches no attachment

## 3. The record

- [x] 3.1 Add `openformulieren_submission.receivedVia` (`consumer`, `account`) to both registers, version 1.2.0, and validate every written submission against the real register schema

## 4. Admin configuration

- [x] 4.1 Add `OpenFormulierenSettingsController` (GET never returns the secret; a blank secret keeps the stored one; unknown, disabled and right-less accounts refused with a field error; an admin account warns)
- [x] 4.2 Add `OpenFormulierenConnectionSettings.vue` with the account picker and the one-line state, en and nl strings through `l10n/*.json` and `npm run l10n:build`
- [ ] 4.3 Run `tests/e2e/openformulieren-connection-settings.spec.ts` (written; it runs in the nightly Playwright job, not locally)

## 5. Migration

- [x] 5.1 Add the repair step `MigrateOpenFormulierenConnection` and bump the app version so it runs on upgrade. Verify: the source trust becomes one consumer without an account, a second run creates nothing, no usable source creates nothing

## 6. Live proof

- [x] 6.1 On the throwaway instance after the change: a signed submission without a login is stored, owned by the intake account, attachments stored; no account answers 503 (`iof-live/commands.md`)
