# Context Brief: secret-hygiene
Source: Specter deep-research 2026-07-14 (insight #1245). VERIFY every code claim against HEAD before writing artifacts.

## Problem (CRITICAL, well-localised security)
- #1003 [CRITICAL]: plaintext secrets (Authorization headers, tokens, passwords) persisted in CallLog request/response snapshots.
- #964: configuration export can leak credentials despite redaction handlers.
- #1012: private key material written to world-readable temp files (verify AuthenticationService inline-certificate materialisation).
Context: HEAD commit e79fcc83 just locked the source schema (credentials readable by any authed user, ocon#147); the source-broker-credentials change (moving secrets to the credential broker) is open but NOT this change.

## In scope
1. Redaction at the CallLog persistence boundary: allowlist/denylist of sensitive header/body/query fields (authorization, cookie, api-key, token, password, client_secret, assertion...) masked BEFORE the log entity is saved (service-layer, not controller-side — a known bypass is direct OpenRegister object reads, so redact at write time).
2. Config export: audit every ConfigurationHandler for credential fields; add a shared redaction utility + regression test that exports every entity type and asserts no secret-shaped values.
3. Temp credential files: 0600 perms, dedicated subdir, guaranteed cleanup (finally/destructor), never world-readable (#1012).
4. Tests: unit redaction matrix; integration proving a CallLog written after an authenticated call contains no secret.
## Out of scope
- Credential broker migration (source-broker-credentials change).
- SSRF/SSTI/XXE fixes (#1004/#962/#960 — separate security wave).

## Constraints
- Redaction must be irreversible in stored logs (masking, not encryption).
- Specs to modify via deltas: http-call-engine (CallLog), configuration-export-import, authentication-twig (temp files).
- hydra security gates will run (security-change-has-tests): every security-relevant change MUST include tests.
