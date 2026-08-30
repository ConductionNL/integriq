# Design — Retrofit authorization-jwt

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`lib/Service/AuthorizationService.php` is openconnector's authorization handler for
incoming endpoint requests. It supports four authorization schemes plus CORS header
injection, all in one class. Endpoint rules in the rule pipeline (cluster
`rule-pipeline`, REQ retro-spec pending) decide which scheme to invoke and supply the
relevant configuration. This spec captures the observed contract of every public
method plus the four private JWT helpers that have non-trivial behaviour worth pinning.

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `authorizeJwt::AlgorithmManager` | lists `HS256` twice, never instantiates `HS512`; `HMAC_ALGORITHMS` lies about HS512 support | high (potential auth-bypass surface inversion) |
| `getJWK::tmpfile` | writes RSA/PSS public key to `/var/tmp/publickey-<microtime><pid>` between `file_put_contents` and `unlink` | low (public key, not private) |
| `findIssuer` | returns first match; no ambiguity check on duplicate consumer names | medium |
| `authorizeBasic` | `$users` / `$groups` allow-list block is commented out; any authenticated NC user passes | medium |
| `authorizeOAuth` | `$users` / `$groups` allow-list block is commented out (same as above) | medium |
| `authorizeOAuth::str_starts_with('Bearer')` | accepts `Bearertoken-no-space` | low |
| `corsAfterController` | echoes any request `Origin` back; no allow-list | by-design but noteworthy |

These are documented in REQ Notes rather than silently fixed via spec text. Any
hardening would land as a separate change against this retrofit baseline.

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `authorizeJwt` + private helpers `findIssuer`, `checkHeaders`, `getJWK`, `validatePayload` |
| REQ-002 | `authorizeBasic` |
| REQ-003 | `authorizeOAuth` |
| REQ-004 | `authorizeApiKey` |
| REQ-005 | `corsAfterController` |

The four private helpers are annotated with the same `@spec` task-1 tag as
`authorizeJwt` because they are only reachable from `authorizeJwt` and their
contracts collapse into REQ-001's payload/signature/header semantics.

## What the spec deliberately does NOT cover

- Constructor wiring (`__construct(IUserManager, IUserSession, ObjectService)`) is
  not specified — it is DI plumbing, not observable behaviour.
- The `HMAC_ALGORITHMS` / `PKCS1_ALGORITHMS` / `PSS_ALGORITHMS` consts are referenced
  in the REQ but not specified as their own REQ — they are private helpers to
  `getJWK` and `checkHeaders`.
- Rate limiting, retry semantics, audit logging — not in this class today; spec
  does not retroactively mandate them.

## Validation

After archive, `openspec validate authorization-jwt --strict` MUST pass and Specter
MUST register the spec as part of the retrofit cohort.
