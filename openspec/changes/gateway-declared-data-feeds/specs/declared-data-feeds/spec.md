# declared-data-feeds Specification

## ADDED Requirements

### Requirement: An app declares its data feeds in a file (REQ-DDF-001)

Integriq MUST read `lib/Settings/feeds.json` from every enabled app on install, upgrade and on request, MUST validate it against a published JSON Schema, and MUST keep one read-only endpoint per declared dataset and one API product per declaring app in step with it. A dataset that names a field its schema does not declare MUST be refused with the field named, without affecting the other datasets. Integriq MUST NOT expose a dataset that no declaration names.

#### Scenario: shillinq declares its financial feeds
- GIVEN shillinq ships `feeds.json` with `ledger-lines`, `accounts`, `periods` and `relations`
- WHEN integriq syncs declarations
- THEN four feed endpoints and the API product `shillinq data feeds` exist, and the Feeds page lists them
- e2e: `tests/e2e/feeds-page.spec.ts`

#### Scenario: a declared field does not exist
- GIVEN a dataset listing a field the schema does not declare
- WHEN integriq syncs
- THEN that dataset is refused with the missing field named and the other datasets are served
- @e2e exclude declaration sync; covered by PHPUnit

### Requirement: A feed answers only its declared fields, page by page (REQ-DDF-002)

A feed endpoint MUST answer GET only, MUST return only the declared fields, MUST page with `$top` capped at the declared page size and `$skip`, and MUST include a next link while more records remain. It MUST support `$select` over declared fields and `$filter` with `eq` on declared fields, and MUST answer 400 naming any other query option.

#### Scenario: Power BI refreshes the ledger
- GIVEN 2,500 posted ledger lines for Gemeente Voorbeeld and a page size of 1,000
- WHEN the BI tool calls `ledger-lines` and follows each next link
- THEN it receives three pages holding every line once, each line with only the declared fields
- @e2e exclude machine API without a screen; covered by Newman

#### Scenario: an unsupported query option
- GIVEN a call with `$orderby=amount`
- WHEN the feed answers
- THEN the answer is 400 and names `$orderby` as not supported
- @e2e exclude machine API without a screen; covered by Newman

### Requirement: A consumer reads only the scope it is bound to (REQ-DDF-003)

A feed request MUST be authenticated as a consumer and MUST be answered only within the scope values bound to that consumer for the declaring app, applied as a filter on every request. A request for another scope value, or from a consumer with no binding for that app, MUST receive 403. The check MUST run after authentication and the source allowlist and before the rate limit.

#### Scenario: a BI workspace asks for another municipality's ledger
- GIVEN the consumer "Power BI Gemeente Voorbeeld" bound to its own administration
- WHEN it requests `ledger-lines` with another administration id
- THEN the answer is 403 and no line is returned
- @e2e exclude machine API without a screen; covered by Newman
