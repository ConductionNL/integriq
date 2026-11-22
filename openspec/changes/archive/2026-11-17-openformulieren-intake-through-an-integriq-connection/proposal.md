# Proposal: openformulieren-intake-through-an-integriq-connection

kind: code. Cites **ADR-022** (apps consume OpenRegister abstractions) and **ADR-099** (scoped identity, `runAs` over `runAsSystem`). Follows `dso-intake-through-an-integriq-connection` (#2499) and reuses its building blocks.

## Why

Open Formulieren posts a submission to `POST /api/open-formulieren/submissions` without a Nextcloud user. The endpoint read the admin-only `open-formulieren` source to find the webhook secret, then saved an `openformulieren_submission` with no user.

A live run on 2026-10-04 proved it fails for every real submission (throwaway Nextcloud 35, OpenRegister development e80cd62, integriq development 92d334b6d):

- A correctly signed submission without a login got 401 `invalid signature`. Nothing was stored.
- integriq's own `WebhookSignatureService::verify()` accepted the same bytes and header. The signature was right.
- An anonymous list of `source?type=open-formulieren` returned 0 results. The source is admin-only, so `resolveActiveSource()` found nothing and the endpoint failed closed.
- The same signed request with an admin session was stored, mapped, with both attachments. Only a logged-in admin got through.

Ruben decided on 2026-10-04: Open Formulieren moves onto the same consumer model as DSO, after DSO.

## What changes

- **Open Formulieren becomes a consumer.** One `consumer` with `authorizationType: open-formulieren` holds the webhook trust (scheme, secret, header, tolerance) and the account the intake acts as (`userId`).
- **The signature check is that consumer's authentication.** `OpenFormulierenConnection::authenticate()` reads the consumer as an engine read, verifies with `WebhookSignatureService`, resolves the account and checks `create` and `update` on `openformulieren_submission`. All before the first write.
- **Ingest runs as that account,** inside OpenRegister's `ObjectService::runAs()`. The attachment files are stored as the same account. No `_rbac: false` on a write, no `runAsSystem()`.
- **Failure is loud.** No connection, no usable account or missing rights: 503 with a named error, nothing written, an error log and one admin notification per reason per hour. A write OpenRegister refuses anyway: 503 `submission_not_stored`.
- **A retry finds its record.** A second delivery with the same `submission.uuid` creates no second record.
- **The record says who delivered it.** `openformulieren_submission.receivedVia` holds the consumer and the account.
- **The account is validated when it is set.** A new admin section edits the consumer with an account picker.
- **Existing configuration migrates.** A repair step copies the source's `webhookSignature` into the consumer with an empty account.

## Capabilities

### Modified Capabilities

- `open-formulieren-intake`: REQ-001 (where the trust lives, 503 instead of 401 for a missing connection). New REQ-006 (the intake acts as the connection's account) and REQ-007 (the account is chosen and checked by an administrator).
- `consumer-management`: new requirement for the `open-formulieren` authorization type.

## Impact

- New: `lib/Service/OpenFormulieren/OpenFormulierenConnection.php`, `lib/Controller/OpenFormulierenSettingsController.php`, `lib/Repair/MigrateOpenFormulierenConnection.php`, `src/views/admin/OpenFormulierenConnectionSettings.vue`.
- Changed: `OpenFormulierenController::inbound()`, `OpenFormulierenIntakeService::ingest()` (retry match, `receivedVia`; `resolveActiveSource()` removed), the shared DSO blocks (`DsoConnection::findConsumers()` takes the type, `DsoAccountRights::missing()` takes the schema, `DsoConnectionAlerts::notify()` and `DsoConnectionUnavailableException` take a channel, `DsoConnectionNotifier` renders per channel, `DsoStamConsumerListener` also guards `open-formulieren`), both registers (`openformulieren_submission` 1.2.0 with `receivedVia`).
- Operators: one admin step after upgrade, choosing the account. The webhook URL and the signature do not change.
