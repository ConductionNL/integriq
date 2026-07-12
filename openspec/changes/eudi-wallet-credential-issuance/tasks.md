# Tasks — OpenID4VCI EUDI wallet credential issuance adapter

> Implementation order follows the design: schema fragment + issuer key
> service first (nothing else can sign or persist without them), then the
> app-facing offer-creation leg (reuses existing auth, lowest risk), then
> the wallet-facing protocol endpoints in protocol order (metadata → offer
> resolution → token → credential → status list), then revocation +
> callback, then the refresh cron, then catalogue/Beheer wiring. Per
> design.md D-ROUTE, no wallet-facing route is registered in
> `appinfo/routes.php` ahead of its real verification logic — DSOController
> is the standing counter-example of what shipping a stub verifier looks
> like in production.

## Register fragment + schemas

- [ ] Confirm the ADR-037 fragment loader merges `components.schemas` (not
  only `components.objects`) into the effective register descriptor
  (design.md DEFERRED_QUESTION) — trace `lib/Settings/register.d/`
  loading code before writing the fragment's final shape
- [ ] Author `lib/Settings/register.d/eudi-wallet-credential-issuance.json`
  declaring `eudi_credential_offer`, `eudi_issuance_session`, and
  `eudi_status_list` schemas (properties per spec.md REQ-EUDI-001/004/005/006/008)
- [ ] If the loader does not support `components.schemas`, fall back to a
  documented one-line addition to `openconnector_register.json` and note
  the deliberate `openconnector-register-schema` REQ-A-002 entry-count
  bump in the fragment's `$comment`

## Issuer key service (Beheer > Authenticatie)

- [ ] `lib/Service/EudiIssuerKeyService.php`: `generateKey(organisationId)`
  (ES256/P-256 via `openssl_pkey_new`), `ICrypto::encrypt()` the private
  key before persisting, store public key + SHA-256 `kid` fingerprint
  plain, mirroring scholiq `KeyManagementService::generateTenantKeypair()`
  line-for-line in shape (not copy-pasted — new class, new app)
- [ ] `rotateKey(organisationId)`: archive current public key (capped at
  32, oldest-pruned-first) before generating the new active key; discard
  the rotated-out private key (never archived)
- [ ] `resolveActiveKey(organisationId)` / `resolvePublicKeyByFingerprint(organisationId, kid)`
  for signing and JWKS-publish/verification lookups respectively
- [ ] Wire organisation scoping through the existing `organisation-bridge`
  soft-fail accessor; fall back to a single default-organisation key when
  it returns null (never an unencrypted key, never a hard failure)
- [ ] `lib/Controller/EudiIssuerKeyAdminController.php`: admin-gated
  generate/rotate/status endpoints, `Beheer > Authenticatie` UI section
  (mirrors `digid-eherkenning-auth-adapter`'s existing UI shape) — returns
  public key + `kid` only, never private key material
- [ ] Unit tests: encrypted-at-rest assertion (no raw PEM in storage),
  rotation archive/prune behaviour, fingerprint-based resolution
  (active + archived), organisation-bridge-null fallback

## App-facing offer creation (consumer-gated)

- [ ] `lib/Service/EudiCredentialOfferService.php::createOffer()`: validate
  `{credentialPayload, format, subjectId, consumerId}`, mint a
  cryptographically random `pre-authorized_code`, persist only its hash
  in a new `eudi_credential_offer` row, build `{offerUrl,
  credentialOfferUri, qrPayload}`
- [ ] `lib/Controller/EudiWalletController.php::createOffer()`:
  `POST /api/eudi/credential-offers`, gated by the existing
  `consumer-management` REQ-CON-001 resolution + `authorization-jwt`
  REQ-001 JWT check (no new auth mechanism) before calling the service;
  HTTP 400 on malformed payload with zero persisted state
- [ ] Unit tests: authenticated success path, unauthenticated 401 with no
  row persisted, malformed-payload 400

## Wallet-facing protocol endpoints

- [ ] `GET /.well-known/openid-credential-issuer`: issuer metadata
  (credential_issuer, credential_endpoint, token_endpoint,
  credential_configurations_supported for at least one `jwt_vc_json`
  (EDCI diploma) and one `dc+sd-jwt` (Open Badges 3.0) configuration,
  active + archived-within-window JWKS) reflecting the resolved
  organisation's key set
- [ ] `GET /api/eudi/credential-offers/{id}`: single-fetch resolution of
  the `credential_offer_uri` target, 15-minute default TTL, atomic
  consume-on-read (second fetch → 404/410, generic message)
- [ ] `POST /api/eudi/token`: pre-authorized_code grant only, atomic
  lookup-and-invalidate against the stored code hash (mirrors
  `AuthorizationService::validatePayload`'s jti-replay shape), `tx_code`
  verification with rate-limiting that does NOT consume the code on a
  wrong PIN, persists `eudi_issuance_session` (access_token hash,
  c_nonce, expiry) on success
- [ ] `POST /api/eudi/credential`: Bearer + `proof.jwt` verification
  (nonce == session's current `c_nonce`, proof not previously seen for
  this session — replay rejection); dispatch by `format`:
  - `jwt_vc_json` → return the stored `credentialPayload` verbatim, no
    re-signing (design.md D-SIGN)
  - `dc+sd-jwt` → mint + sign a fresh SD-JWT VC with
    `EudiIssuerKeyService::resolveActiveKey()`
- [ ] `GET /api/eudi/status-lists/{id}`: OAuth Status List Token
  (`bits: 1`, `purpose: revocation` only), signed with the resolved
  organisation's active issuer key, own `exp`
- [ ] Unit + integration tests per endpoint: happy path, each documented
  failure mode from spec.md (expired/consumed offer, replayed
  pre-authorized_code, wrong tx_code non-consumption, replayed proof,
  format dispatch for both `jwt_vc_json` and `dc+sd-jwt`)
- [ ] Register the four public routes in `appinfo/routes.php`
  (`#[PublicPage]`/`#[NoCSRFRequired]`) — only once their verification
  logic above is real and tested, per design.md D-ROUTE; do not land a
  route ahead of its verification (see `DSOController`'s removed-route
  precedent in the same file)

## Revocation + status callback

- [ ] `EudiCredentialOfferService::revoke()`: consumer-ownership check
  (only the offer's `consumerId` may revoke it → 403 otherwise), flip the
  assigned status-list bit `0 → 1`, idempotent on an already-revoked
  offer (no double-toggle, no error)
- [ ] `POST /api/eudi/credential-offers/{id}/revoke` on
  `EudiWalletController`, same consumer-gating as offer creation
- [ ] Wire the status callback through the existing
  `WebhookSignatureService` (no new signing scheme) —
  `X-OpenConnector-Signature` HMAC over the callback body, delivered to
  the owning consumer's configured callback URL
- [ ] Unit tests: bit-flip + callback delivery, idempotent double-revoke,
  cross-consumer 403 with unchanged bit

## Status list refresh cron

- [ ] `lib/Cron/EudiStatusListRefreshJob.php` (`TimedJob`, mirrors
  `EventRetryJob`'s shape): sweep `eudi_status_list` rows whose token
  `exp` is within the configurable refresh window (default: <25% of
  total validity remaining), re-sign with the current active issuer key,
  preserve bitstring contents
- [ ] Register the job in `appinfo/info.xml`/background-jobs registration
  (existing convention — verify against `EventRetryJob`'s registration
  before adding a second one)
- [ ] Unit test: near-expiry row is refreshed with unchanged bitstring and
  a later `exp`; a rotated issuer key does not invalidate an
  already-published, still-unexpired token, but the next refresh cycle
  signs with the new active key

## Adapters catalogue + IA wiring (ADR-017 Rule 1/3/7)

- [ ] Add an "EUDI Wallet Credential Issuance" card to the Adapters
  catalogue index (domain tag, supported credential configurations,
  no top-level menu item, no per-adapter settings page)
- [ ] Add a step to the *Verbindingen* new-connection wizard for this
  adapter (schema selection: `eudi_credential_offer` /
  `eudi_issuance_session` / `eudi_status_list`)
- [ ] Confirm the *Beheer > Authenticatie* "EUDI issuer key" section
  (built above) is discoverable from the catalogue card per the
  `digid-eherkenning-auth-adapter` sanctioned split — no duplicated
  config surface between the two locations

## Cross-cutting

- [ ] Secret-hygiene audit: confirm the issuer private key never appears
  in an API response, log line, or error message anywhere in the new
  code (grep the diff for the private-key field name after implementation)
- [ ] `composer phpcs` + `composer phpstan`/`psalm` clean on every touched
  file; `composer check:strict` overall
- [ ] Full existing PHPUnit suite green — no regressions from this
  change (net-new endpoints/services, no modification to existing
  request paths)
- [ ] Verify e2e on a dev instance: create an offer via
  `POST /api/eudi/credential-offers` with a fixture `jwt_vc_json`
  payload, resolve the offer, exchange the pre-authorized_code at
  `/api/eudi/token`, fetch the credential at `/api/eudi/credential` and
  confirm it matches the fixture payload byte-for-byte; revoke it and
  confirm the status list bit flips and a signed callback is delivered
  to a fixture receiver
- [ ] `openspec validate eudi-wallet-credential-issuance --strict` clean

Acceptance criteria (plain bullets — verified by /opsx-verify):

- The Adapters catalogue shows one "EUDI Wallet Credential Issuance" card;
  no new top-level menu item exists
- The EUDI issuer signing key is generated/rotated only from
  *Beheer > Authenticatie*, its private key is `ICrypto`-encrypted at
  rest, and a rotation leaves previously-issued credentials verifiable
  against the archived public key
- `POST /api/eudi/credential-offers` requires valid consumer
  authentication (REQ-CON-001) and, where configured, a valid JWT
  (authorization-jwt REQ-001); an unauthenticated call persists nothing
  and returns 401
- A created offer's `credential_offer_uri` resolves exactly once and
  expires after 15 minutes even if never fetched
- A `pre-authorized_code` exchanges for exactly one access token; a
  second exchange attempt is rejected as `invalid_grant`
- `format: "jwt_vc_json"` credentials are returned byte-identical to the
  payload the consuming app supplied; `format: "dc+sd-jwt"` credentials
  are freshly minted and signed with the resolved organisation's active
  issuer key
- A replayed proof-of-possession JWT is rejected and issues no credential
- The published status list is a validly signed, unexpired JWT whose
  bitstring reflects every revoked credential's bit as `1`, refreshed by
  the cron job before its own `exp` lapses
- Revocation is idempotent, restricted to the owning consumer (403
  otherwise), and delivers an HMAC-signed callback via the existing
  `WebhookSignatureService` scheme
- No private key material or plaintext `pre-authorized_code`/`access_token`
  ever appears in a persisted row, log line, or API response
