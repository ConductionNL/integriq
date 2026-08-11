---
kind: code
depends_on: []
---

## Why

The Consumers index create/edit dialog rendered **3 of the 8 authorable consumer
properties** — authorizationType, description, name — in alphabetical order. Neither the
field set nor the ordering was chosen:

1. **`pages[Consumers].config.includeFields`** whitelisted exactly those three, so
   `domains`, `ips`, `authorizationConfiguration`, `rateLimit` and `quota` were unreachable.
   `ConsumersController` is a constructor-only placeholder and there is no `lib/Db/Consumer`,
   so the dialog is the only consumer editor that exists — those five properties could not be
   set from any UI at all.
2. **No `order` was declared anywhere** for the `consumer` schema, so `fieldsFromSchema()`
   fell through to its last resort, `keyA.localeCompare(keyB)`. The alphabetical sequence was
   the absence of a decision, not a decision.

Two of the five hidden properties are load-bearing rather than cosmetic:

3. **`domains`/`ips` are the source allowlist** enforced by `ConsumerScopeService::isAllowed()`
   (`REQ-CON-SCOPE-001`). An operator could not author the control at all — not through the
   UI, and not through an app endpoint either, since every consumer write goes straight to
   OpenRegister's generic object API.
4. **`rateLimit`/`quota` were already required on this form.** `REQ-CON-RL-005` says the
   Consumer editor "MUST let an operator configure a consumer's `rateLimit` … and `quota`",
   and `changes/archive/2026-07-07-consumer-rate-limiting/tasks.md` ticks it — against a Vue
   view the OpenRegister cutover deleted. `grep -rn 'requestsPerWindow' src/` hit only
   `ApiProducts/ApiProductDetail.vue`. The requirement has been false since the cutover.

A fifth defect surfaced while restoring the field set, and is the reason this change is not
purely declarative:

5. **The generic dialog cannot express "no allowlist" on create.** `domains`/`ips` are bare
   `type: array`, so `resolveWidget()` maps them to the `tags` widget, and
   `CnFormDialog.initFormData()` seeds every `tags` field to `[]` on create.
   `buildSubmitPayload()` spreads formData verbatim and `useObjectStore.saveObject()`
   `JSON.stringify`s it, so a create submits `domains: []` and `ips: []`.
   `ConsumerScopeService::isAllowed()` gates on `is_array()` — `[]` is *an allowlist that
   admits nobody*, not *no allowlist* — so **every consumer authored through the restored
   dialog would have been born rejecting all inbound traffic with HTTP 403**, silently at save
   time and unattributably later. Nothing downstream rescues it: OpenRegister does filter
   empty non-required arrays, but only out of a validation COPY
   (`$validationData = $sanitised` in `SaveObjects.php`), so the persisted row keeps `[]`.

   A `form-fields` slot could not have fixed this — `initFormData()` and
   `buildSubmitPayload()` both live in `CnFormDialog`, above the slot. Owning the payload
   requires replacing the dialog.

## What Changes

**No PHP logic changes.** One new register fragment, one manifest block, one new helper
module, one new SFC, one new test suite.

- **`lib/Settings/register.d/consumer-form-fields.json`** (new, ADR-037) — declares `order`
  for the 8 authorable properties and `widget: "json"` for the three object-typed ones. Bumps
  the `consumer` schema 1.1.0 → 1.2.0. This serves the schema-READING surfaces — the
  ConsumerDetail "Access policy" data widget and the detail grid — not the editor, which is
  bespoke. The widget hints are not optional there: `fieldsFromSchema` drops `type: object`
  properties outright unless they carry one, and it tests `prop.widget` (the schema's) before
  overrides merge, so a manifest override could never reach them.

- **`src/manifest.json`**, page `Consumers` — `includeFields` **removed** (dead once
  `CnFormDialog` no longer mounts, and it would read as if it still governed the form);
  `slots["form-dialog"] = "ConsumerEditorModal"` added; a `_note` records why the whole
  dialog is replaced. Page `ConsumerDetail` — the `con-data` widget gains
  `content.exclude: ["authorizationConfiguration"]`, because the new widget hints would
  otherwise put a permanently-empty row on the panel for a field OpenRegister strips from
  every response.

- **`src/modals/v2/consumerDraft.js`** (new) — `AUTHORIZATION_TYPES`,
  `CREDENTIAL_AUTHORIZATION_TYPES`, `QUOTA_PERIODS`, `emptyConsumerDraft`,
  `consumerDraftFromItem`, `normaliseList`, `positiveIntOrNull`, `buildRateLimit`,
  `buildQuota`, `buildConsumerPayload`. The last one carries the three omission rules that
  are the point of the change (defect 5 above, plus write-only preservation and explicit-null
  limit clearing).

- **`src/modals/v2/ConsumerEditorModal.vue`** (new) — name, description, the two allowlists as
  chip inputs, an authorization-type select, a conditional JSON credential editor with a
  Clear control, and the rate-limit/quota block as four typed scalars. Registered in
  `src/registry.js`.

- **`tests/vitest/consumerDraft.spec.js`** (new, 44 assertions) — the helpers, the three
  omission rules asserted individually, and a cross-file consistency block: every authored
  property has a unique numeric `order`; every `type: object` property has a schema `widget`
  (the assertion whose absence left the Jobs page's `arguments` override inert); the fragment
  does not clobber `authorizationConfiguration.writeOnly` and still sorts after the fragment
  that sets it; `QUOTA_PERIODS` equals the schema enum; the page carries no dead
  `includeFields`/`fieldOverrides`; the registry exports the slot name; every offered option
  has a `t()` label.

- **`tests/vitest/editorModalSlotContract.spec.js`** — `ConsumerEditorModal` added to
  `MODALS`, so its `canSave` is pinned against both slot scopes. `confirm` was verified
  present in the installed `dist/esm` build before relying on it; without that binding this
  modal would have shipped the openconnector#1150 defect.

- **l10n** — 38 new `t()` literals extracted into `l10n/en.json`, and added to `l10n/en.js`,
  `l10n/nl.js` and `l10n/nl.json`. The chip-input examples (`example.com, *.example.org`,
  `203.0.113.4, 10.0.0.0/8`) and the credential templates are module constants, deliberately
  NOT run through `t()`: they are syntax, not prose, and the prose that explains them is
  translated in the helper line beneath each input.

Field order after this change: name, description, domains, ips, authorizationType,
authorizationConfiguration, rateLimit, quota.

## Non-goals

- **An `enum` on the `consumer` schema's `authorizationType`.** An enum is enforced on save,
  which would make the three seeded consumers (`internal-dashboard`, `partner-portal`,
  `mobile-app`) unsaveable and would reject casing variants that legitimately work —
  `AuthorizationService::resolveConsumerByApiKey()` compares
  `strtolower($data['authorizationType']) !== 'apikey'`, so stored data may hold `apiKey` or
  `apikey`. The list stays presentational in `consumerDraft.js`, and the picker displays an
  off-list stored value rather than blanking it.

- **A schema `default` for `domains`/`ips`.** A schema default reaches every API-, MCP- and
  seed-created consumer, and for these two properties `absent` (unrestricted) and
  `[]` (rejects everything) mean opposite things. Materialising `domains: []` on every
  consumer that omitted it would fail every inbound request closed — the same defect as
  number 5, moved server-side.

- **`bearer` enforcement.** Offered for parity with the list the deleted `EditConsumer.vue`
  had, but nothing reads it: the engine's only typed paths are `apikey`
  (`resolveConsumerByApiKey`) and the JWT issuer lookup (`findIssuer`, which matches on
  `name`, not on type). A consumer set to `bearer` authenticates like one set to `none`.
  Removing it would be a data migration, not a UI edit.

- **Authoring `domains: []` from the UI.** The `[]` state stays reachable through the API for
  anyone who genuinely wants to deny everything, and `REQ-CON-SCOPE-001` still enforces it.
  It is simply not something a form should be able to produce by accident — clearing the last
  chip means "stop restricting", which is how clearing an allowlist reads to an operator.

- **Typed sub-fields for `authorizationConfiguration`.** `apiKey` and
  `publicKey`/`algorithm` are nested keys and the shape is provider-specific; the editor
  offers a JSON object editor with a per-type "Insert example" instead. Typed leaves for the
  two known shapes would leave every other provider with no way to author its config.

- **Pre-filling the credential on edit.** It is `writeOnly: true`; OpenRegister strips it from
  every response, admin included. The editor opening blank is the security boundary working,
  not a bug, and the field's helper text says so.

- **Library changes.** Two identified, not taken. `fieldsFromSchema` tests `prop.widget`
  rather than `overrides[key].widget` when filtering `type: object` properties, which is why
  the widget hints must live in the schema (and why Jobs' `arguments` override is inert). And
  `CnFormDialog.initFormData()` seeding `[]` for `tags` fields is arguably wrong in general —
  "the user expressed nothing" is not "the user expressed an empty list" — but it is a shared
  library used by five apps and the app-local fix is contained.

- **A Consumer detail-page editor.** The detail page stays read-only; the dialog is
  sufficient for eight fields.

- **Translating the other 34 locale bundles.** `test:l10n` (en.json) is the CI gate;
  `test:l10n:parity` is deliberately not wired because the tree already carries a 337+ string
  backlog. English and Dutch are done here; the rest join that backlog.

## Known nuance

Switching a consumer's type to one that carries no credential sends
`authorizationConfiguration: null`, retiring the stored key. This is deliberate and differs
from what the generic dialog would have done: its conditional-visibility path *deletes* the
form key, and OpenRegister's preserve rule then restores the stored value — leaving an
unreachable credential at rest. Retiring it is the safer reading, and the operator sees the
credential editor disappear when they make the change.
