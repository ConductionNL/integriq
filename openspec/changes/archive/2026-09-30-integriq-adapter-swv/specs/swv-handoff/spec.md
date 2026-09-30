# swv-handoff Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- integriq-adapter-swv

## Purpose
Provide the integriq-side wire adapter for the SWV (samenwerkingsverband) support-request/TLV hand-off to Kindkans-shaped and LDOS-shaped receivers, per the abstract integration pattern (learniq declares the `swv` job type and composes the dossier; integriq owns the adapter — D3, decisions.md). M3-integrations.md row I10.

## ADDED Requirements

### Requirement: Dormant SWV hand-off client with deterministic mock default (REQ-001)
The system MUST provide an abstract `SwvHandoffClient` with exactly one concrete subclass active by default, `SwvHandoffClientMock`, returning a deterministic, canned acknowledgement and never performing network I/O. Each client MUST expose `flavour()` returning `mock` (or, for a future live binding, `https`).

#### Scenario: Mock client returns a deterministic acknowledgement
- GIVEN a `SwvHandoffClientMock` instance
- WHEN `handOff('swv-kindkans', $dossier)` is called with any dossier array
- THEN it returns an array with `referenceId`, `acceptedStatus` and `receivedAt` keys
- AND `flavour()` returns `mock`

### Requirement: Source adapter maps an already-composed dossier onto the receiver's envelope (REQ-002)
The system MUST provide a `SwvHandoffSourceAdapter` that calls the configured `SwvHandoffClient`, maps the already-composed SWV dossier (support-request and TLV fields) onto the receiver-shaped envelope, and logs a non-PII-bearing summary. Pupil-identifying values (`pupilReference`, any BSN-shaped value) MUST NEVER be passed to the logger.

#### Scenario: Source adapter hands off a dossier and returns the acknowledgement
- GIVEN the `SwvHandoffSourceAdapter` is configured with the mock client
- WHEN `handOffDossier('swv-kindkans', $dossier)` is called with a dossier containing `pupilReference`, `requestType`, `tlvRequested`
- THEN the returned acknowledgement carries a `referenceId`
- AND the debug log entry contains no `pupilReference` value

### Requirement: Two dormant Source rows, one per receiver, sharing one adapter class (REQ-003)
The system MUST seed two Source rows in `lib/sources.seed.json` — `swv-kindkans`, `swv-ldos` — each `isEnabled: false`, each referencing `SwvHandoffSourceAdapter` as `adapterClass`, each gated behind `swv.handoff.feature_flag`.

#### Scenario: Both receiver rows are seeded and dormant
- GIVEN `lib/sources.seed.json` after this change
- WHEN the sources list is parsed
- THEN it contains exactly two new rows with ids `swv-kindkans`, `swv-ldos`
- AND each has `isEnabled: false`

### Requirement: The Privacyconvenant holder question is recorded, never enforced (REQ-004)
The system MUST expose a `privacyconvenantHolder()` method reading the `swv.privacyconvenant.holder` app-config key (default empty string) and include its value in the debug-log summary. This value MUST NOT gate `isActive()` or any dispatch logic — it is an operational record, not a code blocker.

#### Scenario: An unset Privacyconvenant holder does not block a mock hand-off
- GIVEN `swv.privacyconvenant.holder` is unset (empty string)
- WHEN `handOffDossier()` is called with the mock client
- THEN the call succeeds and returns an acknowledgement
- AND the debug log records `privacyconvenantHolder: ''`

## Non-Functional Requirements

- **Performance:** the mock client returns synchronously with no I/O.
- **Accessibility:** N/A — no user interface in this change.
- **Internationalization:** N/A — no new user-facing strings.

## Acceptance Criteria

- [ ] `SwvHandoffClientMock::handOff()` returns a deterministic acknowledgement with no network I/O.
- [ ] `SwvHandoffSourceAdapter::handOffDossier()` maps the dossier and never logs a pupil-identifying value.
- [ ] `privacyconvenantHolder()` never blocks dispatch, regardless of its value.
- [ ] Two Source rows are seeded, disabled, in `lib/sources.seed.json`.
- [ ] Contract tests pass against the recorded/representative fixture.

## Notes
The dossier fixture's field names are drawn from the SWV-hosted operator manuals found in market-intelligence round 1 (Kindkans: "Handleiding van ParnasSys naar Kindkans via OSO SWV"; LDOS: "Handleiding TLV-applicatie LDOS", SWV PO Eindhoven, 2023) — both describe the hand-off as an OSO SWV dossier exchange, not a bespoke API per vendor. This is representative, not a payload captured from a live receiver — no live credentials exist for this round. Onderwijs Transparant and TOP dossier are also OSO-connected SWV systems (care-swv/round1/sources.md) but are out of scope for this change per the lane brief's "Kindkans and LDOS shaped" wording.
