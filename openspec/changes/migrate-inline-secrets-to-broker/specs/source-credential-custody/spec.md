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

## Requirement: Authentication Config Audit

`authenticationConfig` SHALL NOT be migrated to a `credentialRef`: it is VESTIGIAL —
no code authenticates from it (`AuthenticationService` reads `$configuration[...]`;
`AuthenticationRuntime` reads `configuration.authentication.*`; the only live
references are redaction), so a minted ref would never be resolved. It SHALL instead
be AUDITED and then removed (ocon#232).

The audit SHALL report, per source, the uuid, the name, whether the field is
absent/empty, and — for a non-empty bag — the KEY NAMES ONLY (`array_keys()`) plus a
value-shape hint and a non-reversible truncated fingerprint. It SHALL NEVER print,
log, or return a value. It SHALL read via `_render: false` (the field is `writeOnly`,
so a rendered read reports "empty" for a source holding a live credential). It SHALL
offer `--json`, and SHALL be read-only.

#### Scenario: The audit reports key names but never a value

- **WHEN** a source holds `authenticationConfig: {client_id, client_secret}`
- **THEN** the report carries the key names, a shape hint (e.g. `string(36)`) and a truncated sha256 prefix
- **AND** no report field or log line contains the secret value

#### Scenario: The audit reads raw, not through the render boundary

- **WHEN** the audit inspects a source holding a `writeOnly` `authenticationConfig`
- **THEN** the read passes `_render: false` and the source is reported as holding a value, never as clear

#### Scenario: A Twig template referencing the field makes it live

- **WHEN** a source's `configuration` contains a template referencing `source.authenticationConfig`
- **THEN** the source is reported `referenced` with the configuration path, because `CallService::renderValue()` renders `configuration` against the RAW source and the reference resolves to a live secret
- **AND** the field is not reported as safely removable

## Requirement: Authentication Config Removal

Removal of `authenticationConfig` DELETES credential data and SHALL therefore be
reachable ONLY via an explicit human opt-in flag on an occ command. It SHALL NOT be
performed by any repair step, app upgrade, or `occ maintenance:repair`, and SHALL NOT
be armed by a persistent appconfig flag (which would re-trigger unattended on a later
upgrade). Per source it SHALL null the value and save with `_rbac: false,
_multitenancy: false`; it SHALL isolate failures per source, skip already-clear
sources (idempotent), refuse Twig-referenced sources, and log secret-free.

`authenticationConfig` SHALL remain declared in `lib/Settings/openconnector_register.json`:
`Schema::hydrate()` replaces `properties` wholesale, so removing it there would prune
the property fleet-wide and ungated on the next version-bumping import.

#### Scenario: Nothing is written without the explicit flag

- **WHEN** the command is run without `--remove-authentication-config`
- **THEN** it audits only and no source object is written

#### Scenario: One failing source does not abort the batch

- **WHEN** one source's save throws during a removal run
- **THEN** that source keeps its value, the remaining sources are still cleared, and the outcome is reported per source

#### Scenario: A second run is a no-op

- **WHEN** the removal is run again after a successful run
- **THEN** every source is skipped as already clear and no save is issued
