# rod-adapter Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- integriq-adapter-rod
- rod-adapter-bsn

## Purpose

The DUO ROD adapter identifies a pupil the way DUO asks, by a burgerservicenummer or an
onderwijsnummer, and sends the school advice as DUO's AanleverenAdviesVO_Request (PvE ROD-PO
1.14.2, 15-4-2026, section 7.9.1). The number never leaves the adapter except inside the
message to DUO.

## ADDED Requirements

### Requirement: REQ-001: the persoonsgebonden nummer goes in DUO's choice element
Every ROD message MUST carry `persoonsgebondenNummer` with exactly one child named by
`persoonsgebondenNummerType`: `burgerservicenummer` or `onderwijsnummer`. The number MUST be
nine digits. A payload with only the legacy `bsn` key MUST be read as a burgerservicenummer.
A missing number, an unknown type or a malformed number MUST fail translation before any XML
is built.

#### Scenario: an onderwijsnummer
- GIVEN a learner record with `persoonsgebondenNummerType` `onderwijsnummer`
- WHEN an inschrijving is translated
- THEN the body holds `<persoonsgebondenNummer><onderwijsnummer>` with the number
- AND no `bsn` element

#### Scenario: an unknown type
- GIVEN `persoonsgebondenNummerType` `paspoort`
- WHEN any message is translated
- THEN translation fails naming `persoonsgebondenNummerType`

#### Scenario: the learner mapping maps the number, not the ECK iD
- GIVEN the seeded mapping `learniq-bron-rod-export-learner`
- THEN it maps `persoonsgebondenNummer` and `persoonsgebondenNummerType` from the same input keys
- AND it still maps `eckId`

### Requirement: REQ-002: the school advice is sent as AanleverenAdviesVO_Request
A `bron-rod` message with berichtsoort `schooladvies` MUST render an
`AanleverenAdviesVO_Request` holding, in order: persoonsgebonden nummer, `adviesvolgnummer`,
`onderwijsaanbieder` and `onderwijslocatie` when not null, `vestigingscode`, `adviesjaar`,
`advies1` and `advies2` each as a combined `advies` plus `adviesdatum` and left out when null.
At least one advice MUST be given. Formats: adviesvolgnummer letters and digits up to 20;
onderwijsaanbieder `nnnAnnn`; onderwijslocatie `nnnXnnn`; vestigingscode six letters or digits;
adviesjaar four digits; advice dates `Y-m-d`; an advice value in DUO's value-list format.
The seeded mapping `learniq-bron-rod-export-schooladvies` MUST exist, and a learniq `bron-rod`
job without a mapping slug and with scope `berichtsoort: schooladvies` MUST use it.

#### Scenario: advice with a reconsidered definitive advice
- GIVEN `advies1` `VMBO_KB` on 2026-01-20 and `advies2` `VMBO_GL/TL` on 2026-05-15
- WHEN the schooladvies is translated
- THEN `advies1` and `advies2` each hold their `advies` and `adviesdatum`

#### Scenario: advice without a second advice
- GIVEN `advies2` null
- WHEN the schooladvies is translated
- THEN no `advies2` element is rendered

#### Scenario: a malformed onderwijsaanbieder
- GIVEN `onderwijsaanbieder` `100B200`
- WHEN the schooladvies is translated
- THEN translation fails naming `onderwijsaanbieder`

#### Scenario: default mapping for a schooladvies job
- GIVEN learniq requests a `bron-rod` export with scope `berichtsoort: schooladvies` and no mapping
- WHEN the job is created
- THEN its mapping is `learniq-bron-rod-export-schooladvies`

### Requirement: REQ-003: the persoonsgebonden nummer never reaches a log or a stored error
The ROD path MUST NOT write the persoonsgebonden nummer to a log line, an exception message,
a `rod_message` error or an exchange rejection. Provider and transport messages MUST be
redacted (the known number and any standalone run of nine digits) before they are logged,
thrown or stored. Translation failures MUST name fields, never values.

#### Scenario: a DUO fault echoes the number
- GIVEN a provider failure message containing the number
- WHEN the send fails
- THEN the thrown message, the stored `error` and every log line lack the number

#### Scenario: an exchange rejection
- GIVEN a `bron-rod` job whose send fails
- WHEN the dispatcher records the rejection
- THEN the rejection holds a code and field names only

## Non-Functional Requirements

- **Performance:** no extra calls; validation is in-memory.
- **Accessibility:** no UI change.
- **Internationalization:** no new user-facing strings.

## Acceptance Criteria

- Mapping tests for the learner message and the school advice message.
- Logger-capture tests prove the number never appears.
