# data-residency Specification

## Purpose

Every outbound destination records where it sits, one page lists them all with
a verdict, and an administrator can enforce EU-only egress. Woo row 17.11.

## ADDED Requirements

### Requirement: A source records its residency with a basis and evidence (REQ-RES-001)

A source SHALL carry an optional `residency` object with `country` (ISO 3166-1
alpha-2, upper case; any other value refused), `basis` (`set-by-admin`,
`declared-by-app` or `seeded`), `evidence`, `recordedBy` and `recordedAt`. Only
an administrator SHALL set or change it with basis `set-by-admin`, on the
source's audit trail. A declaration in `connections.json` MAY carry
`residency`, which the declaration sync SHALL copy onto the linked source with
basis `declared-by-app`.

#### Scenario: an administrator records where a partner sits
- GIVEN the source `zgw-zaken` without residency, and an administrator
- WHEN she sets country `NL` with evidence "verwerkersovereenkomst 2026-03, art. 4"
- THEN the source's residency reads `NL`, basis `set-by-admin`, her user id and the time, and the audit trail has the change
- e2e: `tests/e2e/data-residency.spec.ts`

#### Scenario: an app declares a national endpoint
- GIVEN dossiq's `connections.json` declares `brp` with residency `{country: NL, evidence: "Haal Centraal BRP, RvIG"}` and a linked source without residency
- WHEN the declaration sync runs
- THEN the linked source's residency is `NL` with basis `declared-by-app`
- @e2e exclude a sync side effect; covered by PHPUnit `ConnectionRegistryServiceTest::testADeclaredResidencyIsCopiedToTheLinkedSource`

#### Scenario: an administrator's residency is not overwritten
- GIVEN a source whose residency an administrator set to `DE`
- WHEN a declaration says `NL`
- THEN the source keeps `DE` with basis `set-by-admin`: a residency an administrator set always wins over a declaration
- @e2e exclude a precedence rule; covered by PHPUnit `ConnectionRegistryServiceTest::testAnAdminResidencyWins`

#### Scenario: a declaration file without residency is still valid
- GIVEN a `connections.json` written before this change
- WHEN the validator reads it
- THEN it is accepted unchanged
- @e2e exclude a validator rule; covered by PHPUnit `ConnectionDeclarationValidatorTest::testResidencyIsOptional` and `::testAnInvalidCountryIsRefused`

### Requirement: One page lists every outbound destination with its location (REQ-RES-002)

Integriq SHALL serve `GET /api/egress/destinations` (admin only) and an admin
page over it. The answer SHALL list every enabled source and every distinct
host in the call log of the last 30 days, each with `host`, `sourceId` (or
null), `country` (or null), `basis`, `evidence`, `zone` (`eu`, `eea`,
`outside` or `unknown`) and `lastCallAt`. The page
SHALL state that calls made by other apps without integriq are not covered.

#### Scenario: every destination is in the EU
- GIVEN three enabled sources with residency `NL`, `DE` and `FR`, and no other host in the call log
- WHEN an administrator opens the destinations page
- THEN each row shows its country and zone EU, and the verdict reads "EU only"
- e2e: `tests/e2e/data-residency.spec.ts`

#### Scenario: a non-administrator cannot read the list
- GIVEN a user who is not an administrator
- WHEN they call `GET /api/egress/destinations`
- THEN the answer is 403 and lists nothing
- @e2e exclude an authorization refusal; covered by PHPUnit `EgressDestinationControllerTest::testANonAdminIsRefused`

### Requirement: The destinations carry a verdict that only proven EU zones pass (REQ-RES-004)

The answer of `GET /api/egress/destinations` SHALL carry `verdict`: `eu-only`
only when every listed destination has zone `eu`, else `not-proven`, with the
counts per zone. The zone SHALL come from a constant list of the 27 EU member
states and the three EEA states that are not in the EU.

#### Scenario: an unknown destination blocks the verdict
- GIVEN two sources in `NL` and one source without residency
- WHEN the destinations are listed
- THEN the verdict is `not-proven` with `unknown: 1`, and the row without residency is named
- @e2e exclude a verdict computation; covered by PHPUnit `EgressDestinationServiceTest::testAnUnknownDestinationBlocksTheVerdict`

#### Scenario: a host nobody declared shows up
- GIVEN a call log entry to `api.example.com` that matches no source
- WHEN the destinations are listed
- THEN `api.example.com` is listed as an undeclared destination with `sourceId` null and zone `unknown`
- @e2e exclude a call log join; covered by PHPUnit `EgressDestinationServiceTest::testACallLogHostWithoutASourceIsListed`

#### Scenario: Norway is not the EU
- GIVEN a source with residency `NO`
- WHEN the destinations are listed
- THEN its zone is `eea` and the verdict is `not-proven`
- @e2e exclude a constant list; covered by PHPUnit `EgressDestinationServiceTest::testAnEeaCountryIsNotCountedAsEu`


### Requirement: An administrator can enforce EU-only egress (REQ-RES-003)

Integriq SHALL offer the setting `egress_eu_only`, off by default. When it is
on, `CallService` SHALL refuse a call to a source whose residency zone is not
`eu`, before any request is sent, and SHALL record the refusal in the call log
with status `refused-residency` and the source's country or `unknown`. A failed
settings read SHALL refuse a source without residency. Switching the setting on
SHALL be refused while the destinations verdict is `not-proven`.

#### Scenario: a call to an unknown destination is refused
- GIVEN EU-only mode on and a source without residency
- WHEN a synchronization calls that source
- THEN no HTTP request is sent, and the call log holds a record with status `refused-residency` and country `unknown`
- @e2e exclude an outbound refusal; covered by PHPUnit `CallServiceResidencyTest::testAnUnknownDestinationIsRefusedBeforeSending`

#### Scenario: a call to an EU destination goes out
- GIVEN EU-only mode on and a source with residency `NL`
- WHEN a synchronization calls it
- THEN the request is sent as today
- @e2e exclude an outbound call; covered by PHPUnit `CallServiceResidencyTest::testAnEuDestinationIsCalled`

#### Scenario: a failed settings read refuses rather than sends
- GIVEN the settings read fails, and a source without residency
- WHEN a synchronization calls that source
- THEN the call is treated as if EU-only mode were on: no HTTP request is sent and the call log holds a `refused-residency` record
- @e2e exclude a fail-closed path; covered by PHPUnit `CallServiceResidencyTest`

#### Scenario: the mode cannot be switched on over a gap
- GIVEN one source without residency
- WHEN an administrator switches EU-only mode on
- THEN the save is refused, naming that source as a blocking destination, so the switch cannot silently stop live traffic
- e2e: `tests/e2e/data-residency.spec.ts`
