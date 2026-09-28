# Tasks: gateway-declared-data-feeds

Kind: code. Size M. Half for shillinq `reporting-data-delivery` (row shillinq `rep-bi-feed`).

## Implementation tasks

### Task 1: Read and sync feed declarations
- **spec_ref**: `openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001`
- **files**: `lib/Service/FeedDeclarationService.php`, `lib/Settings/feeds.schema.json`, `lib/Repair/SyncFeedDeclarations.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN shillinq with a `feeds.json` of four datasets WHEN integriq syncs THEN four feed endpoints and one API product exist
  - GIVEN a dataset naming a field its schema lacks WHEN integriq syncs THEN that feed is refused with the field named and the others are kept
- [ ] Implement
- [ ] Test (PHPUnit with a fixture app declaration)

### Task 2: Serve a feed with paging and field selection
- **spec_ref**: `openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-feed-answers-only-its-declared-fields-page-by-page-req-ddf-002`
- **files**: `lib/Service/EndpointService.php`, `lib/Service/Feed/FeedRequestHandler.php`
- **acceptance_criteria**:
  - GIVEN 2,500 ledger lines and a page size of 1,000 WHEN a BI tool follows the next links THEN it receives three pages and every line once
  - GIVEN `$filter=period eq '2026-09'` WHEN requested THEN only that period is returned; GIVEN `$orderby` THEN the answer is 400
- [ ] Implement
- [ ] Test (PHPUnit; Newman collection against a dev instance with shillinq seed data)

### Task 3: Bind each consumer to its scope
- **spec_ref**: `openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-a-consumer-reads-only-the-scope-it-is-bound-to-req-ddf-003`
- **files**: `lib/Settings/integriq_register.json` (`consumer.feedScopes`), the consumer form, `lib/Service/Feed/FeedRequestHandler.php`
- **acceptance_criteria**:
  - GIVEN a consumer bound to Gemeente Voorbeeld WHEN it asks for another administration THEN the answer is 403; WHEN a consumer without a binding asks THEN the answer is 403
- [ ] Implement
- [ ] Test (Newman with two consumers)

### Task 4: Feeds page and docs
- **spec_ref**: `openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001`
- **files**: `src/manifest.json` (Feeds page), `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN shillinq's declaration WHEN an administrator opens Feeds THEN the four datasets show with their URL, fields and subscribed consumers
- [ ] Implement
- [ ] Test (Playwright)

## Verification
- [ ] `openspec validate gateway-declared-data-feeds --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit, Newman and Playwright exit codes read
- [ ] Power BI Desktop (or its OData connector) pulls `ledger-lines` from a dev instance, noted in the PR
