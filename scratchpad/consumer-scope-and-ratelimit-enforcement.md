# consumer-scope-and-ratelimit-enforcement — report

Tracked on ocon#210. PRs: **#217** (apply) + **#218** (archive), both admin-merged to `development`.

## Verify-first verdicts (against HEAD `cdb84571`, before any fix)

| Audited hole | Verdict | Evidence |
|---|---|---|
| 1. Rate limit bypassable by choosing apiKey auth | **FALSE — already fixed** | `authorizeApiKey()` resolves + records the consumer (`AuthorizationService.php:699`), mirroring the JWT path at `:416`. Shipped by ocon#188 (`archive/2026-07-14-consumer-apikey-enforcement`). `EndpointService::enforceInboundRateLimit()` keys on `getResolvedConsumer()` and is auth-type agnostic. `AuthorizationServiceApiKeyTest:145` already asserted the resolution. **The brief described the pre-#188 state.** |
| 2. `domains`/`ips` are fabricated scope controls | **TRUE — live** | Only occurrences in the repo were the 2024 migration columns (`Version0Date20240826193657.php:259,261`). **Zero readers in `lib/`.** The spec even documented "unlisted domain is forbidden → HTTP 403" with no implementing code — orphaned-capability defect class. Checked for a superseding enforcement point (middleware, rule action, SecurityService): none exists. |

The `null`-consumer cases are *not* consumers: rule-inline `keys`, `basic`, `oauth` authenticate a **Nextcloud user** against the endpoint's own user/group lists. No consumer record → no consumer `rateLimit` to apply. Rather than invent an anonymous limit with no configuration surface, that boundary is now explicit in `REQ-CON-RL-002` and pinned by a test.

## What is enforced, and where

`ConsumerScopeService` + `Scope\IpMatcher` + `Scope\ReverseDnsResolver` — single enforcement point, called from `EndpointService::dispatchAfterBeforeRules()` **after** auth resolves a consumer and **before** the rate limiter → 403 `source_not_allowed` without spending rate-limit budget. Fails closed.

- Absent `ips`/`domains` = unrestricted (backwards compatible — all consumers today).
- Empty-but-present = matches nothing = **rejects**. Never allow-all.
- `ips` + `domains` = **union**; malformed entries skipped (never wildcards); IPv4 never cross-matches IPv6.
- Skipped on the resume-from-approval path (original request already passed; no live client IP).

## Client-IP / proxy decision and its limits

**`IRequest::getRemoteAddress()` only.** NC core resolves it against `trusted_proxies` / `forwarded_for_headers`, so a forwarded header is honoured only from a proxy the admin trusts. The trusted-proxy story **is** resolvable — NC core already owns it; delegating beats any local re-derivation (also ADR-022).

Deliberately **NOT** `SecurityService::getClientIpAddress()` (`SecurityService.php:544`), which walks `HTTP_CF_CONNECTING_IP` / `HTTP_X_FORWARDED_FOR` / `HTTP_X_REAL_IP` with **no trusted-proxy check at all**. Keying an allowlist on that = spoofable = another fabricated control.

`domains` matched on **forward-confirmed reverse DNS** (PTR → forward-resolve → must return the same IP), never `Origin`/`Referer`/`Host`.

**Limits, documented in the schema descriptions and design.md:**
- Without `trusted_proxies` configured, `getRemoteAddress()` returns the *proxy's* address → the allowlist over-rejects. **Fails closed, never open, never spoofable.** Fixing it is instance-level admin config, outside this app's authority.
- FCrDNS needs the caller's operator to publish correct PTR records; many cloud egress IPs don't. `ips` is the practical field. DNS results cached 300s — also the window a revoked delegation stays honoured. Resolver outage → no confirmed hostname → reject (correct for a security control).

## Bad-path test results (all pass — 17 new tests)

| Bad path | Result |
|---|---|
| apiKey consumer over its rate limit | **429**, keyed `consumer:consumer-uuid-1` — not unlimited |
| Request from IP outside a configured allowlist | **403** |
| Confirmed hostname outside configured `domains` | **403** |
| Empty-but-configured allowlist (`ips:[]`, `domains:[]`, both) | **403** — does not allow-all |
| Consumer with no allowlist (regression guard) | **allowed** |

Extra guards: forged `X-Forwarded-For` naming an allowed IP → **403**; unconfirmed PTR claiming a listed hostname → **403**; IPv4↔IPv6 cross-match → **403**; malformed entries not treated as wildcards; the dispatch path actually reaches the gate and 403s *before* the limiter runs (guards against re-orphaning the capability).

## Baseline + delta (real output, `oc-phpunit-83:local`, fresh composer install)

- Baseline, pristine `origin/development` worktree: **1677 tests, 4650 assertions, OK** (1 pre-existing PHPUnit deprecation)
- This branch: **1694 tests, 4690 assertions, OK** (+17, same 1 deprecation)
- Hydra gates: **39/39 GREEN**. psalm + phpstan clean. phpcs/phpmd clean on every touched file.

> `composer check:strict` is **already red on pristine `origin/development`** — phpcs exit=2, phpmd exit=2, verified against a pristine worktree, not assumed. All in files this change never touches (`SynchronizationService` NPath 17280, `UserService`, `Twig/*RuntimeLoader` missing `@spec`). Separate cleanup; not folded into a security fix.

## Pre-existing issues fixed along the way

- 8 `@spec` anchors in `EndpointService` pointing at the evaporated `hitl-approval-rule-action` change dir (canonical home is `openspec/specs/`)
- 5 anchors from #216 whose slugs don't match the gate's slugify (`execution_trace`→`execution-trace`, `entry-points`→`entry-point-s`, `call_log`→`call-log`)
- 2 REQ-CON-002 scenarios missing `@e2e`
- phpmd `ExcessiveClassComplexity` on the new service — fixed by splitting `IpMatcher`/`ReverseDnsResolver` out (the class was doing three jobs), not by baselining a new security file

## Deliberate non-changes

- **No i18n.** Only new strings are schema descriptions (rendered by the generic schema-driven renderer, which doesn't consume openconnector's l10n — hence `Rate Limit`/`Allowed Domains` have no keys today) and one API error code, untranslated like `subscription_required`. Adding keys = dead weight.
- **No seed data.** Schema already carries `domains`/`ips`; only descriptions changed. No migration: absent = unrestricted, so no live consumer changes behaviour unless an operator had already populated an allowlist — in which case enforcement is the intent.
- **ADR-031**: service class is the documented exception — OR's declarative engine can't express request-path admission control on transport facts. Configuration stays declarative on the `consumer` schema.

## Risk called out

Operators who populated `ips`/`domains` **as documentation** will now get 403s. That is the control working as advertised, but it is a live behaviour change on the fleet's gateway. Mitigated: rejections logged with consumer uuid + client IP, body names `source_not_allowed`, schema descriptions state the semantics.

## What remains on #210

Hole 1 needs no work (already fixed by #188; now regression-pinned). Nothing else from this change's scope is outstanding.
