# Proposal: secret-hygiene

## Summary
This change closes the one remaining credential-leak surface in OpenConnector's
secret-handling pipeline: configuration export/import redacts credentials for
Sources only, leaving five of six `ConfigurationHandler` implementations
(Endpoint, Mapping, Rule, Job, Synchronization) exporting their `configuration`
payloads verbatim with no secret-shaped-value screening. It introduces a single
shared sensitive-field registry, applies it uniformly across every export
handler, and adds the regression tests needed to lock in this fix and the two
related security fixes that HEAD already ships but that remain untested against
regression: CallLog redaction (`CallService::redactSecretsFromConfig` et al.,
merged in commit `8b6d6a27`, #1013) and temp credential/certificate file hygiene
(`CallService::writeFile`/`AuthenticationService::getRSJWK`, merged in commit
`b0a5ef8a`, #1011/#1012).

## Motivation
Specter deep-research insight #1245 flagged three CRITICAL/High findings against
the secret-hygiene surface: CallLog plaintext persistence (#1003, tracked
internally as #1013), configuration-export credential leakage (#964), and
world-readable private-key temp files (#1012). Verifying every claim against
`HEAD` (`e79fcc83`) during this change's research phase found that #1013 and
#1012 were **already fixed** in commits `8b6d6a27` (2026-05-27) and `b0a5ef8a`
(2026-05-27) — both are ancestors of the current `development` HEAD. Their fixes
(`redactSecretsFromConfig`/`collectSecretValues`/`redactSecretValuesFromString`
in `CallService.php`; `tempnam()` + `chmod(0600)` + `try/finally unlink` in
`CallService::writeFile()` and `AuthenticationService::getRSJWK()`) are
functionally sound but have **no dedicated regression tests** — a future change
could silently regress them without any test failing.

The #964 configuration-export finding is real and still open:
`SourceHandler::export()` strips a fixed field list (`authorizationHeader`,
`auth`, `secret`, `password`, `apikey`, `jwt`, header keys matching
`authorization|token|key|secret`), but `EndpointHandler`, `MappingHandler`,
`RuleHandler`, `JobHandler`, and `SynchronizationHandler` perform **zero**
redaction — they only strip `id`/`uuid`. Rules and Endpoints can carry inline
per-entity auth overrides and templated header/body config
(`RuleHandler::configuration`, per the `configuration-export-import` spec's own
REQ-004 notes on nested-key rewriting); any secret value placed in one of those
configs is exported verbatim today. This is the change's real remaining work.

## Affected Projects
- [ ] Project: `openconnector` — shared sensitive-field registry, redaction
  applied to all six `ConfigurationHandler::export()` implementations, and
  regression tests for CallLog redaction, config-export redaction, and
  temp-credential-file permissions/cleanup.

## Scope

### In Scope
1. **Shared sensitive-field registry**: extract `CallService::isSecretKeyName()`
   (currently private) and its associated header-name/pattern list into a single
   shared service (`SecretFieldRegistry` or equivalent), used by both
   `CallService`'s CallLog redaction and a new shared redaction helper consumed
   by every `ConfigurationHandler::export()`.
2. **Configuration-export redaction for all six entity types**: apply the shared
   registry to `EndpointHandler`, `MappingHandler`, `RuleHandler`, `JobHandler`,
   and `SynchronizationHandler` exports (in addition to the existing
   `SourceHandler` coverage, which is refactored onto the shared registry for
   consistency). Redaction walks the entity's `configuration` array (including
   nested keys, matching `RuleHandler`'s existing nested-walk pattern) and masks
   any key matching the shared secret-name pattern, replacing the value with an
   irreversible placeholder — never encrypting or reversibly obfuscating it.
3. **Regression tests locking in the three already-shipped fixes**:
   - Redaction matrix unit tests for the shared registry (every field name
     pattern from #1013/#964 exercised: `authorization`, `cookie`, `api-key`,
     `token`, `password`, `client_secret`, `assertion`, etc.).
   - An export-leak regression test that exports one instance of every one of
     the six entity types (each seeded with a secret-shaped value in its
     `configuration`) and asserts no secret-shaped value survives in the
     exported document.
   - A temp-file permission regression test asserting
     `CallService::writeFile()`-produced cert/key files are mode `0600` and that
     `AuthenticationService::getRSJWK()`-produced private-key temp files are
     mode `0600` and are removed even when `JWKFactory::createFromKeyFile`
     throws.
4. **CallLog redaction regression coverage**: confirm/extend
   `CallServiceTest::testBrokeredCallLogRedactsSecretsLikeGuzzlePath` (or add a
   sibling test) so the non-brokered Guzzle path has an equivalent explicit
   assertion that a CallLog persisted after an authenticated call contains no
   plaintext `Authorization` header value.

### Out of Scope
- The credential broker migration (`source-broker-credentials`, active
  separately) — moving Source secrets out of OpenConnector storage entirely is
  a different change; this change only hardens what is exported/logged while
  secrets remain in OpenConnector.
- SSRF/SSTI/XXE fixes (#1004/#962/#960) — separate security wave, unrelated
  surface.
- Re-deriving or reversing the redaction fixes already shipped in `8b6d6a27`
  and `b0a5ef8a` — this change adds tests and closes the config-export gap, it
  does not rewrite CallLog redaction or temp-cert-file handling.
- Moving the temp-file location to a dedicated chmod-0700 subdirectory (the
  `http-call-engine` spec's Notes flag `sys_get_temp_dir()` as a soft
  follow-up). The current `tempnam()` + unpredictable-name + `chmod(0600)`
  approach already closes the world-readability risk that made this
  CRITICAL; a dedicated subdirectory is deferred as a hardening nice-to-have
  (see Open Questions).

## Approach
Introduce one shared PHP class carrying the sensitive-field detection logic
(regex pattern + explicit header-name list) that `CallService` already proved
out. `CallService` is refactored to delegate to it (behaviour-preserving). Each
`ConfigurationHandler::export()` gains a call to a shared redaction helper
(applied to the entity's `configuration` array, walking nested arrays) before
returning its serialised array. Tests are added at the unit level (redaction
matrix, per-handler export-leak assertions, temp-file permission assertions)
and validated through the existing PHPUnit suite — no new integration/E2E
surface, since this is a pure backend service change (matches the existing
`@e2e exclude` posture of all three affected specs).

## New Dependencies
None.

## Impact
- `lib/Service/CallService.php` — `isSecretKeyName()` extracted to the shared
  registry; call sites updated to delegate (`redactSecretsFromConfig()`,
  `collectSecretValues()`, `redactSecretsFromUrl()` unchanged in behaviour).
- `lib/Service/ConfigurationHandlers/{Endpoint,Mapping,Rule,Job,Synchronization,Source}Handler.php`
  — each gains a redaction pass on `export()`.
- `lib/Service/ConfigurationHandlers/ConfigurationHandlerInterface.php` — no
  signature change; redaction is an internal implementation concern of
  `export()`.
- New shared class under `lib/Service/` (or `lib/Service/Security/`) for the
  sensitive-field registry.
- `tests/Unit/Service/CallServiceTest.php`,
  `tests/Unit/Service/AuthenticationServiceTest.php`,
  `tests/Unit/Service/ConfigurationServiceTest.php`, and new
  `tests/Unit/Service/ConfigurationHandlers/*HandlerTest.php` (or a shared
  redaction-matrix test class) gain the regression coverage described above.

## Cross-Project Dependencies
None. This is fully contained within `openconnector`. The `source-broker-credentials`
change (also open) touches `CallService::call()`'s brokered-dispatch branch but
not the redaction/export/temp-file code paths this change modifies — no
sequencing dependency, but both changes should be reviewed together since they
touch the same file.

## Risks

### Risk 1: A sixth handler's `configuration` shape hides a secret under a key name the shared pattern doesn't match
**Severity:** Medium — **Mitigation:** the shared registry uses the same
pattern already proven against real Source credential fields
(`token|key|secret|password|passwd|apikey|access[-_]?token|bearer|auth|signature|assertion|private[-_]?key|client[-_]?secret`),
extended with an explicit denylist for the fields `SourceHandler` already
enumerates by exact name. The export-leak regression test seeds each entity
type with several differently-named secret fixtures to catch pattern gaps
before merge, and the spec delta documents the pattern as the single source of
truth so future entity fields are reviewed against it.

### Risk 2: Refactoring `CallService::isSecretKeyName()` into a shared class regresses the already-shipped CallLog redaction
**Severity:** Medium — **Mitigation:** the refactor is a pure extraction (same
regex, same logic, no behavioural change); the existing
`testBrokeredCallLogRedactsSecretsLikeGuzzlePath` and the new Guzzle-path
sibling test both run against the refactored code before merge, so any
behavioural drift fails CI immediately.

## Rollback Strategy
Every change in this proposal is additive or a pure refactor of already-shipped
logic (extraction into a shared class; new redaction calls in five handlers;
new test files). Rollback is a straight `git revert` of the change's commits —
no data migration, no schema change, no stored-log reprocessing is introduced,
so reverting cannot leave the system in a partially-migrated state. Previously
exported configuration documents are not retroactively touched.

## Open Questions
- Should the temp-credential-file location move from `sys_get_temp_dir()` to a
  dedicated `chmod(0700)` OpenConnector-owned subdirectory, as the
  `http-call-engine` spec's Notes originally flagged? Deferred here since the
  unpredictable-filename + `0600`-permission approach already closes the
  world-readability risk; revisit if a future audit needs directory-level
  isolation (e.g., to satisfy a specific compliance control).
- Should the shared sensitive-field registry also be applied to `JobHandler`'s
  `arguments` field (distinct from `configuration`) — jobs can carry synchronization
  arguments that are not currently walked by any handler? Flagged for the design
  phase to confirm whether `arguments` can structurally carry secret-shaped
  values in practice.
