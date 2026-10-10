# Tasks: connectors-graph-document-search

Kind: code. Size M. Half for dossiq `woo-requests-gather-documents-from-sources` (row opencatalogi `wr-search-sources`).

## Implementation tasks

### Task 1: Graph search in the Microsoft 365 adapter
- **spec_ref**: `openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008`
- **files**: `lib/Service/Adapter/Saas/Microsoft365Adapter.php`, its manifest entry
- **acceptance_criteria**:
  - GIVEN a recorded Graph answer with a file, a mail and a chat hit WHEN the search runs THEN three REQ-DCC-004 hits with their entity types and handles come back
  - GIVEN more hits than the limit WHEN the search runs THEN it answers the limit and the count of the rest
- [x] Implement (`Microsoft365Adapter::search()`, `GraphDriveSearch`)
- [x] Test (PHPUnit against recorded answers): `tests/Unit/Service/Adapter/Microsoft365DocumentSearchTest.php`

### Task 2: Grants per entity type
- **spec_ref**: `openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008`
- **files**: `lib/Service/Adapter/Saas/Microsoft365Adapter.php`, the per-user grant lookup from `sources-per-user-oauth`
- **acceptance_criteria**:
  - GIVEN a person without a delegated grant WHEN they search THEN file hits come back with the notice `delegated-grant-missing`
- [ ] Implement (not run: the per-user grant lookup waits on `sources-per-user-oauth`; until then mail and chat answer `delegated-grant-missing`, tested in `Microsoft365DocumentSearchTest::testMailAndChatOnlyAnswerTheMissingGrantNotice`)
- [ ] Test (PHPUnit)

### Task 3: Search and fetch commands
- **spec_ref**: `openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009`
- **files**: `lib/Event/DocumentSearchRequestedEvent.php`, `lib/Event/DocumentFetchRequestedEvent.php`, their listeners, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN dossiq's linked Microsoft 365 connection WHEN it dispatches a search and then a fetch of the file hit THEN it receives the hits and then the file's name, type and content, and integriq keeps no copy
- [x] Implement (`lib/Event/Document{Search,Fetch}RequestedEvent.php`, their listeners, `Application`)
- [ ] Test (PHPUnit with the real event classes: done in `tests/Unit/EventListener/DocumentSearchAndFetchListenerTest.php`; the live search against a Microsoft 365 developer tenant is owed, live pass)

### Task 4: Docs
- **spec_ref**: `openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009`
- **files**: `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the docs WHEN an administrator sets up the search THEN the Graph permissions for files and for mail and chat are listed
- [ ] Implement
- [ ] Test (docs build)

## Verification
- [ ] `openspec validate connectors-graph-document-search --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
