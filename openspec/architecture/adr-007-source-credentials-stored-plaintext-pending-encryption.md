# ADR-007: Source credential fields are stored as plaintext today; an EncryptionService layer is planned but not yet wired

## Status
Accepted (capturing existing state — credentials are currently plaintext; the planned EncryptionService design is in [ADR-016](adr-016-encryption-service-design.md))

## Date
2026-05-20

## Context

`lib/Db/Source.php` stores six credential-bearing string columns without any
setter-level or mapper-level encryption:

- `$secret` (`string` / `addType 'string'`)
- `$password` (`string`)
- `$apikey` (`string`)
- `$jwt` (`string`)
- `$jwtId` (`string`)
- `$username` (`string`)

Static inspection of `Source.php:135-179` (the `__construct` / `addType`
block) and `SourceMapper.php:18-23` finds no `EncryptionService` injection and
no overridden setters that call an encrypt/decrypt path. The fields are
serialised verbatim in `jsonSerialize():257-266`, meaning any API response that
includes a full Source object exposes credential values in plaintext to the
authenticated caller.

The in-flight chain B spec (`openconnector-register-storage`) treats the
encryption status as an **open discovery question** (DEFERRED Q3 in
`openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md:445`)
and calls for a startup assertion that inspects `lib/Db/Source.php` setters and
any `EncryptionService` references to determine whether encryption is
"column-level" (setter/mapper) or "storage-level" (Nextcloud server-side).

The in-flight chain C spec (`openconnector-services-direct-or-usage`) resolves
this (discovery.md Q5 at line 152-165): the answer is
**column-level, via a separate `EncryptionService`**. However, the service
class `OCA\Integriq\Service\EncryptionService` does not exist in the
current codebase (`find lib/ -name EncryptionService.php` → no output).
The chain C discovery documents an implicit decrypt call in `Source` entity
getters (`getApikey()` etc.) that was present in a prior version of the code
but has since been removed (or was forward-looking spec language).

In the current deployed code, credential values flow:

1. API caller PATCHes a Source with `{"apikey": "live_xyz..."}`.
2. `SourcesController` calls `$source->hydrate($data)` (Source.php:218-237).
3. `SourceMapper::insert()/update()` persists the raw string value.
4. `CallService::renderConfiguration()` reads `$source->getApikey()` and passes
   it directly into Guzzle auth headers.

No encryption layer is interposed at any point in the current code.

## Decision

Document the current state honestly: Source credential fields (`secret`,
`password`, `apikey`, `jwt`, `jwtId`, `username`) are **persisted as plaintext**
in `oc_openconnector_sources` today. This is the pre-migration status quo;
it is NOT the intended long-term design.

No new code in the current branch should write more credential columns without
also wiring an encryption path. Once `EncryptionService` is implemented:

- Setters for the six credential fields MUST call `encrypt()` before
  persisting.
- Getters (or explicit callsites per chain C's explicit-call model) MUST
  call `decrypt()` before using the value.
- `jsonSerialize()` MUST redact or omit credential fields in API responses
  (already partially done for `request`/`response` filtering in
  `CallService:449-452` for outbound call bodies).

## Consequences

- Until an `EncryptionService` is wired, every admin who has API access to
  `GET /api/sources/{id}` can retrieve raw credential values. This is a
  known pre-existing security gap, not introduced by any new change.
- Chain B (`openconnector-register-storage`) must detect "column-level vs
  storage-level" at migration startup before copying the bytes to OR. If
  column-level is confirmed (currently: neither — plaintext), the migrator
  copies bytes verbatim and chain C consumers must add the decrypt callsite.
- Chain C (`openconnector-services-direct-or-usage`) already accounts for
  explicit `EncryptionService::decrypt()` callsites; this ADR confirms the
  plaintext gap and pins the discovery for the migration author.
- Cross-reference: ADR-005 (Source/Synchronization/Contract triad) — `Source`
  is the entity that hosts the credentials.
- Cross-reference: `openspec/changes/openconnector-register-storage/specs/
  openconnector-storage-migration/spec.md:335-359` — the encryption
  inspection requirement in chain B.
- Cross-reference: `openspec/changes/openconnector-services-direct-or-usage/
  discovery.md:152-165` — Q5 resolution confirming explicit-decrypt model.

## Evidence

- `lib/Db/Source.php:37-40` — `$secret`, `$username`, `$password`, `$apikey`
  declared as nullable strings with no encryption annotation.
- `lib/Db/Source.php:151-155` — `addType('jwt', 'string')`,
  `addType('secret', 'string')` etc. — no custom setter registered.
- `lib/Db/Source.php:257-263` — `jsonSerialize()` includes `jwt`, `secret`,
  `username`, `password`, `apikey` in the returned array verbatim.
- `lib/Db/SourceMapper.php:18-23` — `__construct` injects only `IDBConnection`;
  no `EncryptionService` dependency.
- `lib/Service/CallService.php:449-452` — auth keys are stripped from the
  **Guzzle config** before logging but are read from the entity without
  decryption, implying plaintext read.
- `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md:445-447`
  — "DEFERRED Q3: encryption layering … column-level (verbatim copy)."
- `openspec/changes/openconnector-services-direct-or-usage/discovery.md:152-165`
  — Q5 resolution: "Chain C consumers … MUST call
  `EncryptionService::decrypt(…)` explicitly."
