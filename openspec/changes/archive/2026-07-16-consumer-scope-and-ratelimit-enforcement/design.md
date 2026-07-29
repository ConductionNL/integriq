# Design — consumer source-scope enforcement

## Verify-first findings (against HEAD `cdb84571`)

| Audited hole | Verdict | Evidence |
|---|---|---|
| Rate limit bypassable via apiKey auth | **FALSE — already fixed** | `authorizeApiKey()` resolves + records the consumer (`AuthorizationService.php:699`), mirroring JWT at `:416`; shipped by ocon#188 (`archive/2026-07-14-consumer-apikey-enforcement`). `enforceInboundRateLimit()` keys on `getResolvedConsumer()` and is auth-type agnostic. |
| `domains`/`ips` are fabricated controls | **TRUE — live** | Only hits in the repo are the 2024 migration columns (`Version0Date20240826193657.php:259,261`). Zero readers in `lib/`. The spec's "unlisted domain → 403" scenario had no implementation. |

The audit's superseding-enforcement check was run for hole 2 as well: there is no
other gate (no middleware, no rule action, no `SecurityService` path) that
consults `domains`/`ips`. This change adds the *first* enforcement point rather
than a duplicate one.

## Decision 1 — Client IP derivation: `IRequest::getRemoteAddress()`, nothing else

This is the security-critical decision. Two candidate sources existed in-repo:

1. `IRequest::getRemoteAddress()` — Nextcloud core resolves this against the
   instance's `trusted_proxies` + `forwarded_for_headers` config. A forwarded
   header is honoured **only** when the request arrives from a proxy the admin
   explicitly trusts.
2. `SecurityService::getClientIpAddress()` (`SecurityService.php:544`) — walks
   `HTTP_CF_CONNECTING_IP`, `HTTP_X_FORWARDED_FOR`, `HTTP_X_REAL_IP`, … and
   returns the first present, **with no trusted-proxy check at all**.

**We use (1) exclusively.** Deriving an allowlist from (2) would let any caller
set `X-Forwarded-For: <allowed-ip>` and walk straight through — replacing one
fabricated control with another, subtler one. `ConsumerScopeService` never reads
a request header. A regression test
(`testForwardedHeaderCannotSpoofTheAllowlist`) pins this: a mocked request whose
`getHeader()` returns an allowed IP but whose `getRemoteAddress()` is the real
(unlisted) address is rejected.

**The trusted-proxy story is resolvable, and here it is.** We do not need to
solve it ourselves — NC core already owns it, and delegating is strictly more
correct than any local re-derivation (also ADR-022: consume the abstraction).

**Documented limit:** on a deployment where the admin has *not* configured
`trusted_proxies`, `getRemoteAddress()` returns the **reverse proxy's** address,
not the origin client's. The allowlist then has to name the proxy, which makes it
useless for distinguishing callers — but it **fails closed** (over-rejects), never
open, and it is never spoofable. That trade is stated in the `ips` schema
description so an operator configuring the field sees it. Fixing that case means
configuring `trusted_proxies`, which is an instance-level admin concern outside
this app's authority.

## Decision 2 — `domains` is matched on forward-confirmed reverse DNS, not headers

An inbound HTTP request has no trustworthy "source domain". The tempting
candidates — `Origin`, `Referer`, `Host` — are all caller-controlled; any
non-browser client sets them freely. Enforcing `domains` against them would ship a
control that looks real and stops nobody: precisely the defect this change exists
to remove.

We therefore match `domains` against **forward-confirmed reverse DNS (FCrDNS)** of
the client IP: PTR-resolve the IP to a hostname, then forward-resolve that
hostname and require it to resolve **back to the same IP**. A hostile network can
set its own PTR to `api.partner.example`, but it cannot make that name
forward-resolve to its address. `testUnconfirmedReverseDnsIsRejected` pins this.

**Documented limits:** FCrDNS requires the caller's operator to publish correct
PTR records — many cloud egress IPs do not, so `ips` is the more practical field
and `domains` is best used where the partner controls its reverse DNS. Results are
cached in the distributed cache (300s) to keep DNS off the hot path; that TTL is
also the window in which a revoked DNS delegation stays honoured.

## Decision 3 — Absent = unrestricted; empty = rejects; both lists = union

- Neither field an array (absent/null) → **unrestricted**. Backwards compatible,
  and the only safe reading: the fields were inert, so no existing consumer's
  value was ever load-bearing.
- Either field present → the source MUST match ≥1 entry across the **union** of
  both. Union (rather than AND) because they are two ways of naming an allowed
  source, not two independent conditions.
- An **empty-but-present** list contributes zero entries and therefore matches
  nothing: `ips: []` rejects everything. Explicitly required by the audit, and it
  is the fail-closed reading — "I configured an allowlist with nothing on it"
  cannot mean "allow all".
- Malformed entries (non-string, blank, unparseable CIDR) are **skipped**, never
  treated as wildcards (`testMalformedAllowlistEntriesAreIgnoredNotAllowAll`).
- IPv4 and IPv6 never cross-match, so `::/0` does not silently admit IPv4.

## Decision 4 — Gate placement: after auth, before the rate limiter

`enforceConsumerScope()` runs at the top of the `enforceRateLimit` block in
`dispatchAfterBeforeRules()`. Ordering rationale:

- **After authentication** — the scope check needs a resolved consumer, and an
  unauthenticated caller must get 401/403 from auth, never a scope 403 that leaks
  whether a consumer exists.
- **Before the rate limiter** — an out-of-scope caller must not consume the
  consumer's rate-limit budget, nor receive `RateLimit-*` headers describing it.
- **Skipped on the resume-from-approval path** (`enforceRateLimit: false`) for the
  same reason the rate limit is: the original request already passed scope before
  suspending, and the resumed request has no live client IP.

No resolved consumer → no allowlist to apply → proceed (the rule-inline/basic/oauth
NC-user paths, unchanged).

## Decision 5 — A service class, not schema-declarative (ADR-031)

ADR-031 says prefer `x-openregister-*` schema metadata over PHP service classes
when OR can express the behaviour. It cannot here: this is a **request-path**
check on transport-layer facts (client IP, DNS) inside OpenConnector's gateway,
with no OR object being read or written at decision time. OR's declarative engine
covers object lifecycle/calculations/notifications, not inbound HTTP admission
control. A service class is the deliberate, documented exception — the ADR-031
"when OR can express it" precondition is simply not met. The *configuration*
(`ips`/`domains`) does stay declarative, on the `consumer` schema, per the ADR's
spirit.

## Seed Data

**None.** This change adds no register objects, no `x-openregister-seed` entries,
and no fixtures. The `consumer` schema already carries `domains`/`ips` (2024
migration + register declaration); this change only rewrites their `description`
text to state the now-enforced semantics. No data migration is needed: existing
consumers have these fields absent or ignored, and absent = unrestricted, so no
live consumer changes behaviour on deploy unless an operator had *already*
populated an allowlist — in which case enforcement is the intent.

## Risks

- **Operators who populated `ips`/`domains` as documentation** will start getting
  403s. This is the control working as advertised, but it is a live behaviour
  change on a gateway. Mitigated by: rejections are logged with the consumer uuid
  and client IP (`ConsumerScopeService::isAllowed()` warn), the 403 body names
  `source_not_allowed`, and the schema descriptions state the semantics.
- **A misconfigured trusted-proxy deployment** may see blanket 403s once an
  allowlist is set (Decision 1's documented limit). Fails closed, is diagnosable
  from the logged client IP, and is fixed by configuring `trusted_proxies`.
- **DNS dependency for `domains`** — a resolver outage makes FCrDNS return no
  confirmed hostname, so a domains-only consumer is rejected. Fail-closed is the
  correct posture for a security control; `ips` avoids the dependency entirely.
