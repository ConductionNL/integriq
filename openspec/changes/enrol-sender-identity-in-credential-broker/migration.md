# Migration: enrol-sender-identity-in-credential-broker

## Current State

Each `sender_identity` object in the `integriq` register may carry:

```json
{
  "smimeCertificate": "-----BEGIN CERTIFICATE-----\n...",
  "smimePrivateKey":  "-----BEGIN PRIVATE KEY-----\n..."
}
```

`smimePrivateKey` is a plain schema property with no `writeOnly` marker and no
schema `authorization` block, so it is stored in the register in cleartext and
returned by any read the caller is permitted to make.

## Target State

```json
{
  "smimeCertificate":   "-----BEGIN CERTIFICATE-----\n...",
  "smimePrivateKeyRef": "{credentialRef}",
  "smimePrivateKey":    ""
}
```

The key material is held by `CredentialBrokerService`, minted at `organisation`
scope under app id `openconnector`. `smimePrivateKeyRef` is readable. The legacy
`smimePrivateKey` property is marked `writeOnly` and emptied; it is **not removed**
here — removal is Phase D, gated on the estate reporting `clean: true`, exactly as
`source` was handled.

## Migration Class

This is **not** a Nextcloud `Version*` migration, and that is deliberate.

```
Not: lib/Migration/VersionXXXXXXXXXX.php
```

A `Version*` migration runs unattended during `occ upgrade`. This transformation
mints credentials into an external store, must verify each one round-trip before
destroying the original, and must be able to refuse and change nothing when the
installed broker is too old. Running that unattended, inside an upgrade whose
failure mode is a half-upgraded instance, is the wrong place for it.

Instead it extends the existing operator-invoked command, which already implements
exactly this discipline for `source`:

```
Command:  occ integriq:migrate-inline-secrets [--dry-run] [--json] [--limit=n]
Executor: lib/Service/Security/InlineSecretMigrationExecutor.php
Planner:  lib/Service/Security/InlineSecretMigrationPlanner.php
Status:   lib/Repair/RecordInlineSecretMigrationStatus.php   (repair step, reports only)
```

Key operations:
- extend `MIGRATABLE` with `sender_identity` → `smimePrivateKey` → `smimePrivateKeyRef`
- mint at `SCOPE_ORGANISATION`, never `personal`
- verify byte-for-byte before any write-back
- write the reference into the **separate** readable property, then empty the original
- refuse the whole run when the broker cannot mint or resolve sessionlessly

The schema edits themselves (`smimePrivateKeyRef`, the `writeOnly` fragment, the
`authorization` fragment) are declarative and land through the existing
`register.d` merge in `lib/Repair/InitializeRegister.php`. They take effect on
deploy, independently of whether the data migration is ever run.

## Migration Steps

1. **Deploy the schema changes.** `smimePrivateKeyRef` is added; the `writeOnly`
   fragment stops `smimePrivateKey` being returned by any read; the lockdown
   fragment adds the `authorization` block. Atomic per deploy, verifiable by reading
   an identity and observing the key is absent. **Disclosure is closed at this
   point**, before any data moves.
2. **Deploy the system-context read** in `SenderIdentityService` in the *same*
   release as step 1. Not optional and not separable — see Rollback, and Risk 1 in
   the proposal.
3. **Report.** `occ integriq:migrate-inline-secrets --dry-run --json` lists each
   identity still holding an inline key and what it would become. Writes nothing.
4. **Migrate, per identity, per field.** For each: mint → resolve back → compare
   byte-for-byte → write `smimePrivateKeyRef` → empty `smimePrivateKey`. A failure
   at any point aborts that field only and leaves it exactly as it was.
5. **Re-report.** The command rewrites the Phase D gate into appconfig so
   `RecordInlineSecretMigrationStatus` stays honest.

Steps 1–2 are one release. Steps 3–5 are operator-invoked, at whatever time suits
the instance.

## Data Impact

**Expected population: zero or near it.** `OutboundSecurityService::protect()` has
no callers, so S/MIME signing does not function yet and no instance should hold a
working signing key. The migration is being written while the field is inert, which
is the cheapest this will ever be. It must still be correct, because "should hold
none" is not "holds none" — an operator may have pre-configured an identity through
the UI.

Affected records: `sender_identity` objects where `smimePrivateKey` is non-empty.
Expected single digits per instance at most; one identity per sending domain is the
realistic shape.

**Data loss:** none, by construction. An inline value is emptied only after the
minted credential has resolved back byte-for-byte identical. The secret exists in
the broker before it stops existing in the register — never the reverse, and never
both-absent.

**Runs on live data:** yes. It is per-object and per-field, and holds no batch
transaction, so an interrupted run leaves every field either fully migrated or
completely untouched. No half-written field is possible.

**Order dependency:** the key must be resolvable before it is destroyed. This is the
entire safety property and is why the verify step is not an optimisation to be
removed by a later refactor — REQ-OSI-010 states it normatively for that reason.

## Rollback Procedure

Three different answers, because the three parts have genuinely different
reversibility, and conflating them is how a rollback destroys a key.

**Steps 1–2 (schema + code):** `git revert`, per repository policy — never a history
rewrite. Reverting restores the previous read behaviour exactly.

⚠️ **Steps 1 and 2 must be reverted together.** Reverting only the system-context
read while leaving the `writeOnly` fragment deployed produces the exact failure this
change is written to avoid: signing silently observes an empty key and mail goes out
unsigned under a message that reads like an unconfigured identity.

**Steps 3–5 (data):** not revertible by reverting code, and no automatic reverse is
provided. That is a decision, not an omission — an automatic reverse would write
private keys back into the register, which is the state being escaped. Reversing is
a deliberate operator action: resolve each reference through the broker, write the
material back inline, clear the reference. It should require someone to mean it.

**If a run fails partway:** nothing to roll back. Each field is atomic and a failed
field is untouched. Re-run after fixing the cause; already-migrated fields are
skipped because their inline value is empty.

**If the broker is too old:** the run refuses up front and rewrites nothing. It does
not partially migrate and does not leave plaintext behind silently.

## Validation

**Before migrating:**
```
occ integriq:migrate-inline-secrets --dry-run --json
```
Lists every identity still holding an inline key. `"clean": true` means none remain.

**That the disclosure is closed (after step 1, independent of the data):**
read a `sender_identity` over the generic object API **as an administrator** and
confirm no PEM private key appears in the body, in `@self.relations`, or in a list
or search response. Administrator specifically, because `writeOnly` stripping is
unconditional and an admin-visible key would mean the fragment is not in effect.

**That signing still works (the step-2 check that matters most):**
with an identity whose key is in the broker, protect a message and assert it comes
back signed. A `signed: false` carrying *"carries no S/MIME certificate and key"* is
the signature of a missing system-context read, not of a missing key — REQ-OSI-011
requires those two causes to report differently so this check cannot be misread.

**After migrating:**
- `--dry-run --json` reports `"clean": true`
- each migrated identity has a non-empty `smimePrivateKeyRef` and an empty `smimePrivateKey`
- resolving each reference through the broker returns key material
- `RecordInlineSecretMigrationStatus` reports the estate as clean in appconfig

**Expected counts:** identities reported before migration == identities carrying a
reference after, with zero remaining inline. Any discrepancy means a field was
skipped, which the command names.
