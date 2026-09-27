# Tasks: observability-log-filters

Kind: config. Matrix row `integriq:obs-filter-logs`. The rendering is a
nextcloud-vue change; these tasks are integriq's.

### Task 1: Scope source and endpoint logs by direction
- **spec_ref**: openspec/changes/observability-log-filters/specs/app-shell-and-logs-ui/spec.md#requirement-source-and-endpoint-logs-show-only-their-own-direction-req-logf-003
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN inbound and outbound calls WHEN the source logs page opens THEN only outbound calls are listed
  - GIVEN inbound and outbound calls WHEN the endpoint logs page opens THEN only inbound calls are listed
- [ ] Implement
- [ ] Test (`node tests/validate-manifest.js`; Playwright `tests/e2e/observability-log-filters.spec.ts`)

### Task 2: Declare filter controls on the six log pages
- **spec_ref**: openspec/changes/observability-log-filters/specs/app-shell-and-logs-ui/spec.md#requirement-every-log-page-declares-its-filter-controls-req-logf-001
- **files**: `src/manifest.json`, `tests/validate-manifest.js`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN it is validated THEN each of the six pages has status (where the schema has one), subject and date range controls, and the three call log pages have direction
  - GIVEN a control key WHEN compared with `src/handlers/logTargets.js` THEN the subject keys match the row action query keys
- [ ] Implement
- [ ] Test (a manifest validation rule that fails when a log page lacks controls or uses a key no row action sends)

### Task 3: Ask nextcloud-vue for the rendering and take the release
- **spec_ref**: openspec/changes/observability-log-filters/specs/app-shell-and-logs-ui/spec.md#requirement-a-filtered-view-is-a-link-req-logf-002
- **files**: an issue on ConductionNL/nextcloud-vue, `package-lock.json`
- **acceptance_criteria**:
  - GIVEN the issue WHEN opened THEN it names the `filterControls` shape, the three control types and the address-bar behaviour
  - GIVEN the nextcloud-vue release WHEN integriq updates its lockfile within `^2.37.0` THEN the controls render on all six pages
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/observability-log-filters.spec.ts` covering a filtered view reopened from its address)

## Verification

- `openspec validate observability-log-filters --type change --strict`
- `npm run lint` and `node tests/validate-manifest.js`
- `tests/e2e/observability-log-filters.spec.ts` green against a local instance with the nextcloud-vue release
