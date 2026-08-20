# sync-editor-ui — Delta: Nextcloud Form picker and field-mapping helper

## Purpose

Extends the source/target configuration widget (base spec REQ-SYNCUI-002)
with a `nextcloud-form` source kind: a form picker (reusing the Source
selector already present for `api`/`nextcloud-table` sources) and a
read-only field-mapping helper prefilled from the selected form's
questions, calling the discovery endpoints defined in
`nextcloud-forms-connector` REQ-005. The type only appears when the
backend reports the Forms app is enabled (`nextcloud-forms-connector`
REQ-001). Unlike the `nextcloud-table` column-mapping helper
(REQ-SYNCUI-007), this helper is source-only labelling — there is no
`nextcloud-form` target, so there is no per-field write payload to
construct.

## ADDED Requirements

### Requirement: Form picker for the `nextcloud-form` source kind (REQ-SYNCUI-008)

`SyncConfigWidget.vue` SHALL present a `nextcloud-form` option in the
source-kind selector only when the backend's available-types list includes
it (`nextcloud-forms-connector` REQ-001/REQ-005). `nextcloud-form` SHALL
NOT be offered in the target-kind selector under any condition
(`nextcloud-forms-connector` REQ-002 is source-only). When `nextcloud-form`
is selected as the source kind, the widget SHALL require picking a
`Source` (reusing the existing Source selector used for `api` sources) and
then fetching and presenting that Source's accessible forms via
`GET .../synchronizations/forms-bridge/forms`, storing the chosen form's id
into `sourceConfig.formId`.

#### Scenario: nextcloud-form kind is hidden when Forms is unavailable

- **GIVEN** the backend's available source-types response does not include
  `nextcloud-form`
- **WHEN** the source-kind selector renders
- **THEN** `nextcloud-form` is not offered as an option

#### Scenario: nextcloud-form is never offered as a target kind

- **GIVEN** the Forms app is enabled and `nextcloud-form` is present in the
  available source-types response
- **WHEN** the target-kind selector renders
- **THEN** `nextcloud-form` is not offered as an option there, regardless
  of the source-types response

#### Scenario: picking a Source populates the form list

- **GIVEN** the `nextcloud-form` source kind is selected and a `Source` is
  picked
- **WHEN** the widget fetches forms for that Source
- **THEN** the returned forms are presented in a picker, and choosing one
  sets `formId` in `sourceConfig`

### Requirement: Field-mapping helper prefilled from form questions (REQ-SYNCUI-009)

When a form is selected for a `nextcloud-form` source, the widget SHALL
fetch that form's questions
(`GET .../synchronizations/forms-bridge/forms/{formId}/questions`) and
present a read-only field reference list showing each question's `text`,
`name`, and `type`, so the user can see the exact question-text/id
references available to a `Mapping` or an outbound `action.kind: 'mapping'`
configuration before writing mapping expressions by hand (the mapping
picker itself, REQ-SYNCUI-003, is unchanged and already exists). The helper
SHALL visually distinguish `multiple`/`multiple_unique`-type questions
(array-valued answers, `nextcloud-forms-connector` REQ-003) from
single-valued question types.

#### Scenario: field reference list shows question text, id, and type

- **GIVEN** a selected form with a `short`-type question titled
  "Company name" (`id: 7`) and a `multiple`-type question titled
  "Interested in" (`id: 12`)
- **WHEN** the field-mapping helper renders
- **THEN** both questions are listed with their `text`, `id`, and `type`
- **AND** "Interested in" is visually flagged as array-valued

#### Scenario: an ambiguous question text is flagged in the helper, not just at run time

- **GIVEN** a selected form with two questions both titled "Comments"
- **WHEN** the field-mapping helper renders
- **THEN** both "Comments" entries are shown with their distinct `id`s and
  a visible warning that referencing this text by name is ambiguous
  (`nextcloud-forms-connector` REQ-003), steering the user toward
  referencing by id instead
