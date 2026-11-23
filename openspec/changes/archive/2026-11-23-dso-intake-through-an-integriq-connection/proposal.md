# Proposal: dso-intake-through-an-integriq-connection

kind: code. Cites **ADR-022** (apps consume OpenRegister abstractions) and **ADR-099** (scoped identity, `runAs` over `runAsSystem`).

## Why

DSO-LV pushes a verzoek to `POST /api/dso/stam/verzoeken` without a Nextcloud user. That is how the real koppelvlak works. The endpoint checks the signature and then asks OpenRegister to save a `dso_verzoek`. OpenRegister refuses `create` for user Anonymous.

A live run on 2026-10-04 proved this end to end (throwaway Nextcloud 35, OpenRegister development 98a3469):

- A correctly signed push answered 202, and nothing was stored. The log said: `User 'Anonymous' does not have permission to 'create' objects in schema 'DSO Verzoek'`.
- `FetchDsoAttachmentsJob` failed the same way under cron. It could not read the admin-only DSO source, so it had no token, and its `update` was refused.
- With an admin user active, everything worked: mapping, three bijlagen stored, a 404 bijlage flagged.

The PR `fix(dso): the STAM intake answers 503, not 202` stops the silent loss. It does not give the intake an identity, so on a real instance every push now gets a 503. This change gives it one.

Integriq already knows who calls it. A `consumer` describes an outside system that may call integriq, and `AuthorizationService::authorizeApiKey()` already turns a consumer's credential into its backing Nextcloud user. The DSO intake skips all of that with its own controller and its own app-config secret. Ruben decided on 2026-10-04 that connections run through integriq.

## What changes

- **DSO-LV becomes a consumer.** One `consumer` with `authorizationType: dso-stam` holds the signature trust (HMAC secret in pre-production, PKIoverheid chain in production) and the Nextcloud account the intake acts as (`userId`).
- **The signature check is that consumer's authentication.** `DSOSignatureVerifierService` verifies against the consumer's configuration instead of app config. A valid signature resolves to the consumer, and the consumer to its account.
- **Ingest runs as that account.** The controller wraps `DsoIngestService::ingest()` in OpenRegister's `ObjectService::runAs()`. Every write has an owner in the audit trail and passes RBAC. No `_rbac: false` on a write, no `runAsSystem()`.
- **The attachment job runs as the same account.** The job carries the account id captured at intake and runs under `runAs()`. A vanished account means no write, an error log and an admin notification.
- **Failure is loud.** No DSO consumer, no account, an unknown or disabled account, or missing rights: the endpoint answers 503 so DSO-LV retries, logs the reason, and notifies the administrators. Rights are checked before the first write, so nothing is stored half.
- **A retry finds its record.** A second push with the same verzoeknummer and volgnummer updates nothing twice and creates no second record.
- **The account is validated when it is set.** The DSO connection settings refuse an unknown or disabled user, and a user without `create` and `update` on `dso_verzoek`.
- **Existing configuration migrates.** A repair step copies the `dso_pki_*` app config into the new consumer. The account stays empty: no step guesses an identity. Until an admin sets it, the intake answers 503 and the admins get one notification.

## Capabilities

### Modified Capabilities

- `dso-omgevingsloket`: REQ-DSO-001 (identity, 503 instead of a false 202, retry matching) and REQ-DSO-050 (where the signature trust lives). New REQ-DSO-070 (the intake and its job act as the connection's account).
- `consumer-management`: new requirement for the `dso-stam` authorization type.

`endpoint-runtime` does not change. Design section "Rejected: move the intake onto the endpoint runtime" says why.

## Impact

- New: `lib/Service/Dso/DsoConnection.php` (finds the consumer, verifies, resolves the account, checks rights), `lib/Repair/MigrateDsoStamConnection.php`.
- Changed: `DSOController::receiveRequest()`, `DSOSignatureVerifierService` (config as input), `DsoIngestService` (match a retry, carry the account to the job), `FetchDsoAttachmentsJob` (run as the account), `DsoPkiSettingsController` and `src/views/admin/DsoPkiSettings.vue` (edit the consumer, pick the account), `lib/Settings/integriq_register.json` (`consumer.userId` description, `dso_verzoek.receivedVia`).
- Depends on OpenRegister's `ObjectService::runAs()` (concrete class, already injected) and `PermissionHandler::hasPermission()` (not a published contract, resolved lazily).
- Operators: one admin step after upgrade, choosing the account. The DSO-LV push URL does not change.
