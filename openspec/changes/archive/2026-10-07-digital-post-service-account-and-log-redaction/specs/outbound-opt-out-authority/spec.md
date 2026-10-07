## ADDED Requirements

### Requirement: An erasure redacts the earlier log entries (REQ-OOA-013)

When integriq erases a contact (`erase-contact`), it MUST redact every earlier `integriq_opt_out_log` entry whose address is one of the erased rows' addresses. A redacted entry MUST keep its id, `at`, `kind`, `category`, `channel` and the decision fields of its detail (`state`, `previousState`, `keptState`, `scope`, `code`). Its address MUST be replaced by a keyed hash of the address, its correlation id MUST be emptied, and the rest of its detail (`ref`, `source`, `lawfulBasis`, `evidence`, `optOutId`) MUST be removed. The erasure's own `change` entry MUST carry the hashed key, never the address. Entries written after the erasure stay append-only. An address that is already a hashed BSN keeps its key. Approved by Ruben on 2026-10-07.

#### Scenario: The earlier entries no longer show the address

- **GIVEN** a `change` entry for `jan@example.nl` with source `voorkeuren-formulier` and evidence `{"form":"voorkeuren"}`
- **AND** a `suppressed` entry for `jan@example.nl`
- **WHEN** pipelinq erases contact `c-1`, whose row holds `jan@example.nl`
- **THEN** no log entry holds `jan@example.nl`, the source or the evidence
- **AND** both entries keep their kind, date and decision, under the same hashed key
- **AND** the erasure's own entry carries that hashed key
- @e2e exclude event path, covered by PHPUnit and the live run

#### Scenario: Another person's entries are untouched

- **GIVEN** log entries for `kees@example.nl`
- **WHEN** contact `c-1` (`jan@example.nl`) is erased
- **THEN** the entries for `kees@example.nl` are unchanged
- @e2e exclude event path, covered by PHPUnit

#### Scenario: A send after the erasure is logged as before

- **GIVEN** contact `c-1` was erased and `jan@example.nl` stays opted out
- **WHEN** a case update to `jan@example.nl` is refused
- **THEN** a new `suppressed` entry is appended as usual
- @e2e exclude event path, covered by PHPUnit
