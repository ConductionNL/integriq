# data-residency Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- connections-declare-data-residency

## Purpose

Every outbound destination records where it sits, one page lists them all with
a verdict, and an administrator can enforce EU-only egress. Woo row 17.11.

## ADDED Requirements

### Requirement: A source records its residency with a basis and evidence (REQ-RES-001)

A source SHALL carry an optional `residency` object with `country` (ISO 3166-1
alpha-2, upper case), `basis` (`set-by-admin`, `declared-by-app` or
`seeded`), `evidence` (string), `recordedBy` and `recordedAt`. Only an
administrator SHALL set or change it with basis `set-by-admin`, and the change
SHALL be on the source's audit trail. A connection declaration in
`connections.json` MAY carry `residency` with `country` and `evidence`; the
declaration sync SHALL copy it onto the linked source with basis
`declared-by-app` unless that source already has a residency set by an
administrator, which always wins. A country that is not a valid ISO code
SHALL be refused.

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
- THEN the source keeps `DE` with basis `set-by-admin`
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
`outside` or `unknown`) and `lastCallAt`. A host in the call log that matches
no source SHALL be listed with `sourceId` null and zone `unknown`. The answer
SHALL carry `verdict`: `eu-only` only when every listed destination has zone
`eu`, else `not-proven`, with the counts per zone. The zone SHALL come from a
constant list of the 27 EU member states and the three EEA states that are not
in the EU. The page SHALL state that calls made by other apps without
integriq are not covered.

#### Scenario: every destination is in the EU
- GIVEN three enabled sources with residency `NL`, `DE` and `FR`, and no other host in the call log
- WHEN an administrator opens the destinations page
- THEN each row shows its country and zone EU, and the verdict reads "EU only"
- e2e: `tests/e2e/data-residency.spec.ts`

#### Scenario: an unknown destination blocks the verdict
- GIVEN two sources in `NL` and one source without residency
- WHEN the destinations are listed
- THEN the verdict is `not-proven` with `unknown: 1`, and the row without residency is named
- @e2e exclude a verdict computation; covered by PHPUnit `EgressDestinationServiceTest::testAnUnknownDestinationBlocksTheVerdict`

#### Scenario: a host nobody declared shows up
- GIVEN a call log entry to `api.example.com` that matches no source
- WHEN the destinations are listed
- THEN `api.example.com` is listed as an undeclared destination with zone `unknown`
- @e2e exclude a call log join; covered by PHPUnit `EgressDestinationServiceTest::testACallLogHostWithoutASourceIsListed`

#### Scenario: Norway is not the EU
- GIVEN a source with residency `NO`
- WHEN the destinations are listed
- THEN its zone is `eea` and the verdict is `not-proven`
- @e2e exclude a constant list; covered by PHPUnit `EgressDestinationServiceTest::testAnEeaCountryIsNotCountedAsEu`

#### Scenario: a non-administrator cannot read the list
- GIVEN a user who is not an administrator
- WHEN they call `GET /api/egress/destinations`
- THEN the answer is 403 and lists nothing
- @e2e exclude an authorization refusal; covered by PHPUnit `EgressDestinationControllerTest::testANonAdminIsRefused`

### Requirement: An administrator can enforce EU-only egress (REQ-RES-003)

Integriq SHALL offer the setting `egress_eu_only`, off by default. When it is
on, `CallService` SHALL refuse a call to a source whose residency zone is not
`eu`, before any request is sent, and SHALL record the refusal in the call log
with status `refused-residency` and the source's country or `unknown`. A
settings read that fails SHALL be treated as on for a source without
residency, so the failure refuses rather than sends. Switching the setting on
SHALL be refused while the destinations verdict is `not-proven`, with the
blocking destinations named, so a switch cannot silently stop live traffic.

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

#### Scenario: the mode cannot be switched on over a gap
- GIVEN one source without residency
- WHEN an administrator switches EU-only mode on
- THEN the save is refused, naming that source
- e2e: `tests/e2e/data-residency.spec.ts`
