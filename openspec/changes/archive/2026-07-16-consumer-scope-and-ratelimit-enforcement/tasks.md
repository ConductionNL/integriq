# Tasks — consumer source-scope enforcement

> Investigate first (done): verified both audited holes against HEAD `cdb84571`.
> Hole 1 (apiKey rate-limit bypass) does NOT reproduce — ocon#188 already made the
> apiKey path Consumer-backed; the brief described the pre-#188 state. Hole 2
> (`domains`/`ips` fabricated) CONFIRMED — zero readers in `lib/`, only the 2024
> migration columns. Work below closes hole 2 and pins hole 1 against regression.

- [x] Verify hole 1 at HEAD: confirm `authorizeApiKey()` resolves + records the
      consumer and that `enforceInboundRateLimit()` is auth-type agnostic
- [x] Verify hole 2 at HEAD: confirm no superseding enforcement point exists for
      `domains`/`ips` anywhere in `lib/`
- [x] Establish the clean baseline from `origin/development` (1629 tests, OK)
- [x] Add `ConsumerScopeService` (SPDX EUPL-1.2) as the single scope enforcement
      point, failing closed
- [x] Derive the client IP from `IRequest::getRemoteAddress()` only; never from a
      caller-controlled header (design Decision 1)
- [x] Match `ips` on exact IPv4/IPv6 + CIDR, with no IPv4/IPv6 cross-matching and
      malformed entries skipped rather than treated as wildcards
- [x] Match `domains` on forward-confirmed reverse DNS, never on Origin/Referer/
      Host (design Decision 2); cache lookups in the distributed cache
- [x] Implement absent = unrestricted, empty-but-present = rejects, ips+domains =
      union (design Decision 3)
- [x] Wire `enforceConsumerScope()` into `EndpointService::dispatchAfterBeforeRules()`
      after auth and before the rate limiter, returning 403 `source_not_allowed`
- [x] Update the `consumer` schema `domains`/`ips` descriptions to state the
      enforced semantics and the trusted-proxy caveat
- [x] Test the bad path: IP outside a configured allowlist is rejected
- [x] Test the bad path: confirmed hostname outside configured `domains` rejected
- [x] Test the bad path: empty-but-present allowlist does NOT allow-all
- [x] Test the bad path: a forged `X-Forwarded-For` cannot satisfy the allowlist
- [x] Test the bad path: an unconfirmed PTR cannot satisfy `domains`
- [x] Test no regression: a consumer with no allowlist still works
- [x] Test the wiring: the dispatch path actually reaches the gate (guard against
      re-orphaning the capability) and 403s before the limiter runs
- [x] Test hole 1 pinned: an apiKey-resolved consumer over its rate limit is
      throttled and keyed on the consumer, not unlimited
- [x] Update the 4 existing `EndpointService` constructions in tests for the new
      constructor arg, defaulting the scope mock to "allowed"
- [x] Run the full suite in `oc-phpunit-83:local` and report baseline + delta
