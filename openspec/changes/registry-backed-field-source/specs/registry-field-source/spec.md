# registry-field-source Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- registry-backed-field-source

## Purpose

Integriq resolves the value of a property whose schema declares a
registry source. One contract, three calls, and a binding per registry
over a source integriq already holds. A resolved value carries where it
came from, when it was read and how old it is, so "read live, never
copied" is a claim anyone can check. Round 4 discovery cluster 26 and
depth-study cluster CT-5, candidates C-integrations-9 (matrix hole),
C-intake-10, C-parties-and-contacts-15, C-parties-and-contacts-4,
C-integrations-11 and C-integrations-43, decision D2.

## ADDED Requirements

### Requirement: A property source is resolved through one provider contract (REQ-RFS-001)

Integriq MUST offer a `PropertySourceProvider` contract with
`suggest(query, config)`, `resolve(identifier, config)` and `describe()`,
discovered through a DI tag and keyed by provider id, with the same
collision policy as the integration registry. A schema property declaring
`x-openregister-property-source` MUST be resolved through the named
provider and MUST NOT be resolved by any provider-specific code path in a
leaf app.

#### Scenario: a declared provider answers a resolve
- GIVEN a property declaring provider `bag` and an identifier
- WHEN the value is resolved
- THEN the `bag` provider answers it
- @e2e exclude provider dispatch; covered by PHPUnit

#### Scenario: an unknown provider fails loudly
- GIVEN a property declaring a provider id no binding answers to
- WHEN the value is resolved
- THEN the resolution fails with an error naming the provider id
- AND no value is returned and none is invented
- @e2e exclude provider dispatch; covered by PHPUnit

#### Scenario: describe says what the provider keys on
- GIVEN the `brp` provider
- WHEN `describe()` is read
- THEN it names the identifier it keys on and its staleness budget
- @e2e exclude contract shape; covered by PHPUnit

### Requirement: Suggest and resolve are separate calls with separate guarantees (REQ-RFS-002)

`suggest` MUST accept a partial query and MAY answer from cache. `resolve`
MUST take an identifier and MUST answer the source within the provider's
staleness budget. Integriq MUST NOT answer a `resolve` with a `suggest`
result, and MUST NOT treat a suggestion as an authoritative value.

#### Scenario: an applicant types an address
- GIVEN a form field declaring provider `bag`
- WHEN three characters are typed
- THEN suggestions are returned
- e2e: `tests/e2e/registry-backed-field-source.spec.ts`

#### Scenario: a suggestion is not an answer
- GIVEN a suggestion returned for a partial query
- WHEN the field is saved
- THEN the value is resolved by its identifier before it is stored
- @e2e exclude resolution ordering; covered by PHPUnit

### Requirement: A resolved value carries its provenance (REQ-RFS-003)

Every resolved value MUST carry the provider id, the identifier at the
source, the timestamp of the read, and whether it came from the source or
from a cache with that cache entry's age. A value that was typed by a
person MUST carry `manual` and MUST NOT carry a provider id. Provenance
MUST be a field on the value and MUST NOT be written into a description,
a note or a log line only.

#### Scenario: a BAG address says it is the BAG's
- GIVEN an address resolved through the `bag` provider
- WHEN the value is read
- THEN it carries provider `bag`, the BAG object id and the read timestamp
- @e2e exclude value shape; covered by PHPUnit

#### Scenario: a hand-typed address says nobody looked it up
- GIVEN an address a handler typed
- WHEN the value is read
- THEN it carries `manual` and no provider id
- @e2e exclude value shape; covered by PHPUnit

#### Scenario: a cached answer reports its age
- GIVEN a value served from cache
- WHEN the value is read
- THEN it says it came from cache and how old the entry is
- @e2e exclude cache reporting; covered by PHPUnit

### Requirement: Live means a stated staleness budget (REQ-RFS-004)

Each provider MUST declare a staleness budget. A `resolve` MUST reach the
source when the cached entry is older than that budget. A caller MUST be
able to demand a fresh read that bypasses the cache. Integriq MUST NOT
describe a value as live when it was served from an entry older than the
budget.

#### Scenario: a stale entry triggers a source read
- GIVEN a cached value older than the provider's budget
- WHEN it is resolved
- THEN the source is read and the cache is replaced
- @e2e exclude cache expiry; covered by PHPUnit

#### Scenario: a caller demands a fresh read
- GIVEN a cached value inside the budget
- WHEN a caller requests a fresh read
- THEN the source is read
- @e2e exclude cache bypass; covered by PHPUnit

### Requirement: An unreachable source degrades to a labelled last value (REQ-RFS-005)

When a source is unreachable, integriq MUST return the last known value
with its age and an explicit unreachable state. Integriq MUST NOT return
an empty value, and MUST NOT return a stale value without saying that the
source could not be reached.

#### Scenario: the BRP is down during a resolve
- GIVEN a `brp` provider whose source is unreachable and a last known value
- WHEN the value is resolved
- THEN the last known value is returned with its age and an unreachable state
- e2e: `tests/e2e/registry-backed-field-source.spec.ts`

#### Scenario: an unreachable source with nothing cached returns no value and says so
- GIVEN a `brp` provider that is unreachable and no cached value
- WHEN the value is resolved
- THEN no value is returned and the unreachable state is reported
- @e2e exclude failure path; covered by PHPUnit

### Requirement: BAG, BRP and KvK bind to sources that already exist (REQ-RFS-006)

Integriq MUST ship a `bag` binding over the PDOK Locatieserver connector,
a `brp` binding over the seeded Haal Centraal source, and a `kvk` binding
over the KvK API source. A binding MUST NOT open its own HTTP connection
and MUST go through the source and call service.

#### Scenario: the BAG binding reuses the PDOK connector
- GIVEN the `bag` provider
- WHEN an address is resolved
- THEN the PDOK connector answers it in the canonical address shape
- @e2e exclude connector reuse; covered by PHPUnit

#### Scenario: a binding without a source is a configuration error
- GIVEN a binding whose source is not configured
- WHEN a resolve is attempted
- THEN it fails naming the missing source and no HTTP call is made
- @e2e exclude configuration; covered by PHPUnit

### Requirement: A list-shaped source resyncs on demand (REQ-RFS-007)

For a provider whose values are a list rather than a lookup, such as an
organisation tree or a classification plan, integriq MUST offer an
on-demand resync from the administration screen, MUST report when the
last resync ran, and MUST report what changed.

#### Scenario: an administrator resyncs the classification plan
- GIVEN a list-shaped provider with a last resync timestamp
- WHEN an administrator resyncs it
- THEN the list is refreshed and the change count is reported
- e2e: `tests/e2e/registry-backed-field-source.spec.ts`

#### Scenario: a failed resync leaves the previous list in place
- GIVEN a list-shaped provider whose source fails mid-resync
- WHEN the resync runs
- THEN the previous list is still served and the failure is reported
- @e2e exclude partial failure; covered by PHPUnit
