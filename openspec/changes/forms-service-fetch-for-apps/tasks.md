# Tasks: forms-service-fetch-for-apps

Kind: code. Size M. Row: portaliq `int-service-fetch`. Builds on `sources-route-distance-and-rdw-lookup` (the call path) and the existing Mapping service.

## Task 1: The `serviceFetch` schema
- **spec_ref**: `specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001`
- **files**: `lib/Settings/register.d/service-fetch.json`
- **acceptance_criteria**:
  - GIVEN the fragment WHEN the register imports THEN `serviceFetch` carries the properties of design D2, each with a `title`, and the log has no `PARTIAL IMPORT` line for it
  - GIVEN a non-admin user WHEN they list `serviceFetch` objects THEN nothing is returned
- [ ] Implement
- [ ] Test

## Task 2: The command and its listener
- **spec_ref**: `specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002`
- **files**: `lib/Event/ServiceFetchRequestedEvent.php`, `lib/Listener/ServiceFetchListener.php`, `lib/Service/ServiceFetchService.php`, `lib/AppInfo/Application.php`, `tests/Unit/Service/ServiceFetchServiceTest.php`
- **acceptance_criteria**:
  - GIVEN each refusal of design D3 WHEN its condition holds THEN the result carries that code and no exception reaches the dispatcher
  - GIVEN a recorded source answer WHEN the fetch runs THEN only declared outputs are in the result, never the body
  - GIVEN `cacheSeconds` 60 WHEN the same inputs are asked twice within a minute THEN the source is called once
- [ ] Implement
- [ ] Test

## Task 3: The call log context
- **spec_ref**: `specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002`
- **files**: `lib/Service/ServiceFetchService.php`, `tests/Unit/Service/ServiceFetchServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a fetch with a `bsn` input WHEN it runs THEN the call log line carries app and fetch slug and the BSN is redacted
- [ ] Implement
- [ ] Test

## Task 4: The designer list endpoint
- **spec_ref**: `specs/service-fetch/spec.md#requirement-a-form-designer-can-list-the-fetches-an-app-may-call-req-sf-003`
- **files**: `lib/Controller/ServiceFetchController.php`, `appinfo/routes.php`, `tests/Unit/Controller/ServiceFetchControllerTest.php`
- **acceptance_criteria**:
  - GIVEN two fetches, one allowed for portaliq WHEN `GET /api/service-fetches?app=portaliq` is called THEN one fetch is listed without source, path or mappings
  - GIVEN the route WHEN gate 5 runs THEN the method carries `#[NoAdminRequired]`
- [ ] Implement
- [ ] Test

## Task 5: The admin page
- **spec_ref**: `specs/service-fetch/spec.md#requirement-an-administrator-defines-a-service-fetch-with-inputs-and-mapped-outputs-req-sf-001`
- **files**: `src/views/admin/ServiceFetchesPage.vue`, `src/modals/ServiceFetchModal.vue`, `src/router/index.js` (admin section, not a public route), `l10n/nl.json`, `l10n/en.json`, `tests/e2e/service-fetches.spec.ts`
- **acceptance_criteria**:
  - GIVEN an administrator WHEN they create a fetch and press "Testen" THEN the raw answer and the mapped outputs show side by side
  - GIVEN the modal WHEN gate `modal-isolation` runs THEN it passes
- [ ] Implement
- [ ] Test
