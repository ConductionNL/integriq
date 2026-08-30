# Design — Retrofit synchronization-engine

> **Retrofit change.** Tasks describe retroactive annotation, not new
> implementation work. No code behavior is changed by this change — only
> `@spec` annotations are added to existing methods, and the spec delta is
> merged into the main specs on archive.

## Context

The `synchronization-engine` cluster is the largest Bucket 2b cluster in the
openconnector coverage report (97 methods). It centres on
`lib/Service/SynchronizationService.php` (74 methods) plus the REST controllers
and the ADR-019 integration provider. None of these methods carried a `@spec`
tag before this retrofit.

The behavior is anchored on ADR-005's Source → Synchronization →
SynchronizationContract triad: the contract is the per-object change-detection
unit, carrying origin/target ids and hashes.

## Behavioral groupings (5 REQs over 97 methods)

| REQ | Theme | Method count (approx) |
|---|---|---|
| REQ-001 | Sync orchestration & direction routing | 10 |
| REQ-002 | Source fetching & pagination | 17 |
| REQ-003 | Mapping, transformation & object identity | 16 |
| REQ-004 | Target write, dedup & file handling | 19 |
| REQ-005 | Rule pipeline & management/integration surface | 35 |

The groupings follow observable behavior. Where a method serves two themes
(e.g. `processMappingRule()` is both a rule and a mapping step) it is annotated
to the REQ whose observable behavior it most directly implements.

## Observed-but-suspicious behavior (flagged, not fixed)

1. **IDOR on the controller surface (REQ-005, SECURITY).** Every method on both
   sync controllers is `@NoAdminRequired` and resolves its target by an
   arbitrary caller-supplied id with no ownership/admin guard. Any authenticated
   user can run/test/execute/activate/deactivate/export/delete against any id.
   Consistent with the openconnector IDOR finding history. Needs a follow-up
   authorization change (ADR-023 action-level authz).
2. **Silent error-swallow in event-driven sync (REQ-001).**
   `handleObjectEventSynchronization()` catches per-synchronization exceptions
   and continues; the `void` return hides which ones failed.
3. **Silent file-fetch failures (REQ-004).** `fetchFileSafely()` swallows
   exceptions so async batches continue; failures are not surfaced structurally.
4. **Attacker-influenceable file endpoints (REQ-004, SECURITY).**
   `fetchFile()` builds the request endpoint from source config + `{{ originId }}`
   substitution and `base64_decode`s the body — an SSRF/content-handling review
   surface.
5. **Error message leakage (REQ-005).** `processRules()` returns
   `$e->getMessage()` in a 500 `JSONResponse`.
6. **Unimplemented source types (REQ-002).** `register/schema` and `database`
   branches of `getAllObjectsFromSource()` are empty `@todo` no-ops.

None of these are corrected here — a behavioral retrofit specifies what the code
does today. They are recorded so a future change can address them deliberately.
