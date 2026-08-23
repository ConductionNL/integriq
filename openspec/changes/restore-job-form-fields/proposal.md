---
kind: code
depends_on: []
---

## Why

The Jobs index create/edit dialog rendered **5 of the 12 authorable job properties** —
description, interval, isEnabled, jobClass, name — in alphabetical order. Neither the field
set nor the ordering was chosen:

1. **`pages[Jobs].config.includeFields`** whitelisted exactly those five (plus `arguments`),
   so `timeSensitive`, `allowParallelRuns`, `singleRun`, `scheduleAfter`, `userId`,
   `logRetention` and `errorRetention` were unreachable. Jobs has **no detail page** — the
   manifest declares only `Jobs` and `JobLogs` — so this dialog is the *only* job editor in
   the app and those seven properties could not be set from any UI at all. `logRetention`
   and `errorRetention` are read by `JobService::executeJob()` to compute per-job log
   expiry, so the gap had real operational consequences.
2. **No `order` was declared anywhere** for the `job` schema, so `fieldsFromSchema()` fell
   through to its last resort, `keyA.localeCompare(keyB)`. The alphabetical sequence was the
   absence of a decision, not a decision.

The deleted legacy modal (`src/modals/Job/EditJob.vue`, removed in `c999f8fd`) had all of
these plus a 2×2 checkbox grid. Two further defects surfaced while restoring it:

3. **`scheduleAfter` was unrenderable.** It is `format: date-time` → widget `datetime`, and
   `JobFormFields.textFieldType()` mapped that to `'datetime-local'` fed into NcTextField's
   `type` prop, whose validator accepts only text/password/email/tel/url/search/number.
   Even had the field been included, it would have produced a prop-validation warning and a
   broken input.
4. **`FlowAction` was missing from the class picker.** `lib/Action/` holds four classes;
   `JOB_CLASS_OPTIONS` hardcoded three. `FlowAction` is dispatched by the same
   `JobService::executeJob()` → `container->get($jobClass)` path as its siblings, so its
   absence was an oversight in a hand-maintained list, not a policy.

## What Changes

**No PHP logic changes.** One new register fragment, one manifest block, one new helper
module, one SFC rewrite, one new test suite.

- **`lib/Settings/register.d/job-form-fields.json`** (new, ADR-037) — declares `order` for
  the 12 authorable properties and `default` for `interval` (3600) and `logRetention`
  (3600). Bumps the `job` schema 1.1.0 → 1.2.0. OpenRegister stores `properties` as a raw
  json column, so the non-standard `order` key survives import and reaches the frontend as
  `prop.order`. Sequence now lives with the schema that owns the properties.
- **`src/manifest.json`**, page `Jobs` — `includeFields` widened from 6 to 13 entries;
  `fieldOverrides` reduced to what a schema cannot or must not express: the `jobClass`
  dropdown list, `description`'s textarea widget, `group: "flags"` on the four scheduling
  booleans, and `errorRetention`'s form-only default. No `order` here.
- **`src/modals/v2/jobDraft.js`** (new) — `groupFieldRuns`, `dateValueFromStored`,
  `formatDateValue`, `coerceNumber`, `readSynchronizationId`, `writeSynchronizationId`.
- **`src/modals/v2/JobFormFields.vue`** — rewritten as a group-aware generic renderer. Net
  deletion of hardcoded field knowledge: `JOB_CLASS_OPTIONS`, the `field.key === 'jobClass'`
  branch, `jobClassOptions`, `selectedJobClassOption` and `onJobClassPick` are gone,
  replaced by a `select` branch driven by `field.enum`. Adds the missing
  `NcDateTimePickerNative` branch (fixing 3), an identity-stable enum option cache, and
  `CnFieldHelper` in place of hand-rolled helper spans. The Synchronization picker keeps its
  behaviour but now anchors under the class picker instead of after the whole field list —
  at 12 fields, "after the loop" put it below Error Retention.
- **`tests/vitest/jobDraft.spec.js`** (new, 32 assertions) — the helpers, plus a
  manifest ↔ `lib/Action/` ↔ SFC consistency block that fails if an Action class is added
  without reaching the picker (defect 4), if an offered class has no translated label, if an
  override names a field that is not included, if two properties share an `order`, or if the
  four flags stop being contiguous.
- **l10n** — 4 new `t()` literals (the class labels) extracted into `l10n/en.json` and
  translated into `en.js` + all 36 locale bundles in the same change.

Field order after this change: name, description, jobClass (+ conditional Synchronization),
interval, the four flags two-up, scheduleAfter, userId, logRetention, errorRetention.

## Non-goals

- **An `enum` on the `job` schema's `jobClass`.** An enum is enforced on save, which would
  make the three jobs seeded with the non-existent `OCA\Integriq\Cron\Example*Job`
  classes unsaveable and would forbid Action classes registered by third-party apps —
  `jobClass` is resolved through the DI container at run time, so any resolvable class is
  legal. The list stays presentational, in the manifest, and the form displays off-list
  values rather than blanking them.
- **A schema `default` for `errorRetention`.** The per-job value is seconds → ms and the
  absent-value fallback is `$this->errorRetention = 2592000000` (30 days), so a schema
  default of 86400 would silently cut every API-, MCP- and seed-created job from 30-day to
  1-day error-log retention. It is a form prefill only. (`logRetention: 3600` *is* in the
  schema: it equals the app fallback `successRetention = 3600000` ms exactly, so it changes
  nothing at the data layer.)
- **`executionTime` on the form.** `JobService` computes it per run and writes it onto the
  *job log*; nothing reads or writes the job object's own copy. The legacy modal offered it
  as an editable max-runtime in seconds, which silently did nothing. Left off rather than
  resurrected as a field that cannot have an effect.
- **A real Nextcloud user picker for `userId`.** `userPicker` is derived from the schema prop
  by `isUserProp()` before overrides merge, so it needs `format: user` /
  `referenceType: nextcloud-user` on the schema *and* a new widget branch. Stays plain text,
  as it was in the legacy modal.
- **A Job detail page.** Out of scope; the dialog is sufficient for the 12 fields.
- **Library changes.** Three were identified and deliberately not taken: `fieldsFromSchema`
  drops `type: object` properties before overrides apply (testing `prop.widget`, not
  `overrides[key].widget`), which is why `arguments` never renders and both Jobs and Rules
  carry standalone fallback blocks; `CnIndexPage` never forwards `size` to `CnFormDialog`,
  capping every `form-fields` slot at NcDialog `normal`; and `dateValueFor`'s regex silently
  drops seconds on every `date-time` field in the fleet.
- **Making `src/modals/v2/` lint.** `eslint.config.js`'s `!src/modals/v2/**` negation never
  fires because `eslint src` prunes `src/modals` first. Tracked in the rule-editor change's
  follow-ups; the vitest suite is the compensating cover here.
- **The three seeded jobs pointing at non-existent `Example*Job` classes.** This change
  tolerates them (off-list values still display); fixing the seed data is separate.
