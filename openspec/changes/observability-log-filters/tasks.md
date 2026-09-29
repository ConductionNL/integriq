# Tasks: observability-log-filters

Kind: config. Matrix row `integriq:obs-filter-logs`. The rendering is a
nextcloud-vue change; these tasks are integriq's.

### Task 1: Scope source and endpoint logs by direction
- **spec_ref**: openspec/changes/observability-log-filters/specs/app-shell-and-logs-ui/spec.md#requirement-source-and-endpoint-logs-show-only-their-own-direction-req-logf-003
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN inbound and outbound calls WHEN the source logs page opens THEN only outbound calls are listed
  - GIVEN inbound and outbound calls WHEN the endpoint logs page opens THEN only inbound calls are listed
- [x] Implement the endpoint half: `EndpointLogs` has `filter: {direction: inbound}`; every inbound writer (`EndpointService`) already sets it.
- [x] Fix found while building: `CallService` never wrote `direction` on a source call (only `CallRecorder` and the inbound path did), so scoping `SourceLogs` to outbound would have emptied it. Both write paths (`buildAndPersistCallLog`, `saveEarlyErrorLog`) now write `direction: outbound` (tests/Unit/Service/CallServiceTest.php `testACallToASourceIsLoggedAsOutbound`, `testARefusedCallIsLoggedAsOutbound`).
- [ ] Implement the source half: `SourceLogs` gets `filter: {direction: outbound}` once the failed calls written before the fix above have expired (call_log keeps errors 30 days). Until then the page would hide them. See design D4.
- [x] Test (tests/vitest/logFilterControls.spec.js 'scopes the endpoint logs to inbound calls'; Playwright `tests/e2e/observability-log-filters.spec.ts` not written: it needs the nextcloud-vue rendering)

### Task 2: Declare filter controls on the six log pages
- **spec_ref**: openspec/changes/observability-log-filters/specs/app-shell-and-logs-ui/spec.md#requirement-every-log-page-declares-its-filter-controls-req-logf-001
- **files**: `src/manifest.json`, `tests/validate-manifest.js`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN it is validated THEN each of the six pages has status (where the schema has one), subject and date range controls, and the three call log pages have direction
  - GIVEN a control key WHEN compared with `src/handlers/logTargets.js` THEN the subject keys match the row action query keys
- [x] Implement (`filterControls` on the six pages in `src/manifest.json`; status on `JobLogs` is `level` with the values JobService writes: SUCCESS, INFO, WARNING, ERROR; `CloudEventLogs` has no subject picker because `call_log` has no event field, the same reason its row action has no query key)
- [x] Test (tests/vitest/logFilterControls.spec.js: every control names a property of the merged register schema, each page has a date range on its sort column, the call log pages have direction and the three status code ranges, trace status and entry point equal the schema enums, and every row action's query key has a reference picker on its page. It is a vitest rule rather than a rule in `tests/validate-manifest.js` because `src/handlers/logTargets.js` is an ES module.)

### Task 3: Ask nextcloud-vue for the rendering and take the release
- **spec_ref**: openspec/changes/observability-log-filters/specs/app-shell-and-logs-ui/spec.md#requirement-a-filtered-view-is-a-link-req-logf-002
- **files**: an issue on ConductionNL/nextcloud-vue, `package-lock.json`
- **acceptance_criteria**:
  - GIVEN the issue WHEN opened THEN it names the `filterControls` shape, the three control types and the address-bar behaviour
  - GIVEN the nextcloud-vue release WHEN integriq updates its lockfile within `^2.37.0` THEN the controls render on all six pages
- [x] Ask: ConductionNL/nextcloud-vue#1291 names the `filterControls` shape, the three control types and the address-bar behaviour.
- [ ] Take the release: move `package-lock.json` inside `^2.37.0` once #1291 ships.
- [ ] Test (Playwright `tests/e2e/observability-log-filters.spec.ts` covering a filtered view reopened from its address)

## Verification

- `openspec validate observability-log-filters --type change --strict`
- `npm run lint` and `node tests/validate-manifest.js`
- `tests/e2e/observability-log-filters.spec.ts` green against a local instance with the nextcloud-vue release
