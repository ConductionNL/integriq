# Migration: notificaties-api-subscriber

## Current State
`lib/Settings/integriq_register.json` declares the `openconnector` register's schemas, including
`event_subscription` (with `action.kind ∈ {webhook, synchronization, job}`, per `nextcloud-event-hub`) and
`consumer` (apiKey/jwt/basic/oauth2/none auth). There is no `notificaties_abonnement` schema, and
`event_subscription.action.kind` has no `notificaties` value. All OR schemas in this app are stored
schema-less (JSON column) — there is no per-schema SQL table to migrate.

## Target State
- `integriq_register.json` gains a new `notificaties_abonnement` schema entry (slug
  `notificaties_abonnement`) with fields `sourceId`, `kanalen` (array of `{naam, filters}`), `callbackUrl`,
  `url` (remote-assigned), `consumerId`, `status` (`pending|active|error|deleted`), `lastError`, `created`,
  `updated`.
- `event_subscription.action.kind` enum gains a fourth value, `notificaties`, and the `action` object's
  documented shape gains the optional `kanaal`, `hoofdObjectField`, `resourceField`, `actieMap`,
  `kenmerken` keys (all additive — no existing key is renamed or removed).
- `openconnector` register's `schemas` list gains `notificaties_abonnement`.

## Migration Class
No `lib/Migration/VersionXXXXXXXXXX.php` is required. Following the exact precedent set by
`nextcloud-event-hub`'s `action`/`retryPolicy` additions to `event_subscription` (see that change's
archived `migration.md`/`design.md`): OR schemas are schema-less JSON-column storage, so a new schema
entry or a new optional field on an existing schema is a `lib/Settings/integriq_register.json`
descriptor change only, picked up by OpenRegister's existing register-sync mechanism (`register-import`)
on next app upgrade/repair run — not a Nextcloud DB migration.

```
Version: N/A — no lib/Migration/VersionXXXXXXXXXX.php required
File: lib/Settings/integriq_register.json (descriptor change only)
Key operations:
- Add `notificaties_abonnement` schema entry to `components.schemas`
- Add `notificaties_abonnement` to the register's top-level `schemas` array
- Add `notificaties` to `event_subscription.action.kind`'s documented enum (description text; OR schemas
  do not enforce a hard `enum` constraint on this field today — action.kind is validated in application
  code, `EventService::attemptDelivery()`'s switch, not at the OR schema layer)
```

## Migration Steps
1. Land the `integriq_register.json` descriptor change (new schema + updated `action.kind`
   description) in the same PR as the code that reads/writes it — descriptor and code MUST ship together
   so a partially-upgraded instance never has `EventService` code expecting a schema the register hasn't
   registered yet.
2. On app upgrade, OpenRegister's existing register-sync repair step picks up the new schema entry — no
   manual admin action required (same mechanism every prior schema addition in this register used).
3. Seed data (design.md "Seed Data" section) is inserted via the standard `_registers.json` seed mechanism
   on fresh install only — NOT backfilled onto existing instances (no existing instance has
   `notificaties_abonnement` records to migrate).
4. No existing `event_subscription` record needs to change — `action` is optional and defaults to
   `{kind: 'webhook'}` exactly as before; the new `notificaties` value is purely additive to the enum.

## Data Impact
Zero existing records are affected. No existing `event_subscription` sets `action.kind = 'notificaties'`
before this ships (the value did not exist). No existing `consumer` record is touched. This migration adds
capacity, transforms nothing, and is fully safe to run on live data — there is, in fact, no data
transformation step at all, only a schema-descriptor addition.

## Rollback Procedure
Revert the `integriq_register.json` descriptor change and the accompanying code in the same PR
revert. Any `notificaties_abonnement` records created in the interim become orphaned OR objects (no code
references the removed schema after rollback) — harmless, and cleanable via a follow-up repair step if
desired, but not required for correctness (matches proposal.md's Rollback Strategy).

## Validation
- After upgrade, `GET /apps/openregister/api/registers/openconnector` (or the equivalent Settings screen)
  SHALL list `notificaties_abonnement` among the register's schemas.
- Creating a subscription with `action = {kind: 'notificaties', sourceId: '<uuid>', kanaal: 'zaken'}` via
  `POST /api/events/subscriptions` SHALL persist successfully (no schema-level rejection) and MUST NOT
  affect any pre-existing subscription's stored `action` value.
- Existing PHPUnit coverage for `events-cloudevents` REQ-008 (webhook/synchronization/job dispatch) MUST
  continue to pass unmodified — confirms the additive change did not regress the pre-existing three kinds.
