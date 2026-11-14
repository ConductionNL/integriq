# Tasks: adapter-configuration-screens

## Implementation Tasks

### Task 1: Answer the three open questions before building anything
- **spec_ref**: `openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-every-configuration-record-an-administrator-owns-has-a-screen-req-acs-001`
- **files**: `openspec/changes/adapter-configuration-screens/proposal.md`
- **acceptance_criteria**:
  - GIVEN the proposal's Open Questions WHEN each is answered THEN the answer is written into the proposal with its reason, not left in a review thread
  - GIVEN question 3 (where six index pages go) WHEN answered THEN the answer names the nav shape and cites the ADR that constrains it, because the shape decides every task below
  - GIVEN question 1 WHEN answered THEN the answer says whether `suspend` and `rotateKey` confirm, and names the fleet convention it follows
- [x] Implement
- [x] Test

### Task 2: LTI platform and tool, index and detail
- **spec_ref**: `openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-every-configuration-record-an-administrator-owns-has-a-screen-req-acs-001`
- **files**: `src/manifest.json`, `src/icons.js`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN `lti_platform` and `lti_tool` WHEN the manifest is read THEN each has an index and a detail page, both `type` index/detail rather than custom, and every column names a declared property of its schema
  - GIVEN the write-only secret fields declared in `register.d/99-lti-*-secrets-writeonly.json` WHEN the detail form renders THEN those fields accept a value and never display one
  - GIVEN every new page WHEN the manifest is read THEN each widget it declares has a matching entry in that page's `config.layout`, because a widget with no layout entry renders nothing and reports nothing
  - GIVEN every icon named WHEN `src/icons.js` is read THEN the name is registered there and resolves in `vue-material-design-icons`
- [ ] Implement
- [ ] Test

### Task 3: The four LTI lifecycle actions, on the rows they act on
- **spec_ref**: `openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-an-administrative-action-with-a-route-has-a-control-req-acs-002`
- **files**: `src/manifest.json`, `src/views/`
- **acceptance_criteria**:
  - GIVEN `lti#approve`, `lti#suspend`, `lti#generateKey` and `lti#rotateKey` WHEN the LTI platform and tool surfaces render THEN each action is reachable as a row or header action on the record it applies to
  - GIVEN the answer to open question 1 WHEN `suspend` or `rotateKey` is invoked THEN the confirmation behaviour matches what that answer decided, and the dialog names the consequence rather than asking "are you sure"
  - GIVEN an action fails server-side WHEN the response returns THEN the surface reports what failed; a silent no-op is a defect
- [ ] Implement
- [ ] Test

### Task 4: The four single-record configurations
- **spec_ref**: `openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-every-configuration-record-an-administrator-owns-has-a-screen-req-acs-001`
- **files**: `src/manifest.json`, `src/icons.js`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN `fsc_service`, `bankfeed_connection`, `cardfeed_account` and `openformulieren_form_mapping` WHEN the manifest is read THEN each has a manifest-driven index over its schema
  - GIVEN each page WHEN a column is declared THEN the key is a property the schema declares; a column over a key the schema does not carry renders blank and is a defect
- [x] Implement
- [x] Test

### Task 5: EUDI status list and its issuer key surface
- **spec_ref**: `openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-every-configuration-record-an-administrator-owns-has-a-screen-req-acs-001`
- **files**: `src/manifest.json`, `l10n/nl.json`
- **closed**: open question 2 judged `eudi_status_list` machinery (proposal, Answers 2), so no page is built.
- **acceptance_criteria**:
  - GIVEN the answer to open question 2 WHEN `eudi_status_list` is judged administrator-owned THEN it gets a configuration surface; WHEN it is judged machinery THEN this task is closed with that reason recorded and no page is built
  - GIVEN `eudi_issuance_session` and `eudi_credential_offer` WHEN surfaced THEN they are read-only, because neither is a record an administrator authors
- [x] Implement
- [x] Test

### Task 6: Prove the screens render, not merely that they are declared
- **spec_ref**: `openspec/changes/adapter-configuration-screens/specs/adapter-configuration/spec.md#requirement-a-declared-screen-is-proven-to-render-req-acs-003`
- **files**: `tests/e2e/regression/manifest-pages.spec.ts`, `tests/e2e/app-chrome.spec.ts`
- **acceptance_criteria**:
  - GIVEN every page this change adds WHEN `MANIFEST_PAGES` is read THEN the page is listed there, because the coverage guard fails on any manifest page no test navigates to
  - GIVEN each new page WHEN the e2e drives its route THEN the SPA mounts, the router matches, and no console error fires
  - GIVEN the nav shape chosen in task 1 WHEN the chrome spec runs THEN it asserts that shape, so a later tidy-up fails rather than passing review
  - GIVEN every string the manifest adds WHEN gate-102 runs THEN each has a key in `l10n/nl.json` whose value is not the English source repeated
- [ ] Implement
- [ ] Test
