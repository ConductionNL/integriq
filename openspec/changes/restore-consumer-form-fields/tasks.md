# Tasks — restore-consumer-form-fields

## 1. Schema fragment — DONE

`lib/Settings/register.d/consumer-form-fields.json`: `order` for the 8 authorable properties
(name 10 → quota 80) and `widget: "json"` for the three object-typed ones
(`authorizationConfiguration`, `rateLimit`, `quota`). `consumer` schema version 1.1.0 → 1.2.0
so OpenRegister's version-gated `importFromApp` takes the fast path rather than relying on its
content-differs fallback.

This serves the schema-READING surfaces — the ConsumerDetail "Access policy" data widget and
the detail grid — not the editor, which is bespoke. The widget hints are load-bearing there:
`fieldsFromSchema` drops `type: object` properties that carry no widget, and it tests
`prop.widget` before per-field overrides merge, so the hint cannot be supplied from the
manifest. That asymmetry is what left the Jobs page's `fieldOverrides.arguments` inert.

Deliberately absent: an `enum` on `authorizationType` and any `default` — both are enforced or
honoured at the data layer, not just on the form. See the proposal's Non-goals.

Merge order matters and is asserted in the test suite: this file sorts after
`99-consumer-secrets-writeonly.json` (glob+sort puts digits before letters) and
`deepMergeConfig` recurses object+object, so `widget` lands beside the existing
`writeOnly: true` rather than replacing the property.

## 2. Manifest — DONE

`src/manifest.json`, page `Consumers`: `includeFields` **removed** and
`slots["form-dialog"] = "ConsumerEditorModal"` added. The removal is the point — once the slot
is wired `CnFormDialog` never mounts, so `includeFields`/`fieldOverrides` would be dead config
that reads as if it governed the form. The three existing `form-dialog` pages (Mappings, Rules,
Synchronizations) carry neither; a test pins that convention. A `_note` records why the whole
dialog is replaced rather than its content.

Page `ConsumerDetail`: the `con-data` widget gains
`content.exclude: ["authorizationConfiguration"]`. Task 1's widget hints are what put
`rateLimit`/`quota` on that panel — the right place, since its stated job is confirming the
access policy — but `authorizationConfiguration` is stripped from every response, so it could
only ever render as a permanently empty row.

## 3. Helper module — DONE

`src/modals/v2/consumerDraft.js`: `AUTHORIZATION_TYPES`, `CREDENTIAL_AUTHORIZATION_TYPES`,
`QUOTA_PERIODS`, `emptyConsumerDraft()`, `consumerDraftFromItem()`, `normaliseList()`,
`positiveIntOrNull()`, `buildRateLimit()`, `buildQuota()`, `buildConsumerPayload()`. No Vue,
axios or DOM access, so it is directly unit-testable.

`buildConsumerPayload()` is the reason this change is not declarative. Three omission rules,
all turning on OpenRegister's PUT contract (an omitted schema property is nulled by
`fillMissingSchemaPropertiesWithNull()`, except a write-only one, which
`collectOmittedWriteOnlyPaths()` restores from storage instead):

1. **An empty `domains`/`ips` is omitted, never sent as `[]`.** `[]` would make
   `isAllowed()` 403 every inbound request; absent nulls the property, which reads as
   unrestricted.
2. **A blank `authorizationConfiguration` is omitted.** The field always opens blank
   (write-only), so sending its value would wipe the stored credential on every unrelated
   edit — openconnector#245's shape. An explicit `null` clears it, from the Clear control.
3. **An unset `rateLimit`/`quota` is sent as an explicit `null`.** No ambiguity to protect
   here — absent and null both mean unlimited — and null is what actually removes a
   previously configured limiter on PUT.

`normaliseList()` also accepts a comma-joined string, because rows written by the pre-cutover
`EditConsumer.vue` persisted a textarea verbatim. Those values never matched anything
(`isAllowed()` reads arrays only), so opening such a consumer and saving it repairs the row.

## 4. The component — DONE

`src/modals/v2/ConsumerEditorModal.vue`, registered in `src/registry.js` and mounted through
`CnIndexPage`'s `form-dialog` slot. Four sections: identity (name, description), allowed
sources (two chip inputs plus a note that states which of the three allowlist states is
active), authentication (type select + conditional JSON credential editor with Insert example
and Clear controls), limits (four typed scalars).

`rateLimit`/`quota` are edited as typed number/select leaves rather than as JSON, which is what
`REQ-CON-RL-005` actually asks for. `fieldsFromSchema` walks top-level properties only, so no
nested leaf is reachable declaratively at any price.

`size="normal"` — unlike the three existing `form-dialog` modals this one does not need extra
width; it replaces the dialog for the payload, not the layout.

## 5. Strings — DONE

38 new source strings, all `t()` literals in the SFC so `tests/l10n/check-l10n.js` can see
them. Extracted into `l10n/en.json` via `check-l10n.js --write`, then added to `l10n/en.js`,
`l10n/nl.js` and `l10n/nl.json` (Dutch reuses the tree's established
*credential → referentie* term). The other 34 bundles join the existing translation backlog —
`test:l10n:parity` is deliberately not a CI gate for that reason.

The chip-input examples and the credential templates are module constants, NOT `t()` literals:
they are syntax rather than prose, and a localised `example.com` would be a worse example, not
a translated one. The prose explaining the syntax is translated, in the helper line under each
input.

Field labels for the detail page still come from the schema's `title`/`description` through the
library's `cnTranslate` and need no new strings.

## 6. Tests — DONE

`tests/vitest/consumerDraft.spec.js` (44 assertions). Helpers: list normalisation including
the legacy comma-string repair and the new-array guarantee; `positiveIntOrNull` truncating
rather than rounding and rejecting 0 against the schema's `minimum: 1`; half-filled limit pairs
collapsing to null; draft seeding that flattens both nested blocks, keeps an off-list
authorization type verbatim, and never reads the write-only credential.

Each of the three omission rules is asserted on its own, including the two failure modes they
prevent: `[]` on create, and a wiped credential on an unrelated edit.

Consistency block, pinning the configuration files against each other: the page wires the slot
and `registry.js` exports that exact name; the page carries no dead
`includeFields`/`fieldOverrides`; every authored property exists on the schema with a unique
numeric `order`; **every `type: object` property has a schema `widget`**; the fragment bumps the
version, does not declare `writeOnly` itself, and still sorts after the fragment that does;
`QUOTA_PERIODS` equals the schema's `quota.period` enum; no `authorizationType` enum exists on
either the base schema or the fragment; the types the app itself writes (`apiKey`) and the
engine special-cases (`none`) are both offered; every offered option has a `t()` label; the
detail widget excludes exactly one field.

`tests/vitest/editorModalSlotContract.spec.js` — `ConsumerEditorModal` added to `MODALS`
(5 further assertions), so its `canSave` is pinned against both the shipped and the
post-#614 slot scopes. `confirm: $options.onFormConfirm` was verified present in the installed
`dist/esm` CnIndexPage build before depending on it; the app builds against `dist`, not the
sibling's `src`, so checking the source would have proved nothing.

Full suite: 281 passing (was 276). `check:specs` and `test:l10n` pass; `npm run dev` builds
with one pre-existing unrelated warning (`sax` → `stream` polyfill, reached via
`@nextcloud/dialogs`).

## 7. Follow-ups (not this change)

- **`CnFormDialog.initFormData()` seeds `[]` for `tags` fields on create.** Defensible as a
  default, but wrong wherever absent and empty differ — "the user expressed nothing" is not
  "the user expressed an empty list". Leaving the key absent until the field is touched would
  keep both states expressible and would have made this page's restoration purely
  declarative. Shared library, five consumers; not taken here.
- **`fieldOverrides[key].widget` cannot rescue an `object`-typed property**, because
  `fieldsFromSchema`'s filter tests `prop.widget` before overrides merge. Already recorded as a
  follow-up by `restore-job-form-fields`; this change is the second page to work around it.
- **`consumer.authorizationType` accepts values nothing reads** (`bearer`, and `basic`/`oauth2`
  at the consumer level). Reconciling the offered vocabulary with the two paths the engine
  actually implements is a data question, not a UI one.
- **`ConsumersController` is a constructor-only placeholder.** Every consumer read and write
  goes through OpenRegister's generic object API, which is why the `99-consumer-lockdown`
  fragment has to carry the RBAC. Fine as it stands; worth noting for anyone looking for an
  app endpoint to hook.
- **Make `src/modals/v2/` lint** — `eslint src` prunes `src/modals` before
  `eslint.config.js`'s `!src/modals/v2/**` negation can fire, so the new SFC and helper module
  are unlinted. Shared with the rule-editor and job-form-fields follow-ups; the vitest suite is
  the compensating cover.
