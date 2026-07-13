# eudi-wallet-credential-issuance Specification

## Purpose
TBD - created by archiving change eudi-wallet-credential-issuance. Update Purpose after archive.
## Requirements
### Requirement: Adapter ships as a catalogue entry backed by three register-fragment schemas (REQ-EUDI-001)

The system MUST declare three new OpenRegister schemas in the
`openconnector` register via an ADR-037 register fragment
(`lib/Settings/register.d/eudi-wallet-credential-issuance.json`,
`components.schemas`) — NOT by editing `openconnector_register.json`
directly: `eudi_credential_offer` (one row per created offer: payload
reference, format, subjectId, consumerId, pre-authorized_code hash,
status, expiry), `eudi_issuance_session` (token/credential-endpoint
session state: access_token hash, c_nonce, offer reference, expiry), and
`eudi_status_list` (one row per published status list: list id, bit
capacity, issuer key `kid`, current bitstring, `exp`). The adapter MUST
ship as a single card in the *Adapters* catalogue ("EUDI Wallet Credential
Issuance") referencing these three schemas as its configuration surface,
per ADR-017 Rule 1. It MUST NOT add a top-level navigation menu or a
per-adapter settings page.

@e2e exclude adapter catalogue registration + schema declaration — covered by PHPUnit, no dedicated browser journey

#### Scenario: the adapter ships as an Adapters card, not a menu item

- **GIVEN** the EUDI wallet credential issuance adapter is installed
- **WHEN** the *Adapters* catalogue is inspected
- **THEN** it SHALL show an "EUDI Wallet Credential Issuance" catalogue
  entry backed by the `eudi_credential_offer`, `eudi_issuance_session`, and
  `eudi_status_list` schemas
- **AND** no top-level menu item SHALL be added for it
- @e2e exclude catalogue registration — covered by PHPUnit

#### Scenario: the register fragment does not modify the coverage-gated descriptor file

- **GIVEN** the register fragment merge runs at load
- **WHEN** `lib/Settings/openconnector_register.json`'s own entry count is
  checked (`openconnector-register-schema` REQ-A-002)
- **THEN** it SHALL remain unchanged at exactly 15 entries
- **AND** the three new schemas SHALL only be reachable through the merged
  fragment output
- @e2e exclude register fragment merge — covered by PHPUnit

### Requirement: Issuer signing-key lifecycle under Beheer > Authenticatie (REQ-EUDI-002)

The system MUST provide an admin-gated key section under
*Beheer > Authenticatie* ("EUDI issuer key", ADR-017 Rule 3/7 sanctioned
split) that generates and rotates the issuer's own ES256 (P-256) signing
keypair, modeled on scholiq's `KeyManagementService`/`KeyAdminController`
pair: the private key MUST be encrypted at rest via
`OCP\Security\ICrypto::encrypt()` before storage (never plaintext), the
public key MUST be stored plain alongside a SHA-256 fingerprint used as the
JWK `kid`, and the key MUST be scoped per OpenRegister Organisation via the
existing `organisation-bridge` soft-fail accessor (falling back to a single
default-organisation key when OpenRegister or the active organisation is
unavailable — never silently skipping encryption). Generation MUST fail
loudly (thrown exception, no partial key persisted) if OpenSSL key
generation or export fails. Rotation MUST archive the previous public key
(verification-only, capped at 32 entries, oldest pruned first, mirroring
scholiq's `archiveCurrentPublicKey()`) so credentials already issued and
signed under a retired key remain verifiable indefinitely; the private key
of a rotated-out keypair MUST be discarded, not archived.

@e2e exclude backend key lifecycle (admin-gated generation/rotation) — covered by PHPUnit

#### Scenario: a generated key's private half is never stored in plaintext

- **GIVEN** an admin generates the EUDI issuer key for an organisation with
  no existing key
- **WHEN** the keypair is persisted
- **THEN** the stored private-key value SHALL be the `ICrypto`-encrypted
  ciphertext, never the raw PEM
- **AND** the response returned to the admin SHALL contain only the public
  key and its `kid` fingerprint

#### Scenario: rotation keeps previously-issued credentials verifiable

- **GIVEN** an organisation with an active issuer key that signed a
  `dc+sd-jwt` credential
- **WHEN** the admin rotates the key
- **THEN** the previous public key SHALL be retained in the archived-keys
  list, resolvable by its `kid`
- **AND** the previously-issued credential's signature SHALL still verify
  against the archived public key
- **AND** the archived key's private key material SHALL NOT be retrievable
  through any endpoint

#### Scenario: no organisation context falls back to a single default key, not a silent skip

- **GIVEN** OpenRegister's `organisation-bridge` accessor returns null
  (OpenRegister absent or no active organisation)
- **WHEN** an issuer key is generated or resolved for signing
- **THEN** the system SHALL use a single default-organisation-scoped key
  rather than failing the request or falling back to an unencrypted key
- @e2e exclude organisation-bridge fallback — covered by PHPUnit

### Requirement: Issuer metadata endpoint (REQ-EUDI-003)

`GET /.well-known/openid-credential-issuer` MUST be a public,
`#[NoCSRFRequired]` endpoint returning OpenID4VCI Credential Issuer
Metadata: `credential_issuer` (this instance's base URL), `credential_endpoint`,
`token_endpoint`, `credential_configurations_supported` (one entry per
configured credential type — at minimum an EDCI/ELM `jwt_vc_json` diploma
configuration and an Open Badges 3.0 `dc+sd-jwt` configuration, each naming
its `format`, `scope`, and claims schema), and the issuer's current JWKS
(active key plus any archived/previous keys still within their verification
window, mirroring REQ-EUDI-002's rotation semantics). The response MUST
reflect the resolved organisation's key set (REQ-EUDI-002), not a
hard-coded single-tenant document.

@e2e exclude backend metadata endpoint (wallet-facing, no browser UI) — covered by PHPUnit/Newman

#### Scenario: metadata lists every configured credential configuration

- **GIVEN** the issuer has an EDCI diploma configuration and an Open Badges
  3.0 configuration enabled
- **WHEN** `GET /.well-known/openid-credential-issuer` is called
- **THEN** the response SHALL list both under
  `credential_configurations_supported`, each with its `format` and claims
  schema
- **AND** the response SHALL include the issuer's active public JWK

### Requirement: Credential offer creation is consumer-gated and app-facing (REQ-EUDI-004)

`POST /api/eudi/credential-offers` MUST require passing consumer
authentication (`consumer-management` REQ-CON-001) and, where the matched
consumer's `authorizationType` is `jwt`, JWT bearer verification
(`authorization-jwt` REQ-001) before any offer is created. The request body
MUST be `{credentialPayload, format, subjectId, consumerId}` where
`credentialPayload` is the already-assembled, already-signed (for
`jwt_vc_json`) or already-claims-shaped (for `dc+sd-jwt`) credential content
handed over by the calling app — this endpoint MUST NOT accept raw claims
for construction from scratch in this change (see design.md Non-goals). On
success the system MUST persist an `eudi_credential_offer` row, mint a
cryptographically random, single-use `pre-authorized_code` (stored only as
a hash, mirroring the `whsec_`-prefixed-secret hygiene pattern), and return
`{offerUrl, credentialOfferUri, qrPayload}`. A request missing or
mismatching required fields MUST be rejected with HTTP 400 before any
`eudi_credential_offer` row is persisted.

@e2e exclude backend offer creation (app-facing API, no browser UI — consumer is a calling app) — covered by PHPUnit/Newman

#### Scenario: a correctly authenticated consumer creates an offer

- **GIVEN** a registered consumer with `authorizationType: jwt` and a valid
  bearer token
- **WHEN** it calls `POST /api/eudi/credential-offers` with a signed
  `jwt_vc_json` payload and a `subjectId`
- **THEN** the response SHALL be `{offerUrl, credentialOfferUri, qrPayload}`
- **AND** a `pre-authorized_code` SHALL be persisted as a hash only, never
  in plaintext

#### Scenario: an unauthenticated caller cannot create an offer

- **GIVEN** a request to `POST /api/eudi/credential-offers` with no
  matching consumer credentials
- **WHEN** the request is processed
- **THEN** the response SHALL be HTTP 401
- **AND** no `eudi_credential_offer` row SHALL be persisted

### Requirement: Credential offer resolution is public, single-fetch, short-TTL (REQ-EUDI-005)

`GET /api/eudi/credential-offers/{id}` MUST be the public
`credential_offer_uri` resolution target an EUDI wallet dereferences after
scanning the QR/deep-link. It MUST return the OpenID4VCI
`credential_offer` object (`credential_issuer`, `credential_configuration_ids`,
`grants` with `urn:ietf:params:oauth:grant-type:pre-authorized_code`) exactly
once per offer — the offer MUST carry a short default TTL (15 minutes) and,
independent of TTL, MUST become permanently unresolvable after its first
successful fetch (single-use marker set atomically on read). A second fetch
attempt (expired, already-consumed, or unknown id) MUST return HTTP 404/410
without leaking whether the id ever existed versus already having been
consumed beyond a generic "not found or expired" message.

@e2e exclude backend offer resolution (wallet-facing) — covered by PHPUnit/Newman

#### Scenario: a fresh offer resolves exactly once

- **GIVEN** an offer created 1 minute ago, never fetched
- **WHEN** a wallet fetches `GET /api/eudi/credential-offers/{id}` twice in
  a row
- **THEN** the first fetch SHALL return HTTP 200 with the offer object
- **AND** the second fetch SHALL return HTTP 404/410

#### Scenario: an expired offer is unresolvable even if never fetched

- **GIVEN** an offer created 20 minutes ago (TTL 15 minutes), never fetched
- **WHEN** a wallet fetches it
- **THEN** the response SHALL be HTTP 404/410

### Requirement: Token endpoint issues a single-use pre-authorized_code grant (REQ-EUDI-006)

`POST /api/eudi/token` MUST be public and MUST implement ONLY the
`urn:ietf:params:oauth:grant-type:pre-authorized_code` grant (no
authorization_code / redirect-based grant in this change). The presented
`pre-authorized_code` MUST be looked up by its stored hash and consumed
atomically (single lookup-and-invalidate, mirroring
`AuthorizationService::validatePayload`'s jti-replay shape and the LTI
nonce single-use pattern) — a second presentation of the same code MUST be
rejected as `invalid_grant`, never re-issue a token. On success the system
MUST persist an `eudi_issuance_session` row and return an opaque
`access_token`, `token_type: "bearer"`, a fresh `c_nonce`, and
`c_nonce_expires_in`. `tx_code` (PIN) input, if configured on the offer,
MUST be verified before the code is consumed; a wrong `tx_code` MUST NOT
consume the code (the wallet retains its remaining attempts) but MUST be
rate-limited to prevent brute-force.

@e2e exclude backend token endpoint (wallet-facing) — covered by PHPUnit/Newman

#### Scenario: a valid pre-authorized_code exchanges for exactly one access token

- **GIVEN** a freshly created offer with no `tx_code` requirement
- **WHEN** `POST /api/eudi/token` presents the correct `pre-authorized_code`
  twice
- **THEN** the first request SHALL return HTTP 200 with an `access_token`
  and `c_nonce`
- **AND** the second request SHALL return HTTP 400 `invalid_grant`

#### Scenario: a wrong tx_code does not consume the code

- **GIVEN** an offer requiring a `tx_code`
- **WHEN** the token request presents the correct `pre-authorized_code` but
  a wrong `tx_code`
- **THEN** the response SHALL be HTTP 400 `invalid_grant` (or
  `invalid_request` for a malformed PIN)
- **AND** the `pre-authorized_code` SHALL remain valid for a subsequent
  correctly-PINned attempt, subject to rate-limiting
- @e2e exclude tx_code rate-limiting — covered by PHPUnit

### Requirement: Credential endpoint verifies proof-of-possession and dispatches by format (REQ-EUDI-007)

`POST /api/eudi/credential` MUST be public and MUST require both a valid
`Authorization: Bearer <access_token>` (matching an unexpired
`eudi_issuance_session`) and a wallet key-binding proof (`proof.jwt`, a
`openid4vci-proof+jwt`-typed JWT whose payload's `nonce` matches the
session's current `c_nonce` and whose header carries a `jwk` or `kid` the
system did not previously see for this session — a fresh key-possession
proof, not a replay of a prior proof). On success the response format
depends on the offer's `format`:
- `format: "jwt_vc_json"` — the response MUST wrap the already-signed
  VC-JWT the consuming app handed over at offer-creation time
  (REQ-EUDI-004) **verbatim**; this endpoint MUST NOT re-sign or otherwise
  mutate that payload.
- `format: "dc+sd-jwt"` — the system MUST mint a fresh SD-JWT VC from the
  claims-shaped payload and sign it with the resolved organisation's active
  issuer key (REQ-EUDI-002); this is the adapter's actual "sealing"
  responsibility.

A request with a missing/invalid Bearer token, an expired session, a
`c_nonce` mismatch, or a replayed proof MUST be rejected with HTTP
400/401 and MUST NOT return credential material in the error response.

@e2e exclude backend credential endpoint (wallet-facing) — covered by PHPUnit/Newman

#### Scenario: jwt_vc_json is returned verbatim, never re-signed

- **GIVEN** an issuance session for a `jwt_vc_json` offer whose
  `credentialPayload` was signed by the consuming app
- **WHEN** the wallet presents a valid access token and a fresh proof
- **THEN** the response's credential SHALL be byte-identical to the
  `credentialPayload` handed over at offer creation

#### Scenario: dc+sd-jwt is minted and signed with the issuer's active key

- **GIVEN** an issuance session for a `dc+sd-jwt` offer
- **WHEN** the wallet presents a valid access token and a fresh proof
- **THEN** the response SHALL be a fresh SD-JWT VC whose signature
  verifies against the resolved organisation's active issuer public key

#### Scenario: a replayed proof is rejected

- **GIVEN** a proof JWT already accepted once for this session
- **WHEN** it is presented again (same session, same proof)
- **THEN** the response SHALL be HTTP 400 and no credential SHALL be
  issued
- @e2e exclude proof replay rejection — covered by PHPUnit

### Requirement: Status list publishes single-bit revocation only (REQ-EUDI-008)

`GET /api/eudi/status-lists/{id}` MUST be public and MUST return an OAuth
Status List Token (`draft-ietf-oauth-status-list`): a JWT whose payload
carries a `status_list` claim with a bitstring (`bits: 1`, `purpose:
"revocation"` only — this change MUST NOT implement the 2-bit suspension
status) compressed per the draft's encoding, signed with the issuing
organisation's active issuer key, and carrying its own `exp`. A background
job (REQ-EUDI-008b below) MUST re-sign the token before that `exp` elapses
so the published endpoint never serves a token whose own signature has
expired.

@e2e exclude backend status list publish (wallet/verifier-facing) — covered by PHPUnit/Newman

#### Scenario: a published status list is a validly signed, unexpired token

- **GIVEN** an `eudi_status_list` row with at least one revoked credential
  bit set
- **WHEN** `GET /api/eudi/status-lists/{id}` is called
- **THEN** the response SHALL be a JWT whose signature verifies against the
  issuer's active key
- **AND** the decoded bitstring SHALL have the revoked credential's
  assigned index set to `1`
- **AND** the token's `exp` SHALL be in the future

### Requirement: Status list refresh keeps the published token ahead of its own expiry (REQ-EUDI-008b)

The system MUST periodically re-sign every `eudi_status_list` row whose
current token `exp` is within a configurable refresh window (default:
re-sign when less than 25% of the token's total validity window remains),
via a new `lib/Cron/EudiStatusListRefreshJob.php` (a `TimedJob`, mirroring
`EventRetryJob`'s shape). Re-signing MUST produce a new signed token with a
fresh `exp` and leave the bitstring contents unchanged. A rotation of the
issuing organisation's
issuer key (REQ-EUDI-002) MUST NOT invalidate previously-published status
list tokens still within their own `exp` (they remain verifiable against
the archived key), but the NEXT refresh cycle MUST sign with the new active
key.

@e2e exclude background cron job — covered by PHPUnit

#### Scenario: a near-expiry status list is refreshed before it lapses

- **GIVEN** a status list token with less than 25% of its validity window
  remaining
- **WHEN** the refresh job runs
- **THEN** a new token SHALL be signed with the same bitstring contents and
  a fresh `exp`
- **AND** a verifier fetching the endpoint SHALL never observe a token
  whose `exp` has already passed

### Requirement: Revocation flips one status-list bit and fires a signed callback (REQ-EUDI-009)

`POST /api/eudi/credential-offers/{id}/revoke` MUST require the same
consumer authentication as offer creation (REQ-EUDI-004; only the owning
consumer, resolved from the offer's persisted `consumerId`, may revoke its
own offers). On success the system MUST flip the credential's assigned
status-list bit from `0` to `1` (idempotent — revoking an already-revoked
credential MUST return success without erroring) and MUST fire a status
callback to the owning consumer's configured callback URL, HMAC-signed via
the existing `WebhookSignatureService` (`X-OpenConnector-Signature: t=...,v1=...`,
the same scheme as `webhook-signing` REQ-WHS-001 — no second signing
scheme is introduced). Revocation MUST NOT modify the credential content
itself (the VC/SD-JWT already issued is unchanged); only the status list
bit and the callback are new state.

@e2e exclude backend revocation + callback (app-facing API, no browser UI) — covered by PHPUnit/Newman

#### Scenario: revocation flips the bit and delivers a signed callback

- **GIVEN** an offer owned by consumer `acme-co`, already issued to a
  wallet
- **WHEN** `acme-co` calls `POST /api/eudi/credential-offers/{id}/revoke`
- **THEN** the credential's status-list bit SHALL flip to `1`
- **AND** a callback to `acme-co`'s configured URL SHALL carry
  `X-OpenConnector-Signature` verifiable against `acme-co`'s signing
  secret

#### Scenario: revoking twice is idempotent

- **GIVEN** an offer already revoked
- **WHEN** the same consumer calls revoke again
- **THEN** the response SHALL be success (not an error)
- **AND** the status-list bit SHALL remain `1` (no double-toggle back to
  `0`)

#### Scenario: a consumer cannot revoke another consumer's offer

- **GIVEN** an offer owned by consumer `acme-co`
- **WHEN** a different authenticated consumer `other-co` calls revoke on
  that offer id
- **THEN** the response SHALL be HTTP 403
- **AND** the status-list bit SHALL remain unchanged

