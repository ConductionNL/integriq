# Tasks: connectors-sharepoint-publication-intake

Kind: code. Size M. Rows `opencatalogi:int-sharepoint` and
`integriq:con-sharepoint`.

### Task 1: The packaged set
- **spec_ref**: openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-each-dossier-folder-becomes-a-draft-publication-with-its-documents-req-sppi-002
- **files**: `lib/Settings/configurations/sharepoint-publications.json`, its synchronization and mapping definitions, `tests/fixtures/graph/` (canned Graph responses)
- **acceptance_criteria**:
  - GIVEN the Graph fixture of two dossiers WHEN the synchronization runs THEN two publications are written with titles and a `source` reference and no `publicationDate`
  - GIVEN a renamed folder in the fixture WHEN it runs again THEN the same publication gets the new title
- [ ] Implement
- [ ] Test (PHPUnit on the mapping against the fixture; the set passes the install guard)

### Task 2: Documents attached in a cron run
- **spec_ref**: openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-each-dossier-folder-becomes-a-draft-publication-with-its-documents-req-sppi-002
- **files**: the set's synchronization (`downloadUrl` as file reference), `lib/Service/SynchronizationService.php` only if REQ-004 cannot read the reference as configured
- **acceptance_criteria**:
  - GIVEN a run with no session and an acting user recorded on the source WHEN documents are fetched THEN each is attached to its publication object
  - GIVEN the same run WHEN it completes THEN no file exists in any user's `OpenConnector SharePoint Documents` folder
- [ ] Implement
- [ ] Test (integration test running the synchronization without a session)

### Task 3: The Graph lookup route
- **spec_ref**: openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
- **files**: `lib/Controller/SharePointSetupController.php`, `appinfo/routes.php`, `lib/Service/Adapter/DocumentCms/SharePointOnlineAdapter.php` (site search, drives, folder children)
- **acceptance_criteria**:
  - GIVEN a brokered credential WHEN the route is asked for sites matching "Woo" THEN it returns the Graph matches with id and name
  - GIVEN a Graph 403 WHEN the route is called THEN it answers with the permission Graph named
- [ ] Implement
- [ ] Test (PHPUnit with a stubbed broker)

### Task 4: The setup dialog
- **spec_ref**: openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-an-administrator-sets-up-sharepoint-to-publication-intake-from-the-store-req-sppi-001
- **files**: `src/modals/SharePointPublicationSetupModal.vue`, `src/components/CatalogItemCard.vue` or the Store detail dialog action, `src/handlers/actionHandlers.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the four choices made WHEN the administrator saves THEN one source, one synchronization and one mapping are created through the configuration import
  - GIVEN "Review before import" on WHEN the first run starts THEN it pauses for approval
- [ ] Implement
- [ ] Test (`tests/e2e/sharepoint-publications.spec.ts` against the mock Graph)

### Task 5: Deprecate the legacy template and seed a demo
- **spec_ref**: openspec/changes/connectors-sharepoint-publication-intake/specs/document-cms-connectors/spec.md#requirement-the-legacy-sharepoint-woo-template-is-marked-deprecated-req-sppi-003
- **files**: `configurations/sharepoint-woo/` (deprecation note), the configuration import preview, `lib/Settings/integriq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN the import screen WHEN the legacy template is chosen THEN a deprecation warning names the new set
  - GIVEN demo data WHEN the Synchronizations page opens THEN a mock SharePoint to publication synchronization is listed
- [ ] Implement
- [ ] Test (`tests/e2e/sharepoint-publications.spec.ts`)

## Verification

- `openspec validate connectors-sharepoint-publication-intake --type change --strict`
- Against a Microsoft 365 test tenant: one dossier folder becomes one draft
  publication with its files, and publishing it in OpenCatalogi makes it
  public.
- `composer check:strict` and `npm run lint` once before push.
