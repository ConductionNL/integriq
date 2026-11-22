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
- [x] Implement
  - 2026-11-22: the grant lookup is `Service\Credential\PersonalCredentialLookup`: the person's one personal, enabled broker credential at the provider (`microsoft-entra`, or the app config key `microsoft365_delegated_provider`), the same owner rule a per-user source applies. None, two, or a failed lookup is no grant. `Microsoft365Adapter::delegatedCredentialId()` reads it, so a person with a grant gets mail and chat searched in one `/search/query` request with their own credential (`GraphDelegatedSearch`, D1 once per grant kind), merged after the drive hits under one limit; without one, file hits and `delegated-grant-missing`, and no delegated call. Message hits carry `message:{id}`, Teams hits the handle `TeamsThreadReader` reads, so `fetchThread()` now runs with the person's grant. Not in this task: the `.eml` fetch of a mail hit (Task 3's fetch).
- [x] Test (PHPUnit)
  - `PersonalCredentialLookupTest`, `Microsoft365DelegatedSearchTest` (with and without a grant, the limit across both searches, a refused mail search, mail only, a chat thread read with the person's grant), and `Microsoft365DocumentSearchTest::testMailAndChatOnlyAnswerTheMissingGrantNotice`.

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
- [x] Implement (`docs/features/document-search.md`)
- [x] Test (docs build)
  - `npm ci --legacy-peer-deps && npm run build` in `docs/` on 2026-11-21: the build succeeds, all 10 AI-baseline checks pass, and the build reports no warning for `docs/features/document-search.md`.

## Verification
- [ ] `openspec validate connectors-graph-document-search --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
