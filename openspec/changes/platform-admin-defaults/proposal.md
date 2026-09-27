---
kind: code
depends_on: []
---

# Proposal: platform-admin-defaults

## Summary

Integriq's log retention can be changed today only with
`occ config:app:set`, and the three services that write logs each carry their
own fallback, one of which disagrees with the others. This change puts the
retention defaults on integriq's Nextcloud admin page, reads them through one
resolver whose defaults match the schemas' declared retention, and replaces
the rebase action that still updates tables integriq no longer writes to.

## Why

Matrix row `integriq:plt-admin-settings`, "Change integriq's defaults, such as
retention, on an admin settings page." Integriq rates it `partial` with
`built.state` `built`. Matrix note: "The admin page is real and wired into
Nextcloud's settings framework, but it sets the action matrix and the DSO PKI
signature config, not app defaults. Log retention can only be changed via occ
config:app:set, and POST /api/settings/rebase (SettingsController.php:82) has
no caller and still targets legacy openconnector_* tables."

There is no demand row. Competitors rated `yes`:

- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/access-management/audit-log-retention.md: users
  with "Audit Log Config Manager and Organization Administrator permissions can
  configure the retention period in Access Management"; and
  https://docs.mulesoft.com/monitoring/monitoring-settings-page.md.
- WSO2 API Manager (`wso2`), source read at v4.7.0:
  "admin-api.yaml:3924 /tenant-config and ... :4000 /tenant-config-schema back
  the admin portal Advanced settings page". No evidence URL is recorded for this
  cell.

This change covers one row: `integriq:plt-admin-settings`.

## What integriq already has

- The admin page: `lib/Settings/IntegriqAdmin.php` extends AppHost's
  `GenericAdminSettings`, and `src/views/admin/AdminSettings.vue:12` renders
  `ActionAuthMatrix` and `DsoPkiSettings` only.
- Retention is read from the app config key `integriq` / `retention` in three
  places, each with its own fallback:
  - `lib/Service/JobService.php:113` to `:121` (30 days error, 1 hour success),
  - `lib/Service/CallService.php:262` to `:270` (30 days error, 1 hour success),
  - `lib/Service/SynchronizationService.php:633` to `:642`, falling back to
    `DEFAULT_ERROR_LOG_RETENTION = 259200000` at `:384`, which is 3 days.
- `lib/Service/SettingsService.php:135` (`getSettings()`) reports the
  synchronization log default as 30 days (`2592000000`), and the
  `synchronization_log` schema declares `P30D` in its
  `x-openregister-archival` block (`lib/Settings/integriq_register.json:2611`).
  Yet `lib/Service/SynchronizationService.php:3230` writes each
  synchronization log with the 3-day fallback, so an instance without the
  config key keeps synchronization logs for 3 days while every document says
  30.
- There is no route that writes retention. `SettingsService` has no update
  method, although `logs-and-statistics` REQ-004 describes
  `updateSettings()`.
- `lib/Service/SettingsService.php:180` (`rebase()`) runs SQL `UPDATE`s on
  `openconnector_call_logs` and `openconnector_event_messages`, tables the logs
  no longer live in; logs are OpenRegister objects.

## What this change builds

1. One `RetentionSettings` resolver with defaults equal to the schemas'
   declared retention, read by the job, call and synchronization services.
2. The settings `index` and `update` routes in the ADR-076 dialect, admin
   only, for the retention defaults.
3. A retention section on the admin page, in days and hours rather than
   milliseconds, with the default shown next to each value.
4. "Apply to existing logs", which queues a background job that recomputes
   `expires` on existing log objects in bounded pages, replacing the SQL
   rebase on the legacy tables.

## Out of scope

- Other defaults, such as the default retry policy or the default page size.
  The settings shape leaves room for them; each needs its own row.
- Changing OpenRegister's handling of `x-openregister-archival`.
