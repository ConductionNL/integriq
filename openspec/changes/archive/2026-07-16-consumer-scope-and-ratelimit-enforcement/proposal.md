---
kind: code
depends_on: []
---

# openconnector — enforce Consumer source scope (`ips`/`domains`) on inbound endpoint requests

## Why

This change comes out of the `ocon#210` done-spec audit, which flagged two
suspected LIVE holes in the fleet's API gateway. **Both were verified against
HEAD first; only one is real.**

### Hole 1 — "rate limit bypassable by choosing apiKey auth" — NOT REPRODUCIBLE

The audit claimed `resolvedConsumer` is set only on the JWT path, so an apiKey
caller resolves no consumer and is never throttled. **Verified false at HEAD**
(`cdb84571`): the archived change `2026-07-14-consumer-apikey-enforcement`
(ocon#188, merged) already made the apiKey path Consumer-backed —
`AuthorizationService::authorizeApiKey()` resolves the consumer via
`resolveConsumerByApiKey()` and records it (`AuthorizationService.php:699`),
exactly as the JWT path does at `:416`. `EndpointService::enforceInboundRateLimit()`
keys off `getResolvedConsumer()` and is auth-type agnostic, so an apiKey consumer
is throttled identically to a JWT one. `AuthorizationServiceApiKeyTest` already
asserts the resolution. The audit brief described the **pre-#188** state.

The remaining `null` cases are *not* consumers: the rule-inline `keys` map, and
`basic`/`oauth`, authenticate a **Nextcloud user** against the endpoint's own
user/group lists. There is no consumer record — and therefore no consumer
`rateLimit` — to apply. Rather than invent an undocumented anonymous limit, this
change makes that boundary explicit in the spec (see `REQ-CON-RL-002` delta) and
adds a regression test pinning that an apiKey-resolved consumer over its limit is
throttled, so the bypass cannot regress silently.

### Hole 2 — "`domains`/`ips` are fabricated scope controls" — CONFIRMED

The `consumer` schema advertises `domains` ("Allowed source domains") and `ips`
("Allowed source IP addresses"), and `consumer-management` even documents the
scenario **"unlisted domain is forbidden → HTTP 403"**. Verified at HEAD:
**nothing in `lib/` reads either field.** The only occurrences are the 2024
migration that adds the columns (`Version0Date20240826193657.php:259,261`).

This is the fleet's recurring **orphaned-capability** defect class, in its most
dangerous form: an operator configures an IP allowlist, the UI accepts it, the
spec promises a 403 — and every request is allowed. A security control that is
advertised but inert is worse than an absent one, because it is *relied upon*.

## What Changes

- **NEW** `ConsumerScopeService` — the single enforcement point for a consumer's
  source allowlist. Fails closed.
- **MODIFIED** `EndpointService` — the dispatch path consults the scope gate after
  authentication resolves a consumer and **before** the inbound rate limiter, so an
  out-of-scope caller receives 403 without spending (or learning) rate-limit budget.
- **MODIFIED** `consumer` register schema — `domains`/`ips` descriptions now state
  the enforced semantics (matching source, empty-vs-absent, proxy caveat) instead
  of a bare promise.
- **NEW** `REQ-CON-SCOPE-001` + spec delta on `REQ-CON-001`/`REQ-CON-RL-002`.

## Impact

- Backwards compatible: a consumer with **no** allowlist configured is
  unrestricted, which is every consumer that exists today (the fields were inert,
  so any value present was never load-bearing).
- **Behaviour change, intended**: a consumer that *does* have `ips`/`domains`
  configured will now actually reject unlisted sources. An operator who populated
  these fields expecting enforcement gets it; one who populated them as
  documentation may see 403s — this is the control working, and it is called out
  in the schema descriptions.
- An **empty-but-present** list rejects everything rather than allowing everything.
