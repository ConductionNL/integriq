# Tasks: {{change_name}}

## Implementation Tasks

<!-- Each task should be small enough for one Ralph Wiggum iteration.
     Include spec_ref and files for the JSON export.
     Order by dependency — foundations first. -->

### Task 1: {{task_title}}
- **spec_ref**: `openspec/specs/{{capability}}/spec.md#requirement-{{name}}`
- **files**: `lib/Controller/...`, `lib/Service/...`
- **acceptance_criteria**:
  - GIVEN ... WHEN ... THEN ...
- [ ] Implement
- [ ] Test

### Task 2: {{task_title}}
- **spec_ref**: `openspec/specs/{{capability}}/spec.md#requirement-{{name}}`
- **files**: `lib/...`
- **acceptance_criteria**:
  - GIVEN ... WHEN ... THEN ...
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

<!-- Required for all changes. Mark N/A with justification if not applicable. -->

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints
- [ ] Browser tests (Playwright MCP) for UI changes
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

<!-- See `.claude/docs/writing-docs.md` for documentation principles. Required for user-facing features. Mark N/A with justification if not applicable. -->

- [ ] Feature documentation updated in `docs/`
- [ ] Screenshot captured and committed to `docs/images/`

## i18n (company-wide hydra ADR-007)

<!-- Required when adding user-facing strings. Mark N/A if no new strings. -->

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added
