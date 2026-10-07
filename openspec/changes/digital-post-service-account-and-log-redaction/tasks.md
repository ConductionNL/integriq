# Tasks: digital-post-service-account-and-log-redaction (integriq)

Approved by Ruben on 2026-10-07. Tests fail first, through the real path with the acting user.

## 1. Digital post service account

- [ ] 1.1 `DigitalPostAccount` finds the `digital-post` consumer, resolves and checks its account, and runs an operation as it.
  - spec_ref: `specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007`
  - files: `lib/Service/DigitalPost/DigitalPostAccount.php`, its test
- [ ] 1.2 `DigitalPostService` sends and polls as the account; a missing or unusable account refuses with `no_service_account`, logs and notifies.
  - files: `lib/Service/DigitalPost/DigitalPostService.php`, `lib/BackgroundJob/DigitalPostStatusJob.php`, `tests/Unit/Service/DigitalPost/DigitalPostServiceTest.php`
- [ ] 1.3 `digitalPostMessage` 1.1.0 grants `digitale-post-verzenders`; the repair step creates it and enrols the account.
  - files: `lib/Settings/integriq_register.json`, `lib/Service/Intake/IntakeGroups.php`, `lib/Repair/ProvisionIntakeGroups.php`
- [ ] 1.4 Admin setting to pick the account, setup check, alert texts in Dutch and English, version bump.
  - files: `lib/Controller/DigitalPostAccountSettingsController.php`, `appinfo/routes.php`, `src/views/admin/DigitalPostAccountSettings.vue`, `lib/SetupCheck/DigitalPostAccountCheck.php`, `lib/Notification/DsoConnectionNotifier.php`, `l10n/`, `appinfo/info.xml`

## 2. Log redaction

- [ ] 2.1 On `erase-contact`, earlier entries for the erased addresses keep only the hashed key, kind, decision and date; the erasure entry carries the hashed key.
  - spec_ref: `specs/outbound-opt-out-authority/spec.md#requirement-an-erasure-redacts-the-earlier-log-entries-req-ooa-013`
  - files: `lib/Outbound/Identity/OptOutRegistry.php`, `lib/Db/OptOutLogMapper.php`, `lib/Outbound/Identity/RecipientKey.php`, `tests/Unit/Outbound/Identity/OptOutRegistryTest.php`

## 3. Live

- [ ] 3.1 dossiq to integriq digital post to a fake HTTP provider: interactive, no session, besluit to an opted-out citizen, ordinary letter to an opted-out citizen, provider error.
- [ ] 3.2 Erase a contact; the earlier entries no longer show the address.
