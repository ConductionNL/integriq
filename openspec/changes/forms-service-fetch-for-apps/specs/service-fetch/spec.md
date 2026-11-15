# service-fetch Specification (delta)

## ADDED Requirements

### Requirement: An administrator defines a service fetch with inputs and mapped outputs (REQ-SF-001)

Integriq MUST store a service fetch as a `serviceFetch` object holding a source, a method, a path and query template, the declared inputs, an output mapping, the declared outputs and the apps allowed to call it. Only administrators MUST be able to read or change a `serviceFetch` object. Integriq MUST offer an admin page to list, create, edit, enable, disable and test fetches; the test MUST show the raw answer and the mapped outputs to the administrator only.

#### Scenario: an administrator sets up the Kadaster ownership check
- **WHEN** an administrator creates fetch `kadaster-eigenaar` with source `brk`, inputs `bsn` and `nummeraanduiding`, an output mapping to `isEigenaar`, and allowed app `portaliq`
- **THEN** the fetch is stored and listed on *Beheer > Service fetches*
- **AND** "Testen" with sample inputs shows the source's answer next to `{ "isEigenaar": true }`

#### Scenario: a non-admin cannot read a fetch's source
- **WHEN** a user outside the admin group lists `serviceFetch` objects
- **THEN** nothing is returned

### Requirement: An app calls a service fetch with inputs and gets mapped outputs (REQ-SF-002)

Integriq MUST offer `OCA\Integriq\Event\ServiceFetchRequestedEvent` carrying the calling app, a fetch slug and input values. The listener MUST call the fetch's source with its credential, MUST apply the output mapping, and MUST write only the declared outputs into the result slot. It MUST refuse with `unknown-fetch`, `not-allowed`, `invalid-input`, `source-unavailable`, `mapping-failed` or `timeout`, and it MUST NOT throw to the caller. The caller MUST never receive the raw answer, the source location or the credential.

#### Scenario: portaliq checks ownership during a form
- **WHEN** portaliq dispatches the command for `kadaster-eigenaar` with a BSN and a nummeraanduiding
- **THEN** the result is `{ ok: true, outputs: { isEigenaar: true } }`
- **AND** the call log line names app `portaliq` and fetch `kadaster-eigenaar` with the BSN redacted
- @e2e exclude backend command; covered by PHPUnit with a recorded source answer

#### Scenario: an app that is not allowed
- **WHEN** an app outside the fetch's `allowedApps` dispatches the command
- **THEN** the refusal is `not-allowed` and no outside call is made
- @e2e exclude backend command; covered by PHPUnit

#### Scenario: the outside service is down
- **WHEN** the source answers 503 or its circuit breaker is open
- **THEN** the refusal is `source-unavailable`, which portaliq shows as situation 4 of FormulierNietBeschikbaar
- @e2e exclude backend command; covered by PHPUnit

#### Scenario: a required input is missing
- **WHEN** the command arrives without the required input `bsn`
- **THEN** the refusal is `invalid-input` naming `bsn` and no outside call is made
- @e2e exclude backend command; covered by PHPUnit

### Requirement: A form designer can list the fetches an app may call (REQ-SF-003)

Integriq MUST answer `GET /api/service-fetches?app={app}` for a signed-in user with the enabled fetches that app may call, projected to slug, title, description, inputs and outputs. It MUST NOT include the source, path, query or mappings.

#### Scenario: the designer picks a fetch
- **WHEN** a form designer asks for the fetches of `portaliq`
- **THEN** the answer lists `kadaster-eigenaar` with its inputs and its output `isEigenaar`, and no source or path
- @e2e exclude backend endpoint; covered by PHPUnit and the designer's own e2e in buildiq
