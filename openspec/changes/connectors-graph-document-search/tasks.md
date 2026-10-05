# Tasks: connectors-graph-document-search

Kind: code. Size M. Half for dossiq `woo-requests-gather-documents-from-sources` (row opencatalogi `wr-search-sources`).

## Implementation tasks

### Task 1: Graph search in the Microsoft 365 adapter
- **spec_ref**: `openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008`
- **files**: `lib/Service/Adapter/Saas/Microsoft365Adapter.php`, its manifest entry
- **acceptance_criteria**:
  - GIVEN a recorded Graph answer with a file, a mail and a chat hit WHEN the search runs THEN three REQ-DCC-004 hits with their entity types and handles come back
  - GIVEN more hits than the limit WHEN the search runs THEN it answers the limit and the count of the rest
- [ ] Implement
- [ ] Test (PHPUnit against recorded answers)

### Task 2: Grants per entity type
- **spec_ref**: `openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-microsoft-365-source-answers-a-document-search-across-files-mail-and-chat-req-dcc-008`
- **files**: `lib/Service/Adapter/Saas/Microsoft365Adapter.php`, the per-user grant lookup from `sources-per-user-oauth`
- **acceptance_criteria**:
  - GIVEN a person without a delegated grant WHEN they search THEN file hits come back with the notice `delegated-grant-missing`
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 3: Search and fetch commands
- **spec_ref**: `openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-sibling-app-searches-and-fetches-through-typed-commands-req-dcc-009`
- **files**: `lib/Event/DocumentSearchRequestedEvent.php`, `lib/Event/DocumentFetchRequestedEvent.php`, their listeners, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN dossiq's linked Microsoft 365 connection WHEN it dispatches a search and then a fetch of the file hit THEN it receives the hits and then the file's name, type and content, and integriq keeps no copy
- [ ] Implement
- [ ] Test (PHPUnit with the real event classes; one live search against a Microsoft 365 developer tenant, recorded in the PR)

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

## Amendment 2026-10-05: tasks for Woo row 1.16

Build these after tasks 1 to 4. Every test named here fails on `development`
today: no thread is fetched and no Talk source exists.

### Task 5: The thread of a Teams hit
- **spec_ref**: `openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010`
- **files**: `lib/Service/Adapter/Saas/Microsoft365Adapter.php` (`fetchThread()`), `lib/Event/DocumentFetchRequestedEvent.php` (optional `context`, result key `thread`), `lib/Service/Chat/ThreadTranscript.php` (the plain text rendering), recorded Graph fixtures under `tests/fixtures/graph/`
- **acceptance_criteria**:
  - GIVEN a channel hit with 8 before and 3 after WHEN fetched with context 5 THEN `messages` holds 9 entries in order with one `isHit`
  - GIVEN a chat hit WHEN fetched THEN `GET /chats/{id}/messages` is paged at most 4 times
  - GIVEN a member-added event and a deleted message nearby WHEN fetched THEN neither is in `messages`
  - GIVEN context 100 WHEN dispatched THEN `context-too-large` and no Graph call
- [ ] Implement
- [ ] Test: PHPUnit `Microsoft365AdapterThreadTest::testAChannelHitComesWithItsThread`, `::testAChatHitIsPagedAtMostFourTimes`, `::testSystemAndDeletedMessagesAreDropped`; `DocumentFetchRequestedListenerTest::testAContextAboveTheMaximumIsRefused`; `ThreadTranscriptTest::testTheHitIsMarked`

### Task 6: The Talk source
- **spec_ref**: `openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011`
- **files**: `lib/Service/Chat/TalkMessageSource.php`, `lib/Service/Chat/IntegriqSearchQuery.php` (implements `OCP\Search\ISearchQuery`), the search and fetch listeners (route `platforms` and Talk handles)
- **acceptance_criteria**:
  - GIVEN Talk installed and a matching message in a conversation the officer is in WHEN she searches THEN one hit with `platform` `nextcloud-talk` and handle `chatMessage:talk:{token}:{messageId}`
  - GIVEN that hit WHEN fetched THEN `getRoomForUserByToken()` is called for her before any comment is read, and the thread follows REQ-DCC-010
  - GIVEN `getRoomForUserByToken()` throws WHEN fetched THEN `not-a-participant` and no `ICommentsManager` call
  - GIVEN Talk absent WHEN searched THEN the notice `talk-not-installed` and the Teams hits unaffected
- [ ] Implement. Read the Talk signatures named in `design.md` D5 on the Talk release matching this app's supported Nextcloud versions before writing the calls, and record the release in the PR body. A dynamic call to a renamed method compiles; only the read catches it.
- [ ] Test: PHPUnit `TalkMessageSourceTest::testATalkHitIsFoundAndFetchedWithItsThread`, `::testALeftConversationIsRefused`, `::testTalkAbsentGivesANotice`. Use the real `OCP\Comments\ICommentsManager` interface for the double; Talk's classes are doubled only where Talk is installed in the test container, and the test says so when it skips.
- [ ] One live run against a Talk instance in the dev environment: search, fetch, and leave the conversation and fetch again. Record the output in the PR body.

### Task 7: The caller sees the thread
- **spec_ref**: `openspec/changes/connectors-graph-document-search/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010`
- **files**: `docs/features/document-search.md` (the contract for dossiq: the `platforms` argument, the `context` argument, every key of `thread`, the notices `talk-not-installed`, `not-a-participant`, `context-too-large`)
- **acceptance_criteria**:
  - GIVEN the real `DocumentFetchRequestedEvent` dispatched through a real `IEventDispatcher` wired by `Application::register()` WHEN the handle is a Teams or Talk chat hit THEN the result carries `thread`
- [ ] Implement
- [ ] Test: PHPUnit `DocumentFetchRequestedListenerTest::testTheDispatchedEventReachesTheThreadFetch`
- [ ] Cross-app: record in the PR body that dossiq `woo-requests-gather-documents-from-sources` must store `thread` with the fetched item and test that on its side, with the same event class.

### Verification for tasks 5 to 7

The building agent follows `~/memcap-work/woo-build/LANE-RULES-BUILD.md`:

- [ ] Own clone, `git checkout --no-track -b <branch> origin/development`, `TMPDIR` a sibling outside the clone.
- [ ] PHPUnit judged by the `Tests:` line, or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`.
- [ ] CI runs the gates on the full tree; the coverage guard needs a test for every added statement.
- [ ] `openspec validate connectors-graph-document-search --type change --strict` passes.
- [ ] One PR, `--base development`; merge `development` in, never rebase; no `Co-Authored-By` trailer.
- [ ] Done means merged on `development` with CI green. Row 1.16 is `production` only once a store release carries it.
