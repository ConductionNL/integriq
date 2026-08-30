# ADR-016: EncryptionService design (specification for the planned class)

## Status
Proposed (specifies a planned class; current codebase state is captured in [ADR-007](adr-007-source-credentials-stored-plaintext-pending-encryption.md))

## Date
2026-05-20

## Context

[ADR-007](adr-007-source-credentials-stored-plaintext-pending-encryption.md)
documents that `Source` credentials (`apikey`, `password`, `secret`, `jwt`,
`username`, and string entries inside `authenticationConfig`) are currently
stored as **plaintext** in `oc_openconnector_sources` — no `EncryptionService`
class exists in the codebase, and `SourceMapper::insert()` applies no
encryption hook.

This is an acknowledged security gap. The chain B/C migration to OpenRegister
storage does not change the security posture (chain B copies verbatim, per
the audit-resolved chain-B encryption requirement; chain C adds explicit
`EncryptionService::decrypt()` call sites in controllers at credential read
boundaries — those call sites point at a class that does not yet exist).

The audit at `/tmp/audit-2026-05-20.md` (MISSING-ADR-5) flagged the absence
of a design ADR for the planned class. Without a design contract:

- Chain C's controller-rewrite tasks add `EncryptionService::decrypt(...)`
  call sites that an implementer cannot test against a real type.
- A future implementer cannot ensure the planned class matches what chain
  C's call sites expect.
- Plaintext-state assertions in chain B's startup gate (per the audit
  resolution) abort if `EncryptionService` is introduced without warning —
  but there is no canonical "what an introduced EncryptionService should
  look like" pinned anywhere.

This ADR specifies the planned API and design intent so:
1. Chain C's call sites have a target shape to code against.
2. Chain B's startup-gate assertion has a documented "expected post-encryption
   shape" to migrate to.
3. The eventual implementer ships against a known contract rather than
   inventing one.

The implementation itself is a separate follow-up change (not chain A/B/C/D1/D2/E).
This ADR does NOT mandate when encryption is added; it only pins what it MUST
look like when it is.

## Decision

Once an `OCA\Integriq\Service\EncryptionService` class is introduced,
it MUST conform to the following design:

### API surface

```php
namespace OCA\Integriq\Service;

use OCP\Security\ICrypto;

final class EncryptionService
{
    public function __construct(
        private readonly ICrypto $crypto,
        private readonly \OCP\IConfig $config
    ) {}

    public function encrypt(string $plaintext): string;

    public function decrypt(string $ciphertext): string;
}
```

- The class is `final` (no subclassing — encryption is a security boundary,
  not an extension point).
- Constructor dependencies: Nextcloud's `\OCP\Security\ICrypto` (provides
  AES-256-GCM via OpenSSL with a Nextcloud-managed key) and `\OCP\IConfig`
  (to read an integriq-scoped salt from app-config if added).
- Two methods only: `encrypt(string): string` returns ciphertext;
  `decrypt(string): string` returns plaintext.
- Both methods MUST be synchronous (no futures, no streaming) — the
  payloads are short credential strings (< 4 KB typical, < 64 KB worst case).
- Both methods MUST throw `\RuntimeException` on cryptographic failure
  (key rotation issues, corrupted ciphertext). No silent fallback to
  plaintext. No partial-result returns.

### Encryption layer placement

- Encryption is **column-level**, not storage-level. Specifically:
  - **Read path**: controllers explicitly call `$this->encryptionService->decrypt(...)` on credential fields before returning them to the caller (chain C's pattern).
  - **Write path**: controllers explicitly call `$this->encryptionService->encrypt(...)` on credential fields before persisting via `ObjectService::saveObject(...)`.
- Entity-setter hooks (`Source::setApikey()` applying encryption transparently)
  are explicitly **rejected** as a design — they couple persistence to
  encryption invisibly and break the chain C "every service injects
  ObjectService directly" model.
- Storage-level encryption (encrypting the whole OR object JSON body via
  OR-side hooks) is explicitly **rejected** as a design — it would make
  the OR archival, search, and indexing layers blind to credential fields,
  which breaks log retention sweeps and audit-trail queries.

### Algorithm + key management

- Algorithm: AES-256-GCM. Provided by Nextcloud's `\OCP\Security\ICrypto`
  abstraction; this ADR does NOT pin a lower-level crypto library — if
  Nextcloud's `ICrypto` switches algorithm in a future release, the
  EncryptionService inherits the new algorithm transparently.
- Key source: Nextcloud's `secret` instance config value (the same key
  Nextcloud uses for app-config encryption). No integriq-specific
  key vault. Rationale: zero new key-management surface; encrypted
  credentials remain readable across integriq restarts and
  in-place upgrades; key rotation is a Nextcloud-level operation (not an
  integriq-level operation).
- An integriq-scoped salt MAY be added (via app-config
  `openconnector.encryption_salt`) to namespace ciphertexts so that
  decrypting an integriq-encrypted value with another app's
  `ICrypto` instance fails. Defaults to no salt if the app-config key
  is unset; existing ciphertexts MUST decrypt successfully whether or
  not the salt was set when they were encrypted (lazy salt adoption).

### Migration from plaintext

When this class ships, all existing plaintext credentials in OR storage
MUST be migrated to ciphertext via a one-shot OCC command:

```
occ integriq:encrypt-credentials [--dry-run] [--batch-size=N]
```

- Idempotent: re-running on already-encrypted columns is a no-op (detection
  via a `enc:` prefix or via attempt-to-decrypt-and-rollback).
- Resumable: state tracked in IAppConfig `openconnector.encryption_migrated`.
- Audited: emits one CallLog summary entry on completion (per
  [ADR-003](adr-003-calllog-primary-observability-surface.md)).

Chain B's startup-gate scenario "Encryption-was-introduced-since-this-spec
aborts" (see `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md`)
must be updated when this class ships — the gate flips from "abort if
EncryptionService present" to "abort if migration has not run".

## Consequences

- **For chain C**: the controller-rewrite tasks that add
  `$this->encryptionService->decrypt(...)` calls now have a documented
  target. Implementers can stub `EncryptionService` (return input unchanged)
  for local development if the class hasn't shipped, or inject a real
  instance via Nextcloud's DI container once it has.
- **For chain B**: the plaintext-state startup-gate assertion remains
  correct against the current codebase. When `EncryptionService` ships,
  the gate is reversed (abort if NOT migrated).
- **For integriq security posture**: this ADR does NOT improve
  security on its own — the implementation is a separate follow-up change.
  But it removes the design ambiguity that would otherwise produce
  inconsistent encryption surfaces across services and controllers.
- **For Nextcloud upgrade compatibility**: depending on `\OCP\Security\ICrypto`
  (a stable Nextcloud public API) means integriq inherits any
  algorithm changes Nextcloud makes without code changes here.
- **For key rotation**: handled at the Nextcloud level (rotate `secret`
  config + re-run a one-shot re-encryption command). Out of scope for
  this ADR.
- **For multi-tenant scenarios**: not addressed. If integriq ever
  needs per-tenant encryption keys (different keys per Nextcloud group
  or per user), this ADR must be revised.

## Evidence

- Current plaintext state: see [ADR-007](adr-007-source-credentials-stored-plaintext-pending-encryption.md)
- Chain C call-site introduction: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md` — search for `EncryptionService::decrypt`
- Chain B plaintext-state assertion: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md` (REQ "Credential columns on Source MUST be copied verbatim during migration")
- Audit MISSING-ADR-5 rationale: `/tmp/audit-2026-05-20.md` line 451-453
- Nextcloud's `ICrypto`: `\OCP\Security\ICrypto` (Nextcloud public API,
  documented at `https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/security.html`)
- `\OCP\IConfig::getSystemValue('secret')`: standard Nextcloud instance
  secret, used by Nextcloud's own app-config encryption

## Related

- [ADR-007](adr-007-source-credentials-stored-plaintext-pending-encryption.md) — current plaintext state
- [ADR-003](adr-003-calllog-primary-observability-surface.md) — CallLog audit pattern referenced by the migration command
- Chain B (`openspec/changes/openconnector-register-storage/`) — startup-gate assertion that flips when this class ships
- Chain C (`openspec/changes/openconnector-services-direct-or-usage/`) — adds the call sites that target this design
