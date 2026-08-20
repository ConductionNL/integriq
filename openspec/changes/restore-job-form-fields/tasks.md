# Tasks — restore-job-form-fields

## 1. Schema fragment — DONE

`lib/Settings/register.d/job-form-fields.json`: `order` for the 12 authorable properties
(name 10 → errorRetention 90) and `default` for `interval` (3600) and `logRetention`
(3600). `job` schema version 1.1.0 → 1.2.0 so OpenRegister's version-gated `importFromApp`
takes the fast path rather than relying on its content-differs fallback.

Deliberately absent: an `enum` on `jobClass` and a `default` on `errorRetention` — both are
enforced/honoured at the data layer, not just on the form. See the proposal's Non-goals.

## 2. Manifest — DONE

`src/manifest.json`, page `Jobs`: `includeFields` widened from 6 to 13 entries.
`fieldOverrides` now carries only the non-schema-expressible bits — the `jobClass` enum,
`description: widget textarea`, `group: "flags"` on the four scheduling booleans, and
`errorRetention: default 86400`. `arguments: widget json` retained (still inert:
`fieldsFromSchema` drops bare `type: object` props before overrides merge). No `order` in
the manifest; the schema owns sequence. A `_fieldOverridesNote` records the split so the
next reader does not "tidy" the order back into the manifest.

## 3. Helper module — DONE

`src/modals/v2/jobDraft.js`: `SYNCHRONIZATION_ACTION_CLASS`, `SYNCHRONIZATION_ID_KEY`,
`groupFieldRuns()`, `dateValueFromStored()`, `formatDateValue()`, `coerceNumber()`,
`readSynchronizationId()`, `writeSynchronizationId()`. No Vue, axios or DOM access, so it is
directly unit-testable.

The two date helpers are byte-for-byte behavioural copies of CnFormDialog's `dateValueFor`
/ `formatDateValue`, including the seconds truncation. `normalizePersistedDates()` has
already applied that same transform to formData before this slot renders, so diverging
would make an untouched field read as dirty on open.

## 4. The component — DONE

`src/modals/v2/JobFormFields.vue` rewritten: `v-for` over `fieldRuns`, one widget switch,
`display: contents` on ungrouped runs so a single switch serves both branches, and a
responsive `repeat(2, minmax(0, 1fr))` grid for grouped runs (collapsing at 768px).

Deleted: `JOB_CLASS_OPTIONS`, the `field.key === 'jobClass'` branch, `jobClassOptions`,
`selectedJobClassOption`, `onJobClassPick`. Added: the `select` branch driven by
`field.enum`, an identity-stable enum option cache held off `data` (Vue's reactive proxy
would break NcSelect's by-reference model match), the `NcDateTimePickerNative` branch, and
`CnFieldHelper` in place of hand-rolled helper spans. `t` is now imported from
`@nextcloud/l10n` rather than relying on the global, because the label map lives at module
scope where `app.config.globalProperties.t` does not reach.

## 5. Strings — DONE

4 new source strings, all `t()` literals in the SFC so `tests/l10n/check-l10n.js` can see
them: "Run a synchronization", "Run a flow", "Dispatch an event", "Ping a source". The
labels deliberately do NOT live in the manifest as `enumLabels` — manifest values are not
`t()` literals and would be permanently untranslatable. Extracted into `l10n/en.json` via
`check-l10n.js --write`, then added to `en.js` and all 36 locale bundles using each
bundle's own established terms for Synchronization/Event/Source. *Flow* is kept as the
product noun (no locale had a Flow key to follow).

Field labels and help text need no new strings at all: they come from the schema's
`title`/`description` through the library's `cnTranslate`.

## 6. Tests — DONE

`tests/vitest/jobDraft.spec.js` (32 assertions). Helpers: group coalescing including the
consecutive-only contract and the `group: ''`/`group: 5` guards; the date round trip
computed TZ-independently so it passes in CI's UTC and locally, with the seconds loss
asserted as intentional; `coerceNumber('0') === 0` and the never-NaN guarantee;
`writeSynchronizationId` non-mutation and key removal.

Consistency block, pinning the three configuration files against each other: the manifest's
`jobClass.enum` equals the FQNs derived from `fs.readdirSync('lib/Action')`; every offered
class has a `t()` label in the SFC; `SYNCHRONIZATION_ACTION_CLASS` is inside the offered
set; every override names an included field; every included field (bar `arguments`) has a
numeric schema `order`; orders are unique; the four flags are contiguous by order.

## 7. Follow-ups (not this change)

- **`fieldOverrides[key].widget` cannot rescue an `object`-typed property.**
  `fieldsFromSchema`'s filter tests `prop.widget`, not `overrides[key].widget`, so
  `job.arguments` (and `rule.conditions`) are dropped before the override merges. A one-line
  library fix would let both pages delete their standalone fallback blocks.
- **`CnIndexPage` never forwards `size` to `CnFormDialog`**, capping every `form-fields` slot
  at NcDialog `normal`. A `formDialogSize` prop would let MappingEditorModal,
  RuleEditorModal and SynchronizationEditorModal collapse back from `form-dialog` to slots.
- **`dateValueFor`'s regex drops seconds** on every `date-time` field in the fleet, and
  `normalizePersistedDates` writes the truncation back. Library-wide, matched here on
  purpose.
- **Make `src/modals/v2/` lint** (shared with the rule-editor change's follow-ups).
- **Three seeded jobs point at non-existent `OCA\OpenConnector\Cron\Example*Job` classes**
  and throw at `JobService.php:432` if run. Tolerated here, broken regardless.
- **`job.executionTime` is a dead property** on the job object — written only onto job logs.
  Retiring it, or renaming it `lastExecutionTime` to match its own description, is a schema
  change of its own.
