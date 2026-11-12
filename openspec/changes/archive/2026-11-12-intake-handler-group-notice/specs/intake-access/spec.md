## ADDED Requirements

### Requirement: The connection settings say when nobody can read the intake records (REQ-IAC-001)

The DSO connection settings and the Open Formulieren connection settings MUST show a warning while their handler group (`dso-behandelaars`, `openformulieren-behandelaars`) has no members. The warning MUST name the group and say where to add handlers. A group that does not exist counts as empty. The warning MUST disappear once the group has a member. The settings endpoints MUST return the group id and whether it is empty.

#### Scenario: An empty handler group is announced

- GIVEN an administrator opens the Open Formulieren connection settings
- AND `openformulieren-behandelaars` has no members
- THEN the section shows a warning that names `openformulieren-behandelaars`
- AND the settings endpoint returns `handlerGroup: {id: "openformulieren-behandelaars", empty: true}`
- @e2e exclude settings payload: covered by PHPUnit and the live settings GET; the card is a plain v-if on that field

#### Scenario: The warning goes away once a handler joins

- GIVEN `dso-behandelaars` has one member
- WHEN an administrator opens the DSO connection settings
- THEN the section shows no handler-group warning
- AND the settings endpoint returns `empty: false`
- @e2e exclude settings payload: covered by PHPUnit and the live settings GET
