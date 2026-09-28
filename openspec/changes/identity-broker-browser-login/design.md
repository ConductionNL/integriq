# Design: identity-broker-browser-login

Kind: code. Size M. Read at integriq development `966d6458` on 2026-09-28.

## Context

- **What runs today.** `IdpBrokerController` serves only the exchange (`appinfo/routes.php:372`). `EnvelopeExchangeService::issueCode(SubjectEnvelope)` stores a one-time code and `redeemCode(code, consumer, presentedSecret)` answers the signed envelope once; `issueCode()` has no production caller, so every exchange fails.
- **The seam.** `GovernmentIdpAdapterInterface::beginAuthentication(array $context): array` and `readAssertion(array $callback): array` return a redirect and a normalised assertion (`id`, `inResponseTo`, `audience`, `notBefore`, `notOnOrAfter`, `subject`, `subType`, `assuranceLevel`, `organisation`). `LogGovernmentIdpAdapter` throws, which keeps the seam dormant (spec requirement "Dormant seam").
- **The spec.** `openspec/specs/digid-eherkenning-auth-adapter/spec.md` binds the envelope to the organisation and consumer "from the signed initiation `state`" and rejects IdP-initiated flows and audience confusion.
- **Config.** `IdpBrokerConfig` keeps consumers as `idp_broker_consumers` (id to secret) in app config (:68, :128); the admin docs show `occ config:app:set integriq idp_broker_consumers --value '{"portaliq":"<secret>"}'`.
- **portaliq's side.** portaliq `signin-integriq-broker-login` design: its start route sends the browser with organisation, provider, requested trust, consumer id and relay state; its callback redeems the code and checks `audience` equals its consumer id.

## D1. Start

`GET /api/idp/{provider}/start?organisation=&consumer=&trust=&returnUrl=&relayState=` (public page, throttled). It refuses an unknown consumer, a `returnUrl` not on that consumer's allow-list, a disabled broker and an unconfigured adapter, each with integriq's error page and no redirect. It stores a state `{nonce, organisation, consumer, provider, trust, returnUrl, relayState, expiresAt}` (single use, five minutes) signed with the broker signing key, and calls `beginAuthentication()` with the state id; the adapter's answer is the redirect.

## D2. Callback

`GET|POST /api/idp/{provider}/callback`. It reads the assertion through `readAssertion()`, loads and consumes the state named by `inResponseTo`, runs `AssertionGuard` and `EnvelopeReplayGuard`, maps trust through `TrustLevelMapper`, pseudonymises through `SubjectPseudonymService`, mints the envelope with `audience` the state's consumer and `organisation` the state's organisation, calls `issueCode()`, and redirects to the state's `returnUrl` with `code` and `relayState`. Any failure redirects to the same `returnUrl` with `error=login_failed` and the relay state, one reason for every failure, and logs the real reason. An assertion without a state is refused (IdP-initiated).

## D3. Consumers with return addresses

`idp_broker_consumers` becomes `{ id: { secretRef, returnUrls: [..], enabled } }`, with the old id-to-secret form read as a consumer without return addresses (so it can still redeem, and cannot start). The secret moves to a broker reference. `occ integriq:idp:consumer <id> --return-url=... --secret-ref=...` sets one. A disabled `portaliq` entry is seeded without secret or addresses, so enabling it is one command.

## D4. Branch

The normalised assertion gains an optional `branch`; `SubjectEnvelope` gains an optional `branch` claim, written only for `provider` `eherkenning` when the assertion restricts the login to a vestiging, and absent otherwise. `fromClaims()` reads it back. The docs claim table lists it.

## Declarative versus imperative

All imperative: protocol handling and redirects. Configuration stays in app config (ADR-017 Rule 1), as the spec requires.

## Seed data

The disabled `portaliq` consumer. The log adapter gains a scripted mode for tests that answers a fixed assertion, so the start and callback round trip can be tested without a vendor.

## Risks

- [State stored but browser never returns] the state expires after five minutes and is swept with the code store.
