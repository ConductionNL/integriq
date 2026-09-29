# migration-sources Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- migration-source-adapters

## Purpose

Integriq reads a running incumbent system, or a delivered file through a
stored column mapping, and yields records for OpenRegister's import engine to
write. It never writes them itself. Round 4 discovery cluster 8, candidates
C-configuration-88 (matrix hole), C-configuration-16 (matrix hole) and the
read half of C-configuration-95 (matrix hole). Numbers 2 and 13 of the
twenty-five loudest.

## ADDED Requirements

### Requirement: A migration source is an adapter behind one contract (REQ-MSA-001)

Integriq MUST offer a `MigrationSourceAdapter` contract with `describe()`,
`count()`, `read()` and `sample()`, discovered through a DI tag and keyed by
source id. An adapter MUST NOT write to the system it reads and MUST NOT
write to the target. `describe()` MUST name the record kinds the adapter
yields and the identifier each is keyed on.

#### Scenario: an adapter yields records and writes nothing
- GIVEN a configured migration source
- WHEN a read runs to completion
- THEN records are yielded in the declared shape and the source system is unchanged
- @e2e exclude an incumbent system cannot be staged on CI; covered by PHPUnit against a mock-mode fixture

#### Scenario: describe says what an adapter can yield
- GIVEN an adapter for an incumbent
- WHEN `describe()` is read
- THEN it names each record kind and the identifier it keys on, before any read is attempted
- @e2e exclude contract shape; covered by PHPUnit

#### Scenario: an unknown source id fails loudly
- GIVEN a migration configured against a source id no adapter answers to
- WHEN it starts
- THEN it fails naming the source id and reads nothing
- @e2e exclude covered by PHPUnit on the registry

### Requirement: A file is read through a stored column mapping (REQ-MSA-002)

Integriq MUST offer a file migration source that reads a delivered file and
maps its columns onto target fields through a stored mapping object, authored
once and reusable across runs. The mapping MUST be versioned, MUST validate
its target field names when it is saved, and MUST report an unmapped required
field before the run rather than during it.

#### Scenario: an administrator maps a delivered file once and runs it twice
- GIVEN a file and a saved column mapping
- WHEN a second delivery of the same shape arrives
- THEN the stored mapping is selected and no columns are mapped again
- e2e: `tests/e2e/migration-file-source.spec.ts`

#### Scenario: a mapping onto a field that does not exist is refused at save
- GIVEN a mapping naming a target field the schema does not have
- WHEN the administrator saves it
- THEN the save is refused naming the field
- e2e: `tests/e2e/migration-file-source.spec.ts`

#### Scenario: a required field left unmapped stops the run before it starts
- GIVEN a mapping that leaves a required target field unmapped
- WHEN the run is started
- THEN it refuses before reading a row and names the field
- @e2e exclude a pre-run validation; covered by PHPUnit on the file source

### Requirement: A named incumbent has an adapter, and a supported path is rehearsable (REQ-MSA-003)

Integriq MUST ship at least one adapter for a named incumbent case system,
and the contract MUST make a second one configuration and code in the adapter
alone, with no change to the engine or to any consuming app. A migration MUST
be rehearsable: a test run against the real source MUST read, report and
write nothing.

#### Scenario: a rehearsal reads the real system and writes nothing
- GIVEN a configured incumbent adapter against a live source
- WHEN the test run executes
- THEN the counts and a sample are reported and nothing is written anywhere
- @e2e exclude a live incumbent cannot be staged on CI; covered by PHPUnit against a mock-mode fixture and by `synchronization-engine` REQ-011

#### Scenario: a second incumbent needs no engine change
- GIVEN a new adapter registered for a second incumbent
- WHEN a migration is configured against it
- THEN it runs through the same contract and no engine or consumer code changed
- @e2e exclude an absence claim about code; covered by PHPUnit on the registry

### Requirement: A read-only pass reports what a migration would bring (REQ-MSA-004)

Before a run, an adapter MUST report a count per record kind and a bounded
sample, and MUST report whether it read the source completely, per
`synchronization-engine` REQ-009. An incomplete read MUST be reported as
incomplete and MUST NOT be reported as a smaller count. The engine's preview
consumes these and integriq draws no conclusion from them.

#### Scenario: an administrator sees the size before committing
- GIVEN a configured source holding 14,000 cases and 61,000 documents
- WHEN the read-only pass runs
- THEN both counts and a sample of each kind are reported
- @e2e exclude a large-source read; covered by PHPUnit against a mock-mode fixture

#### Scenario: a truncated read says truncated, not small
- GIVEN a source that stops answering halfway through pagination
- WHEN the pass ends
- THEN it reports the read as incomplete, and the partial count is labelled as partial
- @e2e exclude fetch-completeness is the engine's; covered by PHPUnit with a truncated fixture

### Requirement: Every yielded record carries its foreign identity (REQ-MSA-005)

Each yielded record MUST carry the identifier and the source it held in the
system it came from, in the provenance shape `registry-backed-field-source`
REQ-RFS-003 defines. A second run of the same source MUST match on that pair
and MUST NOT yield a record the engine would write as a duplicate. Integriq
MUST NOT decide what the engine does with a match.

#### Scenario: a re-run matches rather than duplicates
- GIVEN a completed migration and a second run of the same source
- WHEN the read runs
- THEN each record carries the same foreign identifier and source, and the engine is told it is a match
- @e2e exclude re-run matching; covered by PHPUnit against a mock-mode fixture

#### Scenario: a source with no stable identifier says so before the run
- GIVEN an adapter for a record kind whose source has no stable key
- WHEN `describe()` is read
- THEN it declares that kind as having no stable identifier, so the engine can refuse or ask
- @e2e exclude contract shape; covered by PHPUnit
