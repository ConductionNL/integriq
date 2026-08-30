# Design — OpenID4VCI EUDI wallet credential issuance adapter

## Context

Verified against this openconnector worktree at HEAD (`development`,
untracked change directory; repo otherwise 12 commits behind
`origin/development` — no conflicting changes to the touched surfaces at
that delta):

- **No existing OpenID4VCI code.** `grep -rE
  'OpenID4VCI|openid-credential-issuer|credential_offer|pre-authorized_code'
  lib/ openspec/specs/ openspec/changes/` returns zero hits outside this
  change's own artifacts. This is a net-new capability, not an extension of
  an existing adapter.
- **Key/secret custody today is honestly plaintext-pending-encryption.**
  `openspec/architecture/adr-007-source-credentials-stored-plaintext-pending-encryption.md`
  documents that `Source` credential columns (`secret`, `password`,
  `apikey`, `jwt`, `jwtId`, `username`) are persisted plaintext, with a
  planned `EncryptionService` (`adr-016-encryption-service-design.md`) that
  **does not exist in code** — `grep -rl ICrypto lib/` returns zero files.
  This is the fleet-wide status quo this change must not silently assume
  away.
- **The one existing precedent for in-process external signing fails
  closed.** `lib/Adapters/Digikoppeling/PkiOverheidCredentialResolver.php:105-116`
  looks for `issueSigningMaterial` on OpenRegister's
  `CredentialBrokerService` by reflection and throws
  `DigikoppelingException` naming the exact missing capability when
  absent — documented in its own docblock (lines 12-20) as "the honest,
  spec-compliant behaviour... not a stub." The in-flight
  `source-broker-credentials` change (`design.md` D2/D3, verified at HEAD)
  confirms the broker's `CredentialBrokerService::request()` contract is a
  **constrained proxy** — it injects auth server-side and returns
  `array{status, headers, body}`; it has no method that hands back raw key
  material for a caller to sign with locally. An issuer key that depended
  on that capability today would ship unable to sign anything.
- **`authorization-jwt` (REQ-001, `status: done`) already verifies inbound
  JWTs against a consumer-registered JWK** via
  `AuthorizationService::authorizeJwt()`/`checkHeaders()`, and
  `consumer-management` REQ-CON-001 already gates inbound calls on a
  resolved `consumer` record's `authorizationType`. Both are reused
  unmodified as the app-facing offer-creation gate (D-TRUST below) — this
  change adds no second authentication mechanism for the app-facing leg.
- **`webhook-signing` (REQ-WHS-001/002) already owns HMAC-SHA256 signing
  lifecycle** (`WebhookSignatureService`: generate/reveal-once/redact/rotate,
  `whsec_`-prefixed secrets, `X-OpenConnector-Signature: t=...,v1=...`).
  Reused verbatim for the revocation status callback (D-REVOKE) rather than
  inventing a second signing scheme.
- **scholiq (sibling repo, read for grounding only) already runs the exact
  key-custody pattern this design adopts.**
  `/home/rubenlinde/scholiq-goal/scholiq-dev/lib/Service/KeyManagementService.php`:
  `generateTenantKeypair()` (lines 104-164) generates an asymmetric keypair,
  encrypts the private key via `ICrypto::encrypt()` before storing it in
  `IAppConfig`, stores the public key plain plus a SHA-256 `kid`
  fingerprint, and `archiveCurrentPublicKey()` (lines 236-266) moves the
  previous public key to a capped (32-entry), oldest-pruned-first archive
  on rotation so credentials signed under a retired key remain verifiable.
  `CredentialSigningService.php` consumes this to RS256-sign Open Badges 3.0
  assertions per tenant. This is a proven, already-shipping pattern in a
  sibling Conduction app solving the identical problem (per-tenant
  asymmetric signing key, ADR-031 "cryptographic operations" exception),
  not a hypothetical.
- **ADR-017 Rule 3/7** sanctions exactly the *Adapters* + *Beheer >
  Authenticatie* split this change needs, already precedented by
  `digid-eherkenning-auth-adapter`.
- **ADR-037 register fragments** (`lib/Settings/register.d/README.md`) are
  the escape valve for adding schemas without touching the
  coverage-gated `openconnector_register.json` (`openconnector-register-schema`
  REQ-A-002 asserts exactly 15 entries there). Every existing fragment only
  carries `components.objects` (seed data) — this change is the first to
  exercise the `components.schemas` half of the documented mechanism. Not
  a blocker (the README explicitly names both halves), but flagged as the
  first exercise of that path, so implementation should confirm the loader
  actually merges `components.schemas` before relying on it (DEFERRED
  QUESTION below).

## Decisions

### D-TRUST — Trust model: pre-authorized code only; wallet trust is out of scope

This change implements **only** the OpenID4VCI pre-authorized code flow
(`urn:ietf:params:oauth:grant-type:pre-authorized_code`), never the
authorization_code / redirect-based flow. The consuming app (scholiq or
equivalent) is the sole party that can request an offer be created —
authenticated the same way every other app-facing OpenConnector endpoint
is authenticated (`consumer-management` REQ-CON-001 +, where configured,
`authorization-jwt` REQ-001). The chain of trust this design establishes
is therefore: *consuming app → openconnector (issuer) → wallet*, gated
entirely at the first hop. It explicitly does **not** establish or verify
trust in the *wallet* itself: this change does not check the wallet's
attestation (WSCA/WSCD conformance), does not verify the wallet instance
against a Wallet Provider's trust list, and does not implement Dynamic
Client Registration for wallets. A forged or compromised wallet that
somehow obtains a valid `pre-authorized_code` (e.g. via a leaked QR image)
can retrieve the one credential that code names — the same blast radius as
a leaked bearer token in any pre-authorized-code OAuth flow, bounded by the
code's single-use + short-TTL semantics (REQ-EUDI-005/006), not by
wallet-side attestation. Wallet attestation is listed as an explicit
non-goal (below) because verifying it requires trusting a specific national
or EU Wallet Provider trust list this change has no mandate to curate.

**Rejected alternative**: require wallet attestation before issuing.
Rejected for v1 — the DC4EU/EBSI pilot ecosystem this adapter targets does
not yet have a single converged trust-list mechanism openconnector could
hard-code against, and gating a table-stakes, deadline-driven capability
(end-2026) on an unstable trust-list integration would block shipping
issuance entirely. Attestation checking is the natural extension point once
a specific pilot's trust-list format is known.

### D-KEY — Issuer key custody: `ICrypto`-in-appconfig, mirroring scholiq, not the broker

The issuer's ES256 signing keypair is generated and stored using the same
shape as scholiq's `KeyManagementService` (Context, above): private key
encrypted via `OCP\Security\ICrypto::encrypt()` before persistence, public
key stored plain with a SHA-256 `kid` fingerprint, rotation archives the
previous public key (capped, oldest-pruned) rather than discarding it, so
already-issued credentials and already-published status-list tokens remain
verifiable through their own natural expiry. This is a **new**
`EudiIssuerKeyService`, not a reuse of scholiq's class (different app,
different Nextcloud container), but the identical pattern — chosen because
it is a proven, already-shipping solution to the identical problem
(per-tenant/per-organisation asymmetric signing key) inside the same
Conduction fleet, not a novel design.

**Why not the OpenRegister credential broker.** The broker
(`CredentialBrokerService::request()`) is a constrained *outbound HTTP
call* proxy — it injects a stored secret into a request it makes on the
caller's behalf and returns the response. It has no operation shaped
"return raw key material so the caller can sign a JWT/SD-JWT locally," and
`PkiOverheidCredentialResolver` already demonstrates, in this exact
codebase, what happens when an adapter is built to assume that capability
before it exists: a fail-closed exception naming the missing method. EUDI
issuance needs in-process signing (the SD-JWT / status-list token is signed
here, not by an external call), so it has the same shape of requirement
Digikoppeling already hit and documented as unmet.

**Why not embedded-secret-in-Source, ADR-007 style.** Rejected — that
pattern exists for outbound *call* credentials (API keys, OAuth
client secrets used by `CallService`), not for an asymmetric signing
keypair the instance itself generates and owns. Storing an RSA/EC private
key as a `Source.secret` string would (a) misuse an entity modeled for
per-connection outbound auth, not tenant-wide signing infrastructure, and
(b) put it in the same `plaintext-pending-encryption` bucket ADR-007
already flags as not-yet-hardened — whereas `ICrypto::encrypt()` is
available and already proven (scholiq) for exactly this use case today.
Using it is strictly better than the status quo, at zero additional
engineering risk, and does not block on `EncryptionService` (ADR-016)
landing.

**Organisation scoping.** The key is generated and resolved per
OpenRegister Organisation, read through the existing `organisation-bridge`
soft-fail accessor (`openspec/specs/organisation-bridge/spec.md`) — the
same "returns null, logs a warning, consumer switches on availability"
shape every other organisation-aware feature in this codebase already
uses. When OpenRegister is absent or no organisation is active, the system
falls back to a single default-organisation-scoped key (never to an
unencrypted key, never to a hard failure) — multi-tenant isolation
degrades gracefully to single-tenant, it does not silently disable
encryption.

**Follow-on, explicitly deferred, not blocking.** If/when
`CredentialBrokerService` gains an `issueSigningMaterial` (or equivalent)
capability, migrating issuer-key custody off `ICrypto`-in-appconfig onto
the broker is a natural follow-on — tracked jointly with `digikoppeling-adapter`,
which hit the identical constraint for PKIoverheid WS-Security signing and
flagged the same follow-on. Not required to ship this change (see
proposal.md Impact).

### D-SIGN — Two response shapes by format: pass-through vs. mint-and-seal

`format: "jwt_vc_json"` and `format: "dc+sd-jwt"` are handled differently
at the credential endpoint, deliberately:

- **`jwt_vc_json`**: the consuming app (scholiq) has already built and
  RS256-signed the VC-JWT before calling `POST /api/eudi/credential-offers`
  (scholiq's `CredentialSigningService` — Context, above). This adapter
  wraps and returns that payload **verbatim**. It does not re-sign it,
  because re-signing would mean openconnector asserts authorship of
  content it did not author — the diploma/credential claims are
  scholiq's domain data and scholiq's signing authority (its own per-tenant
  key), and duplicating that signature under a *different* key here would
  create two competing "who sealed this" answers for the same credential.
- **`dc+sd-jwt`**: no upstream signature exists for this format in this
  change's scope (SD-JWT VC is a different serialization scholiq's
  `CredentialSigningService` does not currently produce). Minting and
  signing it here, with openconnector's own issuer key, is this adapter's
  actual "sealing" responsibility — the one place this change performs a
  cryptographic operation on credential content rather than passing
  content through.

**Rejected alternative**: always re-sign both formats with the issuer key,
for a uniform "openconnector is the sealing authority" story. Rejected —
it would silently discard the consuming app's own signature for
`jwt_vc_json` (a security-relevant behaviour change a consuming app
would not expect from a "wrap this in an offer" call) and would require
openconnector to re-derive JWT claims it has no independent basis to
assert (it received an opaque already-signed payload, not raw claims).

### D-REVOKE — Revocation semantics: single-bit OAuth Status List, not suspension

Revocation uses `draft-ietf-oauth-status-list`'s bitstring status list
mechanism with `bits: 1`, `purpose: "revocation"` only. A revoked
credential's bit flips `0 → 1`; there is no un-revoke, and no second
"suspended" state (which the draft supports via `bits: 2`). The published
status list token is itself a JWT with its own `exp`, signed with the
issuer key (D-KEY) and periodically re-signed by
`EudiStatusListRefreshJob` (mirroring `EventRetryJob`'s `TimedJob` shape)
so the endpoint never serves an expired-signature token even though the
bitstring contents rarely change. Revocation additionally fires a signed
callback via the existing `WebhookSignatureService` (webhook-signing
REQ-WHS-001) to the owning consumer's configured URL — reusing the fleet's
one HMAC-signing implementation rather than adding a second.

**Why not suspension (2-bit) in v1.** Suspension implies a re-activatable
state with its own operator workflow (who can un-suspend, under what
authorization) that the proposal's scope does not name a requirement for.
Shipping single-bit revocation now and adding suspension later (a
superset change to the same `eudi_status_list` schema and endpoint,
`bits: 1 → 2`) is lower-risk than speculatively building a workflow no
consumer has asked for.

**Why status-list bit flip, not "delete the offer/session".** The
`eudi_credential_offer`/`eudi_issuance_session` rows describe the
*issuance transaction* (pre-authorized_code, access_token, c_nonce) which
is naturally ephemeral and single-use already (REQ-EUDI-005/006);
revocation is a property of the **issued credential** itself, which lives
on independently in the wallet after issuance completes. The status list
is the only artifact a verifier checks after the fact — deleting
transaction rows has no bearing on a credential a wallet already holds.

### D-REPLAY — Single-use semantics via distributed cache, mirroring existing jti/nonce patterns

`pre-authorized_code` (REQ-EUDI-006), the offer's single-fetch marker
(REQ-EUDI-005), and the credential-endpoint proof-of-possession nonce
(REQ-EUDI-007, via the session's `c_nonce`) are each consumed atomically
(lookup-and-invalidate in one operation) rather than checked-then-deleted
in two steps, mirroring `AuthorizationService::validatePayload`'s
jti-replay guard and the `lti-13-platform` design's nonce-consumption
pattern (both verified precedents for "single lookup consumes it" in this
codebase). Codes/tokens/nonces are hashed before storage (never persisted
in plaintext), matching `webhook-signing`'s `whsec_`-secret hygiene
convention. This governs implementation of the OpenRegister-persisted
`eudi_credential_offer`/`eudi_issuance_session` rows' status transitions —
the same atomicity requirement, expressed against register objects instead
of `ICacheFactory`, since these are consumer-facing artifacts that must
also be queryable/auditable (unlike LTI's purely cache-backed nonce),
not ad-hoc application-level double-checking.

### D-ROUTE — Public wallet-facing routes follow the DSOController fail-closed discipline

The four wallet-facing endpoints (`.well-known/openid-credential-issuer`,
credential-offer resolution, token, credential, status-list) are
`#[PublicPage]`/`#[NoCSRFRequired]` by protocol necessity — a wallet has no
Nextcloud session and no CSRF token. `lib/Controller/DSOController.php` is
this codebase's existing precedent for that shape, and its routes were
**removed** from `appinfo/routes.php` in a wave-3 security fix specifically
because its signature verification was a stub (accepted any non-empty
header). This change's routes MUST ship with real, complete verification
(JWS signature checks in REQ-EUDI-002/003/007/008, single-use consumption
in REQ-EUDI-005/006, proof-of-possession replay rejection in REQ-EUDI-007)
from the first commit that registers them in `appinfo/routes.php` — no
route is registered ahead of its verification being real, and no route
ships with a "TODO: verify signature" placeholder. This is a process
constraint on implementation order (tasks.md), not a runtime behaviour, but
it is called out here because `DSOController` is direct proof of what
happens when it is not followed.

### D-SCHEMA — Register fragment declares schemas, not objects (first use of that half)

`lib/Settings/register.d/eudi-wallet-credential-issuance.json` declares
`components.schemas` for `eudi_credential_offer`, `eudi_issuance_session`,
and `eudi_status_list` — the first fragment in this codebase to use that
half of the ADR-037 mechanism (every existing fragment only seeds
`components.objects`). The `lib/Settings/register.d/README.md` names both
halves as supported (`OpenAPI components.schemas/paths`), so this is not a
new mechanism, but implementation must confirm the fragment loader
actually merges `components.schemas` into the effective register
descriptor before relying on it end-to-end (DEFERRED QUESTION below) —
if it does not, the fallback is a one-line addition to
`openconnector_register.json` itself, accepting the coverage-gate
entry-count bump (REQ-A-002) as a documented, deliberate exception for this
change, not a silent workaround.

## Standards references

- Regulation (EU) 2024/1183 (eIDAS 2.0), in force 20 May 2024, EUDI wallet
  by end of December 2026.
- OpenID for Verifiable Credential Issuance (OpenID4VCI) — pre-authorized
  code flow, Credential Issuer Metadata, Credential/Token endpoints,
  `credential_offer`/`credential_offer_uri`.
- IETF draft `draft-ietf-oauth-status-list` — OAuth Status List Token
  (bitstring status list, revocation purpose).
- IETF draft SD-JWT VC (`dc+sd-jwt` format) for selectively-disclosable
  Verifiable Credentials.
- W3C Verifiable Credentials Data Model — the `jwt_vc_json` payload shape
  the consuming app hands over.
- European Learning Model (ELM v3) / European Digital Credentials for
  Learning (EDCI) — the diploma credential profile scholiq assembles.
- 1EdTech Open Badges 3.0 — the badge credential profile scholiq assembles,
  rebuilt on W3C Verifiable Credentials.
- DC4EU large-scale pilot / EBSI — the piloting context named in insight
  1150 and insight 931.

## Non-goals

- **Not a PID Provider.** This adapter issues credentials whose content a
  consuming app supplies (diplomas, badges); it does not implement the
  eIDAS Person Identification Data issuance flow a Member State's official
  PID Provider runs.
- **Not wallet attestation / WSCA-WSCD verification** (D-TRUST). Verifying
  that a presenting wallet instance is a genuine, unrevoked, conformant
  wallet is out of scope — this change trusts the pre-authorized_code
  possession as the sole bearer-credential for issuance, the same trust
  boundary every pre-authorized-code OAuth flow has.
- **Not a Relying Party / credential verification role.** This change only
  *issues* credentials; verifying a presented credential (OpenID4VP or
  equivalent) is a distinct capability, not built here.
- **Not eIDAS Trusted List registration.** The issuer key generated by
  `EudiIssuerKeyService` is a technical signing key, not a Qualified
  certificate; getting it onto a national eIDAS Trusted List so wallets
  treat it as an authoritative issuer is an organisational/legal
  registration process outside this repository, not a code change.
- **Not suspension status** (D-REVOKE) — only single-bit revocation ships;
  the 2-bit suspend/reactivate status is a later, separate change if a
  consumer requests it.
- **Not migrating issuer-key custody to the OpenRegister credential broker**
  in v1 (D-KEY) — tracked as a shared follow-on with `digikoppeling-adapter`.
- **Not building the scholiq-side caller.** `POST /api/eudi/credential-offers`
  is a contract this change guarantees; the consuming app's own change
  (scholiq's `eudi-wallet-credential-push`) that calls it is out of scope
  here (proposal.md).

## DEFERRED_QUESTION

- Does the ADR-037 register-fragment loader actually merge
  `components.schemas` (not just `components.objects`) into the effective
  register descriptor at load time? Every existing fragment only exercises
  the `objects` half. Resolve during implementation (tasks.md) by tracing
  the loader before writing the fragment's final shape; if unsupported,
  fall back to a documented, deliberate one-line schema-count bump in
  `openconnector_register.json` rather than silently shipping schemas the
  loader never picks up.
