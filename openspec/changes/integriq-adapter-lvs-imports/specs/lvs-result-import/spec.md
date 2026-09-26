# lvs-result-import Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- integriq-adapter-lvs-imports

## Purpose
Provide the integriq-side wire adapter for pulling normed toets results (referentieniveaus) from the four Dutch PO leerlingvolgsysteem suites — Cito (via DULT), IEP, Boom and Dia — and mapping them onto learniq's `lvs-import-contract` `DataExchangeJob` payload, per the abstract integration pattern (learniq declares the contract, integriq owns the adapter — D3, decisions.md).

## ADDED Requirements

### Requirement: Dormant UWLR result-import client with deterministic mock default (REQ-001)
The system MUST provide an abstract `UwlrResultImportClient` with exactly one concrete subclass active by default, `UwlrResultImportClientMock`, which returns a deterministic, canned UWLR-shaped result batch and never performs network I/O. Each client MUST expose a `flavour()` method returning `mock` (or, for a future live binding, `https`) so structured logs record which binding handled a call.

#### Scenario: Mock client returns a deterministic result batch
- GIVEN a `UwlrResultImportClientMock` instance
- WHEN `fetchResults()` is called with any supplier id
- THEN it returns an array of result records with `leerlingReference`, `toetscode`, `referentieniveau`, `vaardigheidsscore`, `afnamedatum` and `groep` keys
- AND `flavour()` returns `mock`

### Requirement: Source adapter maps a UWLR-shaped batch onto the lvs-import-contract payload (REQ-002)
The system MUST provide a `UwlrResultImportSourceAdapter` that calls the configured `UwlrResultImportClient`, maps each returned record onto the field names learniq's `lvs-import-contract` payload declares, and logs a non-PII-bearing summary (supplier id, record count, `isActive()` flag, client `flavour()`) — pupil-identifying values (`leerlingReference`, any BSN-shaped value) MUST NEVER be passed to the logger.

#### Scenario: Source adapter produces an lvs-import-contract-shaped payload
- GIVEN the `UwlrResultImportSourceAdapter` is configured with the mock client
- WHEN `importResults('lvs-boom')` is called
- THEN the returned payload array uses the `lvs-import-contract` field names, not the raw UWLR field names
- AND the debug log entry contains a `recordCount` integer and no `leerlingReference` value

#### Scenario: Debug log never leaks a pupil reference
- GIVEN a result batch containing a `leerlingReference` value
- WHEN `importResults()` logs its summary
- THEN the log payload does not contain the literal `leerlingReference` value anywhere in its structure

### Requirement: Four dormant Source rows, one per supplier, sharing one adapter class (REQ-003)
The system MUST seed four Source rows in `lib/sources.seed.json` — `lvs-cito-dult`, `lvs-iep`, `lvs-boom`, `lvs-dia` — each `isEnabled: false`, each referencing `UwlrResultImportSourceAdapter` as `adapterClass`, each gated behind the `lvs.import.feature_flag` app-config key, and each carrying its own `subCategory` and `documentation` URL.

#### Scenario: All four supplier rows are seeded and dormant
- GIVEN `lib/sources.seed.json` after this change
- WHEN the sources list is parsed
- THEN it contains exactly four new rows with ids `lvs-cito-dult`, `lvs-iep`, `lvs-boom`, `lvs-dia`
- AND each has `isEnabled: false` and `adapterClass` equal to `OCA\Integriq\Sources\Lvs\UwlrResultImportSourceAdapter`

## Non-Functional Requirements

- **Performance:** the mock client returns synchronously with no I/O; not a live-traffic requirement this round.
- **Accessibility:** N/A — no user interface in this change.
- **Internationalization:** N/A — no user-facing strings; this is a backend wire adapter with no admin UI in this change.

## Acceptance Criteria

- [ ] `UwlrResultImportClientMock::fetchResults()` returns a deterministic, UWLR-shaped result batch and never performs network I/O.
- [ ] `UwlrResultImportSourceAdapter::importResults()` maps the mock batch onto the `lvs-import-contract` field names.
- [ ] No pupil-identifying value ever reaches the structured logger.
- [ ] Four Source rows are seeded, disabled, in `lib/sources.seed.json`.
- [ ] Contract tests pass against the recorded UWLR fixture.

## Notes
The UWLR fixture used by the contract tests is built from the one primary-source UWLR reference found in market-intelligence round 1 (Boom Testcentrum manual, local PDF extraction, p33/p45) plus the shared "UWLR"/"resultatenkoppeling" vocabulary the ParnasSys- and Esis-side koppeling pages use for IEP and Dia (`lvs-report/round1/sources.md`). It is a representative fixture, not a payload captured from a live supplier feed — no supplier in this round published an open UWLR XSD and no live credentials exist. A live HTTP binding, and correcting the fixture against a real captured payload, is out of scope for this change (see proposal.md).

Who holds the commercial koppelpartner relationship with each of the four suppliers for a self-hosted, multi-tenant open-source LAS is an open governance question, the same shape as the DUO/Edu-V/Privacyconvenant gates already named in `M3-integrations.md` (c). It does not block this change's code.
