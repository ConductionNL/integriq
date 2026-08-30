# Migration: hitl-approval-rule-action

## Current State

No `approval_request` schema, `approval` rule action type, or
`requiresApproval` Synchronization flag exists. `lib/actions.seed.json` has
no `approval.approve`/`approval.reject` entries.

## Target State

An `approval_request` schema exists on the `openconnector` OpenRegister
register (added via a `register.d` fragment, not a raw SQL table — see
below), plus the new `approval.approve`/`approval.reject` ADR-023
action-matrix entries and the new cron job registration.

## Migration Class

**None.** This change does not require an `lib/Migration/VersionXXXXXXXXXX.php`
class. OpenConnector schemas (like every other schema in this app,
including the most recently added `eudi_credential_offer`/
`eudi_issuance_session`/`eudi_status_list` trio from the
`eudi-wallet-credential-issuance` change, which also shipped without a
migration.md/Migration class) are OpenRegister objects, not custom SQL
tables — they are declared via a `register.d/*.json` fragment
(`lib/Settings/register.d/hitl-approval-rule-action.json`) and picked up by
the existing `lib/Repair/InitializeRegister.php` repair step's
`deepMergeConfig()`, which merges the fragment into the live register
descriptor on app boot/upgrade. No `changeSchema()` / NC `IOutput` DB
migration is involved.

The ADR-023 action-matrix entries are seeded the same way as every other
action in this app: via `lib/actions.seed.json` + the existing
`InitializeActions` repair step (also not an NC Migration class).

## Migration Steps

1. Ship `lib/Settings/register.d/hitl-approval-rule-action.json` declaring
   the `approval_request` schema (Task 1).
2. Ship the two new entries in `lib/actions.seed.json` (Task 2).
3. On app upgrade, `InitializeRegister`'s repair step merges the new schema
   into the `openconnector` register; `InitializeActions`'s repair step adds
   the two new action-matrix entries (both repair steps already run
   idempotently on every upgrade — no new repair-step registration needed).
4. Register `ApprovalTimeoutSweepJob` in `lib/AppInfo/Application.php`
   alongside the existing five cron jobs (Task 10) — NC's `IJobList`
   auto-registers `TimedJob` subclasses declared in `Application::register()`
   on next app load; no separate activation step.
5. Ship seed data (Task 15) so the feature is demoable immediately after
   upgrade (company-wide ADR-016).

Each step is independently deployable and additive — none of them modify or
require reprocessing of existing data.

## Data Impact

Zero existing records are affected — no existing schema, table, or object
is modified, renamed, or reshaped. Zero data loss. Fully safe to run on live
data; the new schema and action-matrix entries are additive-only and the app
functions identically for every existing endpoint/synchronization until an
admin explicitly configures an `approval` rule or `requiresApproval` flag.

## Rollback Procedure

Since there is no NC Migration class, there is no `changeSchema()` to
reverse. Rollback is: revert the app to a prior release (or the specific
commit range). Any `approval_request` objects already created during the
rolled-back window become orphaned-but-harmless OpenRegister objects (no
foreign keys from other schemas point at them); they can be left in place
or bulk-deleted via the standard OpenRegister object-management UI/CLI if a
clean rollback is desired. The two `actions.seed.json` entries and the
`ApprovalTimeoutSweepJob` registration disappear on rollback along with the
reverted code; no separate "unseed" step is needed since seeding is
idempotent-on-presence, not destructive.

## Validation

- `python3 -c "import json;json.load(open('lib/Settings/register.d/hitl-approval-rule-action.json'))"`
  parses without error (mirrors the existing validation step used by
  `openconnector-notifications`'s tasks.md).
- After upgrade, confirm the `approval_request` schema is queryable via the
  OpenRegister admin UI (Settings > OpenRegister > openconnector register).
- Confirm `approval.approve`/`approval.reject` appear in Admin Settings >
  OpenConnector > Action Authorization, both defaulting to `admin`.
- Confirm `ApprovalTimeoutSweepJob` appears in `occ background-job:list`
  after the next app load.
