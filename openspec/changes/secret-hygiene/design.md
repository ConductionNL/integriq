# Design: secret-hygiene

## Architecture Overview
Three independent secret-handling surfaces exist in OpenConnector today, each
implementing its own idea of "what looks like a secret":

1. **`CallService`** (outbound HTTP dispatch + CallLog persistence) — already
   redacts secrets from the persisted `call_log` object via
   `redactSecretsFromConfig()` / `collectSecretValues()` /
   `redactSecretValuesFromString()` / `isSecretKeyName()` (commit `8b6d6a27`,
   #1013). Certificate/private-key temp files are already written with
   unpredictable `tempnam()` names + `chmod(0600)` and cleaned up on every
   dispatch path, including the async promise-settle path (commit `b0a5ef8a`,
   #1011/#1012).
2. **`AuthenticationService::getRSJWK()`** (JWT-bearer client-assertion private
   key materialisation) — independently reimplements the same `tempnam()` +
   `chmod(0600)` + `try/finally unlink` pattern (also part of `b0a5ef8a`'s
   fix wave, verified present at HEAD).
3. **`ConfigurationHandlers/*Handler::export()`** (configuration export/import)
   — `SourceHandler` hand-rolls its own fixed field-name `unset()` list plus a
   `str_contains` substring check on `headers.*` keys; the other five handlers
   (`EndpointHandler`, `MappingHandler`, `RuleHandler`, `JobHandler`,
   `SynchronizationHandler`) perform no redaction at all.

Surface 1 and 2 are functionally correct today but untested against
regression — a future refactor could silently reintroduce the world-readable
temp file or the plaintext CallLog without any test failing. Surface 3 has a
real, open gap: five of six entity types leak whatever is in their
`configuration` array verbatim on export, including any inline per-entity auth
override.

This change extracts the secret-name-detection logic that `CallService`
already proved out into one shared, dependency-injectable registry class, and
wires it into (a) the existing `CallService` call sites (pure refactor, same
behaviour) and (b) all six `ConfigurationHandler::export()` implementations
(new behaviour — closes the gap). It also adds the regression tests that
Surfaces 1–3 all currently lack.

## Goals / Non-Goals

**Goals:**
- One source of truth for "does this field/header/param name look like a
  secret" — no more divergent detection logic in `CallService` vs.
  `ConfigurationHandlers`.
- Every `ConfigurationHandler::export()` redacts secret-shaped values from the
  entity's `configuration` array (nested, matching `RuleHandler`'s existing
  nested-walk pattern for id/slug translation).
- Explicit regression tests for all three secret-hygiene surfaces (CallLog
  redaction, config-export redaction, temp-file permissions/cleanup), so a
  future change that reintroduces any of these leaks fails CI.

**Non-Goals:**
- Moving Source credentials out of OpenConnector storage (that is the
  `source-broker-credentials` change).
- Changing where temp files are written (`sys_get_temp_dir()` stays; see
  proposal Open Questions).
- Encrypting stored secrets or CallLog values — redaction in this change is
  irreversible masking (`***REDACTED***`), never encryption. Reversible
  storage of live credentials is out of scope and would need its own security
  review.
- Rewriting `redactSecretsFromConfig()`'s existing behaviour in `CallService` —
  it is correct; this change extracts its detection logic, it does not change
  what gets redacted or how.

## Decisions

### Decision 1: Redaction happens at the service-layer persistence/export boundary, never at the controller
Both `CallLog` redaction and configuration-export redaction MUST happen inside
the service method that builds the object about to be persisted or returned
(`CallService::buildResponseData()` for CallLog — already true;
`ConfigurationHandler::export()` for config export — the change point here),
not in a controller-level filter.

**Rationale:** `configuration-export-import`'s own retrofit spec records that
credential exposure via a full-object read (bypassing the export path
entirely) is a known bypass class in this codebase (see
`reference_or-searchobjectsbyslug-returns-objects.md` project-memory precedent
and ADR-007's "Source credentials stored plaintext" framing). Redacting only
at a controller/serializer boundary would leave every other code path that
touches an exported array (background jobs, CLI export commands, future API
consumers of `ConfigurationService`) unprotected. Redacting inside
`export()` itself means every caller of the handler gets the guarantee for
free, with no way to accidentally bypass it by calling the service method
directly instead of going through a controller.

**Alternative considered:** Redact in `ConfigurationService::exportConfiguration()`
/`exportRegister()` (the two orchestrating entry points) rather than in each
handler's `export()`. Rejected because `ConfigurationService` calls
`$this->handlers[$type]->export(...)` polymorphically without inspecting the
returned array's shape per type — pushing a single generic "redact anything
that looks secret" pass over the whole merged `components` tree there would
require re-parsing entity-specific structure it doesn't otherwise know
(e.g., distinguishing `configuration` payloads from slug/id fields that
legitimately need dot-suffix matching like `sourceId`). Per-handler redaction
keeps the type-specific knowledge (which fields are actually
`configuration`-shaped vs. structural) where it already lives.

### Decision 2: One shared sensitive-field registry, not two independent copies
A single class (`lib/Service/Security/SensitiveFieldRegistry.php`) owns:
- the secret-header-name exact-match list (`authorization`,
  `proxy-authorization`, `cookie`, `set-cookie`) — lifted verbatim from
  `CallService::redactSecretsFromConfig()`,
- the secret-name regex pattern — lifted verbatim from
  `CallService::isSecretKeyName()`
  (`/(token|key|secret|password|passwd|apikey|api[-_]?key|access[-_]?token|bearer|auth|signature|assertion|private[-_]?key|x[-_]?api[-_]?token|client[-_]?secret)/i`),
- the exact-field denylist already enumerated by `SourceHandler::export()`
  (`authorizationHeader`, `auth`, `authenticationConfig`,
  `authorizationPassthroughMethod`, `jwt`, `jwtId`, `secret`, `username`,
  `password`, `apikey`) — folded in as a supplementary exact-match set so
  fields that don't match the regex (e.g. a bare `username` field, which is
  not secret-shaped by pattern but is sensitive in the Source context) keep
  being caught,
- one public method, `isSensitiveName(string $name): bool`, and one
  convenience method, `redactArray(array $data, ?array $extraExactNames = null): array`,
  that walks an array (including nested arrays, mirroring `RuleHandler`'s
  existing nested-walk approach) and replaces every value whose key matches
  either the pattern or an exact-match name with `***REDACTED***`.

`CallService` is refactored so `isSecretKeyName()` becomes a thin delegate to
`SensitiveFieldRegistry::isSensitiveName()` (behaviour-preserving — same
regex, same header list). Every `ConfigurationHandler::export()` gains a call
to `SensitiveFieldRegistry::redactArray()` on the entity's `configuration`
sub-array before returning.

**Rationale:** the design brief for this change explicitly calls for "a shared
sensitive-field registry, single source used by both CallLog redaction and
ConfigurationHandlers." Two independently maintained secret-detection regexes
is exactly the kind of drift that let the config-export gap persist for two
release cycles after the CallLog fix shipped — a header name added to one
list has no reason to also land in the other unless there is only one list.

**Alternative considered:** a static/global constant array instead of an
injectable service class. Rejected — `SourceHandler` and its siblings are
already constructor-injected (`OrObjectService $orObjectService`), and DI-based
tests need to be able to substitute a `SensitiveFieldRegistry` fixture in
principle (even though this change doesn't require that for the shared
registry, a static class would foreclose it for no benefit). A stateless
service class costs nothing extra to inject.

### Decision 3: Config-export redaction is irreversible masking, exactly like CallLog redaction
`SensitiveFieldRegistry::redactArray()` replaces matched values with the
literal string `***REDACTED***` — the same placeholder `CallService` already
uses — never a reversible transform (no encryption, no hashing that a caller
could later "un-redact"). An operator re-importing an exported configuration
gets the same UX `SourceHandler` already establishes for Sources: the
credential field exists in the imported entity but is empty/placeholder, and
the operator must re-enter it in the target environment.

**Rationale:** the proposal's constraint is explicit ("Redaction must be
irreversible in stored logs (masking, not encryption)") and this section
extends the same constraint to config export, since an exported document can
be handed to a less-trusted party (e.g. committed to a shared repo, emailed to
a partner integrator) exactly as CallLogs can be read by a less-privileged
viewer.

**Alternative considered:** omitting the field entirely (as `SourceHandler`
does for its exact-match denylist today) rather than replacing it with a
placeholder. Rejected as the uniform behaviour for the new nested-`configuration`
redaction — omission is indistinguishable from "this field was never set,"
which loses the operator-facing signal ("a credential lived here, re-enter
it") that `***REDACTED***` preserves. The existing exact-match top-level
fields (`apikey`, `password`, etc.) keep using `unset()` for backward
compatibility with `SourceHandler`'s current export shape (changing a
top-level field from absent-key to present-with-placeholder would be an export
schema change with import-side implications not scoped here); only the new
nested-`configuration` walk uses the placeholder.

## Risks / Trade-offs

- **[Risk] The shared registry's pattern is broader than any single handler's
  current denylist, so previously-exported non-secret fields whose names
  happen to match (e.g. a mapping's `configuration.authProvider` display
  label) get redacted where they weren't before.** → Mitigation: this is an
  intentional fail-closed trade-off consistent with `CallService`'s existing
  posture (which already accepts the same over-matching risk in
  `isSecretKeyName()`). The export-leak regression test suite doubles as a
  false-positive check — if a real fixture starts getting over-redacted,
  the test names the exact field and the pattern can be scoped down
  deliberately rather than silently.
- **[Risk] Extracting `isSecretKeyName()` into a shared class could introduce
  a namespace/DI wiring mistake that breaks CallLog redaction at runtime
  despite passing static analysis.** → Mitigation: the existing
  `testBrokeredCallLogRedactsSecretsLikeGuzzlePath` integration-style test
  already exercises the full `CallService` redaction path end-to-end; it runs
  unmodified against the refactored code, so any wiring break fails that test
  immediately, not just a new unit test of the registry in isolation.
- **[Trade-off] `JobHandler`'s `arguments` field is not walked by the shared
  redaction pass in this change (see proposal Open Questions).** → Accepted:
  research during proposal-writing did not find evidence that job arguments
  structurally carry raw secret values (they carry entity id/slug references
  translated by REQ-004's id↔slug maps), so extending the walk there is
  deferred pending a concrete example, rather than guessed at.

## Migration Plan
No data migration. This is a code-only change (new shared class + handler
edits + tests). Steps:
1. Add `SensitiveFieldRegistry` with unit tests for the pattern/exact-match
   matrix (independent of any call site).
2. Refactor `CallService::isSecretKeyName()` to delegate to the registry;
   re-run the full `CallServiceTest` suite to confirm zero behavioural change.
3. Add `redactArray()` calls to the five previously-unredacted handlers, plus
   refactor `SourceHandler` onto the shared registry for its `configuration`
   sub-array pass (its existing exact-match top-level `unset()` list is
   retained as-is).
4. Add temp-file-permission regression tests for
   `CallService::writeFile()`/`getCertificate()` and
   `AuthenticationService::getRSJWK()`.
5. Add the export-leak regression test that exercises all six entity types
   through `ConfigurationService`.

**Rollback:** revert the commits; no persisted data or schema is touched, so
rollback is a clean `git revert` with no follow-up cleanup (see proposal
Rollback Strategy).

## Open Questions
Carried from the proposal:
- Dedicated chmod-0700 temp subdirectory vs. the current `sys_get_temp_dir()`
  + `tempnam()` + `chmod(0600)` approach — deferred, not blocking.
- Whether `JobHandler::arguments` needs the same redaction walk — deferred
  pending a concrete secret-bearing example.
