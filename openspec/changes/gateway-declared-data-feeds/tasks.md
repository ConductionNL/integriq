# Tasks: gateway-declared-data-feeds

Kind: code. Size M. Half for shillinq `reporting-data-delivery` (row shillinq `rep-bi-feed`).

## Implementation tasks

### Task 1: Read and sync feed declarations
- **spec_ref**: `openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001`
- **files**: `lib/Service/FeedDeclarationService.php`, `lib/Settings/feeds.schema.json`, `lib/Repair/SyncFeedDeclarations.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN shillinq with a `feeds.json` of four datasets WHEN integriq syncs THEN four feed endpoints and one API product exist
  - GIVEN a dataset naming a field its schema lacks WHEN integriq syncs THEN that feed is refused with the field named and the others are kept
- [x] Implement
- [x] Test (PHPUnit with a fixture app declaration)

### Task 2: Serve a feed with paging and field selection
- **spec_ref**: `openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-feed-answers-only-its-declared-fields-page-by-page-req-ddf-002`
- **files**: `lib/Service/EndpointService.php`, `lib/Service/Feed/FeedRequestHandler.php`
- **acceptance_criteria**:
  - GIVEN 2,500 ledger lines and a page size of 1,000 WHEN a BI tool follows the next links THEN it receives three pages and every line once
  - GIVEN `$filter=period eq '2026-09'` WHEN requested THEN only that period is returned; GIVEN `$orderby` THEN the answer is 400
- [x] Implement
- [ ] Test (PHPUnit; Newman collection against a dev instance with shillinq seed data)
  > 2026-11-17, written: `tests/postman/integriq-data-feeds.postman_collection.json` (paging with `$top` and the next link, declared fields only, `$select`, `$filter` eq, `$orderby` and an undeclared field 400, POST 405) against shillinq's `accounts` feed after a sync. Found while writing it: shillinq's real `feeds.json` is written as `datasets` with `binding`, which the sync refused whole; `FeedDeclarationShape` now reads it (PHPUnit `FeedDeclarationShapeTest`, `FeedDeclarationServiceTest::testShillinqsDatasetShapedFileBecomesFourScopedFeeds` over shillinq's file as fixture). Not run against a live instance, so the box stays unticked.

### Task 3: Bind each consumer to its scope
- **spec_ref**: `openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-consumer-reads-only-the-scope-it-is-bound-to-req-ddf-003`
- **files**: `lib/Settings/integriq_register.json` (`consumer.feedScopes`), the consumer form, `lib/Service/Feed/FeedRequestHandler.php`
- **acceptance_criteria**:
  - GIVEN a consumer bound to Gemeente Voorbeeld WHEN it asks for another administration THEN the answer is 403; WHEN a consumer without a binding asks THEN the answer is 403
- [x] Implement
  - Enforcement in `FeedRequestHandler` (pass 1); the consumer form gains **Data feeds**, one line per app (`app: value, value`) edited into `consumer.feedScopes`, refusing to save a line it cannot read and sending `{}` when cleared (`src/modals/v2/consumerDraft.js`, `ConsumerEditorModal.vue`). vitest: `tests/vitest/consumerFeedScopes.spec.js`.
- [ ] Test (Newman with two consumers)
  > 2026-11-17, written: the same collection's scope folder: a consumer bound to one administration reads it and gets 403 for another (as parameter and in `$filter`), a consumer without a binding gets 403, no key 401. Not run live yet.
  - Open: needs a running instance.

### Task 4: Feeds page and docs
- **spec_ref**: `openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001`
- **files**: `src/manifest.json` (Feeds page), `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN shillinq's declaration WHEN an administrator opens Feeds THEN the four datasets show with their URL, fields and subscribed consumers
- [x] Implement
  - **Data feeds** menu entry opens Endpoints with `?targetType=feed` through a query preset: app, address, declared fields, page size; nothing to create. `docs/features/data-feeds.md`; en/nl strings.
  - Not on this page: the subscribed consumers per feed. They are on the app's API product (`<app> data feeds`), which the footer note points to; a per-row join of product subscriptions is not something an index preset expresses.
- [ ] Test (Playwright)
  > 2026-11-17, written: `tests/e2e/declared-data-feeds.spec.ts`: Data feeds from the menu lists a declared feed with its app, address, fields and page size; plain endpoints are not listed and nothing can be added. Not run live yet.
  - Open: needs a running instance.

## Verification
- [x] `openspec validate gateway-declared-data-feeds --strict` passes
  - OpenSpec 2.1.1, 2026-11-21: the change validates ("is valid") under `--strict`.
- [ ] `composer check:strict` once before push; PHPUnit, Newman and Playwright exit codes read
- [ ] Power BI Desktop (or its OData connector) pulls `ledger-lines` from a dev instance, noted in the PR
