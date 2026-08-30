# Contract: openconnector-register-schema-declaration

## Consumers

This change introduces **no HTTP API**. It declares a register descriptor that is
consumed at install/upgrade time by another openregister-side service, not over
the wire. The "contract" here is the **file-format contract** between
openconnector (producer of the JSON descriptor) and openregister (consumer of the
descriptor through its `ConfigurationService`).

- `openregister` — consumes `lib/Settings/openconnector_register.json` and
  `lib/Settings/openconnector_seed_data.json` via
  `OCA\OpenRegister\Service\ConfigurationService::importFromApp(...)`.
- `openconnector-register-storage` (sibling change) — depends on the descriptor
  file being present on disk before its migration class runs.
- Downstream apps (`decidesk`, `pipelinq`, `procest`, etc.) — read openconnector
  objects via OR's standard `/api/objects/openconnector/{schema}/{uuid}` surface
  AFTER the storage chain ships. Those routes are not part of this change.

## Endpoints

This change introduces no HTTP endpoints.

The descriptor IS an OpenAPI 3.0 document but is not served — it is loaded off
disk by openregister at install/upgrade time. The runtime endpoints that result
from the descriptor (`/api/objects/openconnector/{schema}/{uuid}` etc.) are owned
by openregister and already specified in OR's `openregister-api` capability.

## Error Codes

No new error codes. Failure modes are descriptor-import failures owned by
openregister:

| Code | Meaning                          | Condition                                              |
|------|----------------------------------|--------------------------------------------------------|
| —    | Schema validation failure        | OR's importer rejects malformed JSON; logs to NC log; no openconnector tables affected. |
| —    | Forward `$ref` unresolvable      | OR's importer leaves the relation un-bound; relation engine treats as untyped string until fixed. |
| —    | Append-only flag rejected by OR  | Only if running against OR older than v0.2.10 — openconnector declares this as its minimum OR version. |

These are diagnostic conditions for the developer running `occ` against a freshly
installed/upgraded openconnector; they do not propagate to end users.

## File-Format Contract

### `lib/Settings/openconnector_register.json`

**Shape**: OpenAPI 3.0 document with `x-openregister` extension. Identical
top-level structure to pipelinq and procest:

```json
{
  "openapi": "3.0.0",
  "info": {
    "title": "OpenConnector Register",
    "description": "Integration platform data model — sources, endpoints, jobs, mappings, synchronisations, and append-only logs.",
    "version": "1.0.0"
  },
  "x-openregister": {
    "type": "application",
    "app": "openconnector",
    "openregister": "^v0.2.10",
    "description": "Integration data model — managed via OpenRegister, no own database tables (post storage-chain)."
  },
  "components": {
    "registers": {
      "openconnector": { "slug": "openconnector", "schemas": [/* 15 slugs */], "tablePrefix": "", "folder": "Open Registers/OpenConnector" }
    },
    "schemas": { /* 15 schema definitions */ }
  }
}
```

### `lib/Settings/openconnector_seed_data.json`

**Shape**: object keyed by schema slug, each value an array of object literals
carrying the OR `@self` envelope:

```json
{
  "source": [
    {
      "@self": { "register": "openconnector", "schema": "source", "slug": "postnl-tracking" },
      "name": "PostNL Tracking API",
      "type": "api",
      "...": "..."
    }
  ],
  "consumer": [/* ... */],
  "...": "..."
}
```

Log schemas (`call_log`, `job_log`, `synchronization_log`,
`synchronization_contract_log`) are absent from this file by design — they are
append-only/immutable and must not be seeded.

## Versioning

- **File-format version**: tied to the `version` field at the top of
  `openconnector_register.json` (initial: `"1.0.0"`).
- **App version**: passed to OR's importer as the third argument; matches the
  openconnector app version at the time of import.
- **OR-side compatibility**: declared in `x-openregister.openregister` (initial:
  `"^v0.2.10"` — matches pipelinq's existing pin).

OR's importer is idempotent: re-importing the same `version` is a no-op;
incrementing it triggers a schema-metadata update that preserves existing object
data.

## Breaking Change Policy

- **Adding a new schema** — non-breaking. Bump the file `version` minor (1.0.0 →
  1.1.0).
- **Adding a new property to an existing schema** — non-breaking. Bump patch
  (1.0.0 → 1.0.1).
- **Removing or renaming a schema** — breaking; coordinate with all consumer
  apps (decidesk, pipelinq, procest); bump major (1.0.0 → 2.0.0); follow the
  ADR-032 deprecation window (one release with both names).
- **Removing or renaming a property** — breaking for that schema; same policy.
- **Changing `appendOnly` / `immutable` on a log schema** — forbidden after the
  schema ships; would violate audit-trail integrity. Add a new schema instead.

Changes to the descriptor are visible to consumers when the storage chain
re-runs the importer at app upgrade; consumers must subscribe to openconnector
release notes for any major bump.

## SLA

The descriptor is static. There is no runtime SLA. The importer runs once per
upgrade (typically <5 seconds for 15 schemas + 33 seed objects on a dev laptop).
Past upgrade, all read/write SLAs are governed by openregister's own SLA on its
`/api/objects/...` surface.
