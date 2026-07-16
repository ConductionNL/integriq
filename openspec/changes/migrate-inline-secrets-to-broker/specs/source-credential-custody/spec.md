# Source Credential Custody

## Purpose

Move every `source` object's INLINE credential (`apikey`, `secret`, `password`,
`jwt`, `authenticationConfig`) out of the object body and into the OpenRegister
credential broker, so a source stores only a `{credentialRef: {credentialId}}`
placeholder and the secret bytes live in Doriath. This is phase C of ocon#151 /
ADR-064: the READ-SAFE planner + the WRITING executor (mint → verify → null),
plus the machine-readable Phase D gate that must be clean before the schema
properties may be removed (phase D, a separate change).

@e2e exclude occ/repair inline-secret migration has no browser surface — it is exercised end-to-end by PHPUnit round-trip + mutation tests (InlineSecretMigrationExecutorTest, InlineSecretMigrationPlannerTest, RecordInlineSecretMigrationStatusTest).

## Requirement: Inline Secret Migration Plan

The planner SHALL classify each inline secret field of every `source` object as
`already-migrated`, `empty`, `would-migrate` (mapped to an inject-only broker
provider), or `needs-manual-review` (unmappable, e.g. `authenticationConfig`),
without ever returning, logging, or embedding a secret value.

#### Scenario: Provider mapping matches the inject-only catalogue

- **WHEN** a source holds `apikey`, `password`, `secret`, and `jwt`
- **THEN** they map to `generic-apikey`, `generic-basic`, `generic-oauth2`, and `generic-bearer` respectively
- **AND** the plan carries field names and provider ids only — never a secret value

#### Scenario: authenticationConfig is flagged, not guessed

- **WHEN** a source holds an `authenticationConfig` object
- **THEN** the field is classified `needs-manual-review` with no provider

## Requirement: Raw Secret Read

The migration SHALL read a source's inline secrets via `ObjectService::find(...,
_render: false)`, the only read that survives OpenRegister's writeOnly render
boundary. A rendered read strips the secret and MUST NOT be used.

#### Scenario: Raw read sees the secret through the render boundary

- **WHEN** the planner reads a source that holds a writeOnly `apikey`
- **THEN** the read is issued with `_render: false` and returns the secret intact

## Requirement: Inline Secret Migration Executor

The executor SHALL, per source per would-migrate field, mint an
organisation-scoped broker credential, VERIFY the secret round-trips via
`resolveInjectable(credentialId, 'openconnector', null, actingOrganisationId)`
with the source's organisation asserted, and ONLY on a byte-for-byte match write
the nested `{credentialRef}` placeholder and null the inline value. A mint
failure, a verify mismatch or exception, or a save failure SHALL leave the inline
value intact. A source with no organisation SHALL be blocked, never minted at
personal scope. The run SHALL be idempotent and SHALL fail closed (rewriting
nothing) when the installed broker lacks `mint()` or the 4-argument
`resolveInjectable()`.

#### Scenario: Verified round-trip writes the ref and nulls the inline value

- **WHEN** a source holds an inline `apikey` and the minted credential resolves back identically
- **THEN** `configuration.authentication.apikey` becomes `{credentialRef: {credentialId}}`
- **AND** the top-level `apikey` is nulled
- **AND** `resolveInjectable` was called with the source's organisation as `actingOrganisationId`

#### Scenario: A failed verify never nulls the inline secret

- **WHEN** the minted credential resolves to a different value than the inline secret
- **THEN** the inline value is left intact, no ref is written, and the source is not saved

#### Scenario: A source with no organisation is blocked, never minted at personal scope

- **WHEN** a source with an inline secret has no organisation
- **THEN** the field is blocked, no credential is minted, and the inline value is intact

#### Scenario: The run fails closed on an old broker

- **WHEN** the installed broker lacks `mint()` or the `actingOrganisationId` parameter
- **THEN** the run refuses with an upgrade hint and rewrites nothing

## Requirement: Real Run Fails Closed

A real (writing) run SHALL never silently leave plaintext: when it cannot mint or
verify safely it SHALL refuse with an actionable upgrade hint and a non-zero exit,
and SHALL NOT downgrade the scope to make the run "work".

#### Scenario: Refused real run rewrites nothing

- **WHEN** a real run cannot mint or resolve organisation-scoped credentials sessionlessly
- **THEN** the command exits non-zero, prints the upgrade hint, and no source is modified

## Requirement: Phase D Gate Signal

The `openconnector / inline_secrets_clean` appconfig flag SHALL be `'1'` only when
no source holds an unmigrated inline secret (zero would-migrate AND zero
needs-manual-review), and SHALL fail closed to `'0'` on any error or unknown
status. A real run SHALL re-report the true post-run gate from fresh raw reads.

#### Scenario: A pending or manual-review field keeps the gate closed

- **WHEN** any source still holds an inline secret or an unmappable field after a run
- **THEN** `inline_secrets_clean` is `'0'` and Phase D must not remove the schema properties
