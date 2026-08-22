# Migrate inline source secrets into the credential broker

## Why

`source` objects store credentials (`apikey`, `secret`, `password`, `jwt`,
`authenticationConfig`) INLINE. Phase 2 (ocon#147) marked them `writeOnly` so
OpenRegister's render boundary strips them from every API response, but the
secret bytes still live in the object at rest. ADR-064 requires those secrets to
move into the OpenRegister credential broker (Doriath), leaving only a
`{credentialRef}` placeholder on the source.

## What

- A read-safe **planner** that classifies every source's inline fields
  (`already-migrated` / `empty` / `would-migrate` / `needs-manual-review`).
- A writing **executor** that, per source per field, mints an
  organisation-scoped broker credential, verifies the secret round-trips, and
  only then writes the nested `{credentialRef}` and nulls the inline value. It is
  idempotent, per-source/per-field isolated, and fails closed.
- The `occ integriq:migrate-inline-secrets` command drives the planner
  (`--dry-run`) and the executor (real run), with `--json` for machines.
- A repair step + the real run persist the **Phase D gate**
  (`openconnector / inline_secrets_clean`) so a later change may remove the
  schema properties only when zero inline secrets remain.

The executor half was deferred until OpenRegister shipped sessionless
organisation resolution — openregister#450 (`actingOrganisationId` on
`resolveInjectable()`) and or#440 (sessionless `mint()`) — which now let this
migration mint AND verify organisation-scoped credentials without a user session.

## Impact

- Rewrites LIVE credentials — every safety property (verify-before-null,
  fail-closed, secret hygiene, idempotency) is load-bearing.
- No schema properties are removed here (Phase D, a separate change).
- Affected: `lib/Service/Security/InlineSecretMigrationPlanner.php`,
  `lib/Service/Security/InlineSecretMigrationExecutor.php`,
  `lib/Command/MigrateInlineSecrets.php`,
  `lib/Repair/RecordInlineSecretMigrationStatus.php`.
