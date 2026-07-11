# rule-editor-ui — Accessibility + i18n Delta

**Spec refs**: `rule-editor-ui`, ADR-004 (frontend, name/role/value), ADR-010 (nl-design,
WCAG AA)

## ADDED Requirements

### Requirement: EditRule modal footer actions are translated

The `EditRule.vue` modal's footer Cancel and Save actions MUST render their labels via
`t('openconnector', ...)`, not as hardcoded English text nodes, so non-English locales
display the correct translated label like every other modal in the app.

#### Scenario: Non-English locale shows translated Cancel/Save

- GIVEN a user's Nextcloud locale is set to a non-English language with a translated
  `l10n/<locale>.json`
- WHEN the user opens the rule editor modal (`EditRule.vue`)
- THEN the footer's Cancel and Save buttons MUST display the locale's translated text,
  not literal English `Cancel`/`Save`

### Requirement: Icon-only remove buttons in EditRule carry an accessible name

Icon-only "remove row" `NcButton`s MUST carry an `aria-label` so a screen-reader user
knows what the button does. This applies to the `extend_input` and
`extend_external_input` action-configuration lists in `EditRule.vue`, matching the
pattern already used by every other icon-only button in this app (e.g.
`MappingRulesEditor.vue`'s drag/edit/delete row actions).

#### Scenario: Screen reader announces the remove-property button's purpose

- GIVEN the rule editor modal renders an `extend_input` or `extend_external_input`
  action form with one or more property rows
- WHEN a screen reader focuses the row's remove (trash-can icon) button
- THEN it MUST announce an accessible name (e.g. "Remove property"), not merely
  "button"
