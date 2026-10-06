# Tasks: connectors-graph-document-search-chat-threads

Kind: code. Size L. Woo row 1.16. Wave 2. The second part of `connectors-graph-document-search`; task numbers continue from it.

Build these after tasks 1 to 4 of `connectors-graph-document-search` have merged on `development`. Every test named here fails on `development`
today: no thread is fetched and no Talk source exists.

### Task 5: The thread of a Teams hit
- **spec_ref**: `openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010`
- **files**: `lib/Service/Adapter/Saas/Microsoft365Adapter.php` (`fetchThread()`), `lib/Event/DocumentFetchRequestedEvent.php` (optional `context`, result key `thread`), `lib/Service/Chat/ThreadTranscript.php` (the plain text rendering), recorded Graph fixtures under `tests/fixtures/graph/`
- **acceptance_criteria**:
  - GIVEN a channel hit with 8 before and 3 after WHEN fetched with context 5 THEN `messages` holds 9 entries in order with one `isHit`
  - GIVEN a chat hit WHEN fetched THEN `GET /chats/{id}/messages` is paged at most 4 times
  - GIVEN a member-added event and a deleted message nearby WHEN fetched THEN neither is in `messages`
  - GIVEN context 100 WHEN dispatched THEN `context-too-large` and no Graph call
- [ ] Implement
- [ ] Test: PHPUnit `Microsoft365AdapterThreadTest::testAChannelHitComesWithItsThread`, `::testAChatHitIsPagedAtMostFourTimes`, `::testSystemAndDeletedMessagesAreDropped`; `DocumentFetchRequestedListenerTest::testAContextAboveTheMaximumIsRefused`; `ThreadTranscriptTest::testTheHitIsMarked`

### Task 6: The Talk source
- **spec_ref**: `openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-nextcloud-talk-is-a-searchable-source-as-the-requesting-person-req-dcc-011`
- **files**: `lib/Service/Chat/TalkMessageSource.php`, `lib/Service/Chat/IntegriqSearchQuery.php` (implements `OCP\Search\ISearchQuery`), the search and fetch listeners (route `platforms` and Talk handles)
- **acceptance_criteria**:
  - GIVEN Talk installed and a matching message in a conversation the officer is in WHEN she searches THEN one hit with `platform` `nextcloud-talk` and handle `chatMessage:talk:{token}:{messageId}`
  - GIVEN that hit WHEN fetched THEN `getRoomForUserByToken()` is called for her before any comment is read, and the thread follows REQ-DCC-010
  - GIVEN `getRoomForUserByToken()` throws WHEN fetched THEN `not-a-participant` and no `ICommentsManager` call
  - GIVEN Talk absent WHEN searched THEN the notice `talk-not-installed` and the Teams hits unaffected
- [ ] Implement. Read the Talk signatures named in this change's `design.md` D5 on the Talk release matching this app's supported Nextcloud versions before writing the calls, and record the release in the PR body. A dynamic call to a renamed method compiles; only the read catches it.
- [ ] Test: PHPUnit `TalkMessageSourceTest::testATalkHitIsFoundAndFetchedWithItsThread`, `::testALeftConversationIsRefused`, `::testTalkAbsentGivesANotice`. Use the real `OCP\Comments\ICommentsManager` interface for the double; Talk's classes are doubled only where Talk is installed in the test container, and the test says so when it skips.
- [ ] One live run against a Talk instance in the dev environment: search, fetch, and leave the conversation and fetch again. Record the output in the PR body.

### Task 7: The caller sees the thread
- **spec_ref**: `openspec/changes/connectors-graph-document-search-chat-threads/specs/document-cms-connectors/spec.md#requirement-a-fetched-chat-message-comes-with-its-conversation-req-dcc-010`
- **files**: `docs/features/document-search.md` (the contract for dossiq: the `platforms` argument, the `context` argument, every key of `thread`, the notices `talk-not-installed`, `not-a-participant`, `context-too-large`)
- **acceptance_criteria**:
  - GIVEN the real `DocumentFetchRequestedEvent` dispatched through a real `IEventDispatcher` wired by `Application::register()` WHEN the handle is a Teams or Talk chat hit THEN the result carries `thread`
- [ ] Implement
- [ ] Test: PHPUnit `DocumentFetchRequestedListenerTest::testTheDispatchedEventReachesTheThreadFetch`
- [ ] Cross-app: record in the PR body that dossiq `woo-requests-gather-documents-from-sources` must store `thread` with the fetched item and test that on its side, with the same event class.

## Verification

The building agent follows `openspec/woo-build-rules.md`:

- [ ] Own clone, `git checkout --no-track -b <branch> origin/development`, `TMPDIR` a sibling outside the clone.
- [ ] PHPUnit judged by the `Tests:` line, or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`.
- [ ] CI runs the gates on the full tree; the coverage guard needs a test for every added statement.
- [ ] `openspec validate connectors-graph-document-search-chat-threads --type change --strict` passes.
- [ ] One PR, `--base development`; merge `development` in, never rebase; no `Co-Authored-By` trailer.
- [ ] Done means merged on `development` with CI green. Row 1.16 is `production` only once a store release carries it.
