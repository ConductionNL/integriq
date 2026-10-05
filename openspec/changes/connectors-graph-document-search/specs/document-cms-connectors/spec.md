# document-cms-connectors Specification

## ADDED Requirements

### Requirement: A Microsoft 365 source answers a document search across files, mail and chat (REQ-DCC-008)

A Microsoft 365 source MUST answer a search for terms in a date range over drive items, mail messages and chat messages, as REQ-DCC-004 hits carrying `entityType` and a handle that fetches the hit. It MUST search files with the source's application grant and mail and chat with the requesting person's delegated grant, and MUST say so with a notice when that person has no delegated grant. It MUST answer at most the requested number of hits with a count of the rest, and MUST answer `not-permitted` with the missing permission when Microsoft 365 refuses.

#### Scenario: a Woo officer searches for a project name
- GIVEN a Microsoft 365 source with an application grant and the officer's own delegated grant
- WHEN she searches "Stationsplein" from 2025-01-01 to 2026-06-30
- THEN she gets a SharePoint document, a mail and a Teams message as hits with name, location, date and snippet, each with a fetch handle
- @e2e exclude backend search through a sibling app; covered by PHPUnit against recorded Graph answers

#### Scenario: no delegated grant
- GIVEN an officer who never connected her own Microsoft account
- WHEN she searches
- THEN she gets file hits and a notice that mail and chat need her own grant
- @e2e exclude backend search; covered by PHPUnit

### Requirement: A sibling app searches and fetches through typed commands (REQ-DCC-009)

Integriq MUST offer `DocumentSearchRequestedEvent` and `DocumentFetchRequestedEvent` that resolve the calling app's linked Microsoft 365 connection, run for a named person, and answer in the result slot without a Nextcloud session. A fetch MUST return the file name, type and content of the hit, and integriq MUST keep no copy.

#### Scenario: dossiq adds a found document to the case
- GIVEN dossiq's search returned a SharePoint document hit
- WHEN dossiq dispatches the fetch with that handle
- THEN it receives the document's name, type and content, and no file remains in integriq
- @e2e exclude backend command; covered by PHPUnit with the real event classes

## ADDED Requirements (amendment 2026-10-05, Woo row 1.16)

### Requirement: A fetched chat message comes with its conversation (REQ-DCC-010)

A fetch of a chat message hit SHALL answer, besides the message, a `thread`
with `platform`, `conversation` (`id`, `name`, `kind`), `participants` (each
`id` and `label`) and `messages` (each `id`, `at`, `actorLabel`, `text`,
`isHit`), holding up to `context` messages before and after the hit (default
5, maximum 25, a larger value refused). The `content` SHALL be a plain text
transcript of that thread with the hit marked. The thread SHALL be read with
the same grant as the hit. System messages and deleted messages SHALL NOT be
part of a thread.

#### Scenario: a Teams channel message keeps its conversation
- GIVEN a Teams channel hit "Stationsplein: planning aanbesteding" with 8 messages before it and 3 after it
- WHEN dossiq fetches it with context 5
- THEN the answer carries the channel name, its members, the 5 messages before, the 3 after and the hit marked, and the content is a transcript in that order
- @e2e exclude a backend command against Microsoft Graph; covered by PHPUnit `Microsoft365AdapterThreadTest::testAChannelHitComesWithItsThread` against recorded Graph answers

#### Scenario: system and deleted messages stay out
- GIVEN a chat whose neighbouring messages include a member-added event and a deleted message
- WHEN the hit is fetched
- THEN neither appears in `messages`
- @e2e exclude a filter; covered by PHPUnit `Microsoft365AdapterThreadTest::testSystemAndDeletedMessagesAreDropped`

#### Scenario: an oversized context is refused
- GIVEN a fetch with context 100
- WHEN it is dispatched
- THEN the result is the error `context-too-large` naming the maximum 25, and nothing is fetched
- @e2e exclude a validation; covered by PHPUnit `DocumentFetchRequestedListenerTest::testAContextAboveTheMaximumIsRefused`

### Requirement: Nextcloud Talk is a searchable source, as the requesting person (REQ-DCC-011)

When Talk is installed, `DocumentSearchRequestedEvent` SHALL search Talk
messages as the requesting person through Talk's unified search provider, so
only conversations that person is in can match, and answer them as REQ-DCC-004
hits with `entityType` `chatMessage`, `platform` `nextcloud-talk`,
`conversationId` and a handle. A fetch of a Talk hit SHALL first confirm
through Talk that the person is still a participant, and SHALL answer
`not-a-participant` with no messages when they are not. Its thread SHALL
follow REQ-DCC-010. When Talk is not installed the Talk part SHALL answer the
notice `talk-not-installed` and no hits, and the Teams part SHALL be
unaffected.

#### Scenario: an officer finds a Talk discussion about a project
- GIVEN Talk installed, and the officer in a conversation "Project Stationsplein" with a message "offerte Stationsplein ontvangen"
- WHEN she searches "Stationsplein"
- THEN a hit with platform `nextcloud-talk`, the conversation name and the message time comes back, and fetching it returns the conversation's participants and the messages around it
- @e2e exclude a backend command; covered by PHPUnit `TalkMessageSourceTest::testATalkHitIsFoundAndFetchedWithItsThread` with a test that skips only when Talk's classes are absent, and one live run against a Talk instance recorded in the PR

#### Scenario: a conversation the officer left is not read
- GIVEN a Talk hit in a conversation the officer has since left
- WHEN she fetches it
- THEN the answer is `not-a-participant` and carries no messages
- @e2e exclude an authorization refusal; covered by PHPUnit `TalkMessageSourceTest::testALeftConversationIsRefused`

#### Scenario: Talk is not installed
- GIVEN Talk not installed
- WHEN dossiq searches both platforms
- THEN the answer carries Teams hits and the notice `talk-not-installed`
- @e2e exclude an app-absent path; covered by PHPUnit `TalkMessageSourceTest::testTalkAbsentGivesANotice`
