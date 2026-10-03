# Tasks: connectors-job-board-multiposting

Kind: code. Size M. Half for humaniq `hiring-multiposting` (row humaniq `hir-multiposting`).

## Implementation tasks

### Task 1: Per-item source and mapping references
- **spec_ref**: `openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-a-flow-step-can-pick-its-source-and-mapping-per-item-req-jbc-001`
- **files**: `lib/Flow/SourceCallNode.php`, `lib/Flow/ApplyMappingNode.php`, their node config schemas, `tests/Unit/Flow/`
- **acceptance_criteria**:
  - GIVEN items for `werk-nl` and `linkedin` WHEN the step uses `jobboard-{{ json.channel }}` THEN each item is sent through its own source
  - GIVEN an item whose board has no source WHEN the step runs with `onError: continue` THEN that item is an error item naming the source and the others succeed
  - GIVEN a plain reference WHEN an existing flow runs THEN its output is unchanged
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 2: Board templates and mappings
- **spec_ref**: `openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002`
- **files**: `lib/Settings/register.d/jobboard-werk-nl-source.json`, `jobboard-linkedin-source.json`, `jobboard-indeed-source.json`, mappings in `lib/Settings/integriq_seed_data.json`
- **acceptance_criteria**:
  - GIVEN humaniq's seeded vacancy WHEN it is mapped for each board THEN the output matches that board's recorded example
- [ ] Implement
- [ ] Test (PHPUnit per mapping)

### Task 3: Vacancy feed endpoint
- **spec_ref**: `openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002`
- **files**: endpoint configuration in the seed data, `lib/Service/EndpointService.php` (only if the XML rendering needs a hook)
- **acceptance_criteria**:
  - GIVEN two published vacancies of which one lists `indeed` WHEN the Indeed feed is requested THEN it lists that one only, without applicant data
- [ ] Implement
- [ ] Test (Newman)

### Task 4: Docs and strings
- **spec_ref**: `openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002`
- **files**: `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the docs WHEN an administrator connects a board THEN the partner programme and the feed URL registration are named
- [ ] Implement
- [ ] Test (docs build)

## Verification
- [ ] `openspec validate connectors-job-board-multiposting --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit and Newman exit codes read
