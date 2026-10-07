# Digital post writes as a service account, and an erasure redacts the opt-out log

Follows `opt-out-before-send` (integriq#2533) and `opt-out-per-purpose` (integriq#2543). Approved by Ruben on 2026-10-07.

## Why

**A letter sent without a user session is never stored.** `DigitalPostService::persist()` saves the `digitalPostMessage` object as whoever is signed in. A background job or an event fired from cron has nobody signed in, so OpenRegister refuses the write: `User 'Anonymous' does not have permission to 'create' objects in schema 'Digital Post Message'` (measured on 2026-10-05, iob-live run 2a). The send is then refused with `not_stored`, and nobody is told.

**An erasure leaves the evidence in the log.** `OptOutRegistry::eraseContact()` clears `contact_ref` and `evidence` on the opt-out row. The append-only `integriq_opt_out_log` keeps every earlier `change` entry with the address, the source, the lawful basis and the evidence (measured on 2026-10-05, iob-live run 6).

## Ruben's decisions (2026-10-07)

1. **Digital post runs as a service account**, on the consumer model integriq already uses for its webhooks and the DSO intake. The account is set by an administrator. When it is missing or disabled, the send is refused, logged and raised to the administrators. Nothing is dropped in silence. Reads of integriq's own configuration may stay `_rbac:false`; no write runs with RBAC off.
2. **An erasure redacts the earlier log entries** of that person. They keep the hashed key, the decision and the date. New events stay append-only.

## What changes

- A consumer with `authorizationType` `digital-post` names the account in `userId`, as every other consumer does.
- Every send and every status poll runs as that account through OpenRegister's `ObjectService::runAs()` (`setVolatileActiveUser()`, restored in a `finally`). The person who asked stays in `requestedBy`.
- `digitalPostMessage` and `outbound_message` leave the deny-all lockdown fragment and grant the group `digitale-post-verzenders` in the register. The repair step creates the group and enrols the account.
- An admin setting picks the account. A setup check says when digital post is configured but cannot be stored.
- On `erase-contact`, every earlier log entry for the erased addresses keeps only a hashed key, its kind, its decision fields and its date. The erasure's own entry is written with the hashed key.

## Capabilities

### Modified capabilities

- `digital-post-adapter`: adds REQ-DPA-007.
- `outbound-opt-out-authority`: adds REQ-OOA-013.

## Impact

- `lib/Service/DigitalPost/`: new `DigitalPostAccount`, `DigitalPostService` runs as it.
- `lib/BackgroundJob/DigitalPostStatusJob.php`: reads and polls as the account.
- `lib/Controller/DigitalPostAccountSettingsController.php`, `src/views/admin/DigitalPostAccountSettings.vue`: the admin setting.
- `lib/SetupCheck/DigitalPostAccountCheck.php`, `lib/Repair/ProvisionIntakeGroups.php`, `lib/Service/Intake/IntakeGroups.php`.
- `lib/Notification/DsoConnectionNotifier.php`: the digital post alert texts.
- `lib/Settings/integriq_register.json`, `lib/Settings/register.d/99-mail-schemas-lockdown.json`: `digitalPostMessage` and `outbound_message` 1.1.0 grant the group.
- `lib/Outbound/Identity/OptOutRegistry.php`, `lib/Db/OptOutLogMapper.php`, `lib/Outbound/Identity/RecipientKey.php`: the redaction.

## Rollback

Revert the PR. The consumer and the group stay and do nothing. Redacted log entries stay redacted: an erasure cannot be undone.

## Related

- integriq#2533, integriq#2543, dossiq#3281, pipelinq#2164 (the same model for the portal).
