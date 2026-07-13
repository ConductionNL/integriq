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

- [x] Confirm the ADR-037 fragment loader merges `components.schemas` (not
  only `components.objects`) into the effective register descriptor
  (design.md DEFERRED_QUESTION) — RESOLVED: `InitializeRegister::deepMergeConfig()`
  (lib/Repair/InitializeRegister.php:135-225) generically deep-merges the
  WHOLE descriptor, and OpenRegister's `ImportHandler::importFromJson()`
  (lib/Service/Configuration/ImportHandler.php:1602-1803) reads
  `components.schemas` generically. Traced one additional requirement not
  named in the design: a new schema slug must ALSO be listed under
  `components.registers.openconnector.schemas[]` (a plain list, concatenates
  cleanly) or it imports but never attaches to the register — the fragment
  was missing this and has been fixed. Covered by
  `tests/Unit/Settings/EudiRegisterFragmentTest.php`.
- [x] Author `lib/Settings/register.d/eudi-wallet-credential-issuance.json`
  declaring `eudi_credential_offer`, `eudi_issuance_session`, and
  `eudi_status_list` schemas (properties per spec.md REQ-EUDI-001/004/005/006/008)
- [x] Loader supports `components.schemas` (confirmed above) — the
  `openconnector_register.json` fallback was not needed.

## Issuer key service (Beheer > Authenticatie)

- [x] `lib/Service/EudiIssuerKeyService.php`: `generateKey(organisationId)`
  (ES256/P-256 via `openssl_pkey_new`), `ICrypto::encrypt()` the private
  key before persisting, store public key + SHA-256 `kid` fingerprint
  plain, mirroring scholiq `KeyManagementService::generateTenantKeypair()`
  line-for-line in shape (not copy-pasted — new class, new app)
- [x] `rotateKey(organisationId)`: archive current public key (capped at
  32, oldest-pruned-first) before generating the new active key; discard
  the rotated-out private key (never archived)
- [x] `resolveActiveKey(organisationId)` / `resolvePublicKeyByFingerprint(organisationId, kid)`
  for signing and JWKS-publish/verification lookups respectively
- [x] Wire organisation scoping through the existing `organisation-bridge`
  soft-fail accessor; fall back to a single default-organisation key when
  it returns null (never an unencrypted key, never a hard failure)
- [x] `lib/Controller/EudiIssuerKeyAdminController.php`: admin-gated
  generate/rotate/status endpoints — returns public key + `kid` only,
  never private key material (verified: `tests/Unit/Controller/EudiIssuerKeyAdminControllerTest.php`).
  **`Beheer > Authenticatie` UI section NOT built** — traced at HEAD:
  no `Adapters`/`Beheer` top-level menu exists yet in `src/manifest.json`
  (still the legacy `ConnectionsGroup`/`AutomationGroup` IA; ADR-017's
  5-menu IA is not implemented), and `digid-eherkenning-auth-adapter` has
  **no frontend files either** (`grep -rl digid|eherkenning src/` = zero
  hits) — same for every other adapter with backend key endpoints
  (`lti#generateKey`/`lti#rotateKey` also have zero frontend callers).
  This is a pre-existing, repo-wide gap shared by every adapter, not
  something this change could close without building the ADR-017 IA from
  scratch (out of scope for one adapter's change). Flagged, not silently
  skipped — see proposal impact note below.
- [x] Unit tests: encrypted-at-rest assertion (no raw PEM in storage),
  rotation archive/prune behaviour, fingerprint-based resolution
  (active + archived), organisation-bridge-null fallback

## App-facing offer creation (consumer-gated)

- [x] `lib/Service/EudiCredentialOfferService.php::createOffer()`: validate
  `{credentialPayload, format, subjectId, consumerId}`, mint a
  cryptographically random `pre-authorized_code`, persist only its hash
  in a new `eudi_credential_offer` row, build `{offerUrl,
  credentialOfferUri, qrPayload}`
- [x] `lib/Controller/EudiWalletController.php::createOffer()`:
  `POST /api/eudi/credential-offers`, gated by the existing
  `consumer-management` REQ-CON-001 resolution + `authorization-jwt`
  REQ-001 JWT check (no new auth mechanism) before calling the service;
  HTTP 400 on malformed payload with zero persisted state
- [x] Unit tests: authenticated success path, unauthenticated 401 with no
  row persisted, malformed-payload 400

## Wallet-facing protocol endpoints

- [x] `GET /.well-known/openid-credential-issuer`: issuer metadata
  (credential_issuer, credential_endpoint, token_endpoint,
  credential_configurations_supported for at least one `jwt_vc_json`
  (EDCI diploma) and one `dc+sd-jwt` (Open Badges 3.0) configuration,
  active + archived-within-window JWKS) reflecting the resolved
  organisation's key set
- [x] `GET /api/eudi/credential-offers/{id}`: single-fetch resolution of
  the `credential_offer_uri` target, 15-minute default TTL, atomic
  consume-on-read (second fetch → 404/410, generic message)
- [x] `POST /api/eudi/token`: pre-authorized_code grant only, atomic
  lookup-and-invalidate against the stored code hash (mirrors
  `AuthorizationService::validatePayload`'s jti-replay shape), `tx_code`
  verification with rate-limiting that does NOT consume the code on a
  wrong PIN, persists `eudi_issuance_session` (access_token hash,
  c_nonce, expiry) on success
- [x] `POST /api/eudi/credential`: Bearer + `proof.jwt` verification
  (nonce == session's current `c_nonce`, proof not previously seen for
  this session — replay rejection); dispatch by `format`:
  - `jwt_vc_json` → return the stored `credentialPayload` verbatim, no
    re-signing (design.md D-SIGN)
  - `dc+sd-jwt` → mint + sign a fresh SD-JWT VC with
    `EudiIssuerKeyService::resolveActiveKey()`
- [x] `GET /api/eudi/status-lists/{id}`: OAuth Status List Token
  (`bits: 1`, `purpose: revocation` only), signed with the resolved
  organisation's active issuer key, own `exp`
- [x] Unit tests per endpoint: happy path, each documented failure mode
  from spec.md (expired/consumed offer, replayed pre-authorized_code,
  wrong tx_code non-consumption, replayed proof, format dispatch for
  both `jwt_vc_json` and `dc+sd-jwt`) — all real behaviour against
  in-memory fakes (real JWT signing/verification via jose-framework,
  real ICrypto-shaped encrypt/decrypt round-trip), no mock-only fakes.
  **Newman/integration tests against a live dev instance NOT run** — no
  running Nextcloud+OpenRegister instance available in this apply
  environment.
- [x] Register the six public routes in `appinfo/routes.php`
  (`#[PublicPage]`/`#[NoCSRFRequired]`) — registered only after real,
  tested verification logic existed (atomic consume-on-read, jti-shaped
  replay rejection, proof-of-possession checks), per design.md D-ROUTE;
  `composer check:routes` confirms all 117 routes (incl. the 8 new EUDI
  routes) resolve to existing controller methods.

## Revocation + status callback

- [x] `EudiCredentialOfferService::revoke()`: consumer-ownership check
  (only the offer's `consumerId` may revoke it → 403 otherwise), flip the
  assigned status-list bit `0 → 1`, idempotent on an already-revoked
  offer (no double-toggle, no error)
- [x] `POST /api/eudi/credential-offers/{id}/revoke` on
  `EudiWalletController`, same consumer-gating as offer creation
- [x] Wire the status callback through the existing
  `WebhookSignatureService` (no new signing scheme) —
  `X-OpenConnector-Signature` HMAC over the callback body, delivered to
  the owning consumer's configured callback URL
- [x] Unit tests: bit-flip + callback delivery (signature format asserted),
  idempotent double-revoke, cross-consumer 403 with unchanged bit

## Status list refresh cron

- [x] `lib/Cron/EudiStatusListRefreshJob.php` (`TimedJob`, mirrors
  `EventRetryJob`'s shape): sweep `eudi_status_list` rows whose token
  `exp` is within the configurable refresh window (default: <25% of
  total validity remaining), re-sign with the current active issuer key,
  preserve bitstring contents
- [x] Registered in `appinfo/info.xml` `<background-jobs>` alongside
  `EventRetryJob`/`LtiKeyRetirementJob`/`BankfeedSyncJob`
- [x] Unit test: near-expiry row is refreshed with unchanged bitstring and
  a later `exp` (`EudiStatusListServiceTest`); a fresh (non-near-expiry)
  token is left alone. Job-level containment (poisoned-sweep exception
  caught/logged, never rethrown) covered by `EudiStatusListRefreshJobTest`,
  mirroring `EventRetryJobTest`. The "rotated key doesn't invalidate an
  already-published token" property follows directly from D-KEY's
  archived-key-remains-resolvable design and is covered indirectly by
  `EudiIssuerKeyServiceTest::testRotateKeyArchivesPreviousPublicKeyOnly`
  (no dedicated end-to-end test combining rotation + a stale
  already-cached status-list token — flagged, not a correctness gap:
  `resolvePublicKeyByFingerprint` is exercised directly).

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

  **NOT IMPLEMENTED — HEAD-reality conflict, not silently reinterpreted.**
  Traced before starting this section: `src/manifest.json`'s `menu` array
  has no `Adapters` and no `Beheer` top-level entry — ADR-017's 5-menu
  IA (Verbindingen/Bronnen/Berichten/Adapters/Beheer) is not implemented
  anywhere in this app at HEAD; the current menu is the legacy
  `ConnectionsGroup`/`AutomationGroup` shape. `grep -rli
  'digid\|eherkenning' src/` (the proposal's cited sanctioned-split
  precedent) returns zero files, and `lti#generateKey`/`lti#rotateKey`
  (this app's OTHER admin-gated key-lifecycle precedent, shipped and
  routed) also have zero frontend callers. Building the catalogue
  page + Beheer group from scratch is a repo-wide IA change affecting
  every adapter, not a "one adapter's change" task, and no sibling
  adapter (LTI, DSO, Digikoppeling, PSD2) has done it either. This
  change ships the full backend contract these three tasks would wire
  a UI onto (`EudiIssuerKeyAdminController`, the register fragment's
  three schemas) and leaves the tasks unchecked rather than fabricating
  a catalogue page pointed at a menu structure that doesn't exist.

## Cross-cutting

- [x] Secret-hygiene audit: confirm the issuer private key never appears
  in an API response, log line, or error message anywhere in the new
  code — `grep -rn 'privateKeyPem\|encryptedPrivateKey\|privateKeySecret'
  lib/Controller/ lib/Cron/` returns zero hits; the only two places
  `privateKeyPem` exists are `EudiIssuerKeyService::resolveActiveKey()`'s
  internal return (never called from a controller) and `signJwt()`'s
  local variable (used, never returned/logged).
- [x] `composer phpcs` clean on every new/touched file (7 files, 0
  errors/warnings after fixing 69 auto-fixable + ~35 manual violations —
  named-parameter-on-internal-calls, no-inline-ternary, doc-comment
  capitalisation, line-length). Scoped `composer phpstan` (7 files):
  0 errors (1 real narrowing issue found and fixed — see
  `EudiCredentialOfferService::exchangeToken()`'s pre-mutation capture of
  `$offerFormat`/`$offerCredentialConfigId`). **Full-repo
  `composer check:strict`/`psalm`/`phpmd` NOT run** (repo-wide, out of
  this task's scoped-file mandate per apply-common.md instructions).
- [x] Full existing PHPUnit suite green — 709/709 (664 baseline + 45 new),
  no regressions from this change (net-new endpoints/services, no
  modification to existing request paths). `composer check:routes`:
  all 117 routes (incl. 8 new EUDI routes) resolve to existing controller
  methods. `check:no-legacy-types`: PASS. Hydra gates: 0 EUDI-related
  failures across all 33 gates (2 pre-existing, unrelated failures:
  gate-9 semantic-auth on `DSOController`/`PeppolController`, predating
  this change; gate-46 spec-anchor-existence, 1068 repo-wide unresolved
  anchors predating this change — this change's own anchors were
  verified anchor-by-anchor against `spec.md` and fixed to 0 failures,
  see `tests/Unit/Settings/EudiRegisterFragmentTest.php`'s neighbourhood
  for the verification method).
- [ ] Verify e2e on a dev instance: create an offer via
  `POST /api/eudi/credential-offers` with a fixture `jwt_vc_json`
  payload, resolve the offer, exchange the pre-authorized_code at
  `/api/eudi/token`, fetch the credential at `/api/eudi/credential` and
  confirm it matches the fixture payload byte-for-byte; revoke it and
  confirm the status list bit flips and a signed callback is delivered
  to a fixture receiver. **NOT RUN** — no live Nextcloud+OpenRegister
  dev instance available in this apply environment (docker-only, no
  running app server). The full flow IS covered end-to-end at the
  service layer by `EudiCredentialOfferServiceTest` (offer→token→
  credential→revoke chain against in-memory fakes with real JWT
  signing), which is the closest verification this environment permits.
- [x] `openspec validate eudi-wallet-credential-issuance --type change --strict`: clean.

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
