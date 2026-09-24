# expression-value-sources Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- allowlisted-expression-sources

## Purpose

Integriq resolves the prefixed sources an expression reaches outside the
instance, starting with `env:`, and refuses everything an administrator has
not named. Round 4 discovery depth study D-casetype-20, candidate
C-access-and-privacy-40, passer Valtimo `V-vr`.

## ADDED Requirements

### Requirement: A prefixed value source is resolved through one contract (REQ-EVS-001)

Integriq MUST offer an `ExpressionValueSource` contract with
`prefix()`, `resolve(key, context)` and `describe()`, discovered through a DI
tag and keyed by prefix, with the same collision policy as the integration
registry. A prefix with no registered source MUST fail naming the prefix and
MUST NOT fall through to another source. Integriq MUST NOT parse, evaluate or
extend any expression syntax.

#### Scenario: a prefix is answered by its own source
- GIVEN a registered `env:` source
- WHEN an expression asks for `env:SMTP_HOST`
- THEN the `env:` source answers it
- @e2e exclude prefix dispatch; covered by PHPUnit on the registry

#### Scenario: an unregistered prefix fails and does not fall through
- GIVEN an expression asking for a prefix no source answers to
- WHEN it is resolved
- THEN resolution fails naming the prefix, and no other source is consulted
- @e2e exclude covered by PHPUnit on the registry

#### Scenario: describe says what a source can answer
- GIVEN the `env:` source
- WHEN `describe()` is read
- THEN it names the prefix, whether it supports writing, and that its keys are allowlisted
- @e2e exclude contract shape; covered by PHPUnit

### Requirement: `env:` resolves only an allowlisted key (REQ-EVS-002)

The `env:` source MUST resolve a key only when that exact key appears on an
administered allowlist. A key absent from the allowlist MUST fail naming the
key, MUST NOT return an empty value, and MUST NOT return the value. The
allowlist MUST NOT accept a wildcard, a prefix pattern or an empty entry
meaning all.

#### Scenario: an allowlisted variable resolves
- GIVEN `SMTP_HOST` on the allowlist
- WHEN an expression asks for `env:SMTP_HOST`
- THEN its value is returned
- @e2e exclude backend resolution; covered by PHPUnit on the source

#### Scenario: a variable nobody allowed fails loudly
- GIVEN an expression asking for `env:DATABASE_PASSWORD` with that key absent from the allowlist
- WHEN it is resolved
- THEN resolution fails naming the key, and no value and no empty string is returned
- @e2e exclude covered by PHPUnit on the source

#### Scenario: no wildcard is accepted
- GIVEN an administrator entering `*` or an empty entry on the allowlist
- WHEN the allowlist is saved
- THEN the save is refused and the reason names that every key must be listed
- e2e: `tests/e2e/expression-value-sources.spec.ts`

### Requirement: The allowlist is administered and every change is recorded (REQ-EVS-003)

The allowlist MUST be editable only by an instance administrator, MUST show
every listed key with who added it and when, and MUST record every addition
and removal. Adding a key MUST NOT reveal its value in the surface that adds
it.

#### Scenario: an administrator adds a key and the addition is recorded
- GIVEN an administrator adding `BRP_BASE_URL`
- WHEN the change is saved
- THEN the key is listed with the principal and the timestamp, and the change is recorded
- e2e: `tests/e2e/expression-value-sources.spec.ts`

#### Scenario: adding a key does not print its value
- GIVEN an administrator adding a key whose value is a secret
- WHEN the list renders
- THEN the key is shown and the value is not
- e2e: `tests/e2e/expression-value-sources.spec.ts`

#### Scenario: a non-administrator cannot edit the allowlist
- GIVEN a principal who is not an instance administrator
- WHEN they attempt to add a key
- THEN the attempt is refused and the allowlist is unchanged
- @e2e exclude an authorization refusal; covered by PHPUnit on the controller

### Requirement: A resolved external value is redacted before it is buffered (REQ-EVS-004)

A value resolved through any `ExpressionValueSource` MUST be redacted before
a trace, a log or a call record buffers it, per `execution-trace` REQ-003. A
source MUST be able to declare a resolved value as a secret, and a value so
declared MUST be redacted even when the surrounding step is not.

#### Scenario: an allowlisted secret never reaches a trace
- GIVEN an expression resolving an allowlisted key the source declares a secret
- WHEN the step is traced
- THEN the trace holds the redaction marker and not the value
- @e2e exclude redaction runs before buffering; covered by PHPUnit on the resolver

#### Scenario: redaction happens before the write, not on read
- GIVEN a buffered step carrying a resolved secret
- WHEN the buffer is inspected directly
- THEN the value is already absent
- @e2e exclude covered by PHPUnit on the recorder

### Requirement: Writing back is declared, and absent unless declared (REQ-EVS-005)

`describe()` MUST state whether a source supports writing. A source that does
not MUST refuse a write naming the prefix, and MUST NOT succeed silently. The
`env:` source MUST declare that it does not support writing.

#### Scenario: `env:` refuses a write
- GIVEN an attempt to store a value through `env:`
- WHEN it runs
- THEN it is refused naming the prefix and nothing is changed
- @e2e exclude covered by PHPUnit on the source

#### Scenario: a caller can ask before it tries
- GIVEN a caller reading `describe()` for a prefix
- WHEN it checks the write capability
- THEN it learns whether a write is possible without attempting one
- @e2e exclude contract shape; covered by PHPUnit
