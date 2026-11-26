# rule-editor-ui — Accessibility + i18n Delta

## ADDED Requirements

### Requirement: The rule editor's save and cancel actions are translated (REQ-RULEUI-009)

The rule editor's Save and Cancel actions MUST render their labels through `t('integriq', ...)`, so a non-English locale shows the translated label. This holds on the rule detail page and in the rule editor modal that replaced the legacy `EditRule.vue`.

#### Scenario: A non-English locale shows translated Save and Cancel

- GIVEN a user whose Nextcloud language is Dutch
- WHEN the user opens a rule in the rule editor
- THEN the Save and Cancel buttons read `Opslaan` and `Annuleren`, not `Save` and `Cancel`

### Requirement: Icon-only remove buttons in the rule editor carry an accessible name (REQ-RULEUI-010)

Each icon-only remove button in the `extend_input` and `extend_external_input` property lists MUST carry a translated `aria-label`, so a screen reader announces what the button does, not merely "button".

#### Scenario: A screen reader announces the remove button's purpose

- GIVEN the rule editor shows an `extend_input` or `extend_external_input` form with property rows
- WHEN a screen reader reaches a row's remove button
- THEN the button's accessible name is `Remove row` (`Rij verwijderen` in Dutch), one per row
