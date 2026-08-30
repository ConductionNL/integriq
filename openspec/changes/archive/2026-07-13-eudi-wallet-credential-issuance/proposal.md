---
kind: code
depends_on: []
---

## Why

**The regulatory deadline is fixed and openconnector has zero code for it.** Insight 1150
(legal-requirement, impact=high): "Regulation (EU) 2024/1183 (adopted 26 Mar 2024, in force
20 May 2024) requires every member state to offer at least one certified EUDI wallet to
citizens by end of December 2026; wallets hold PID plus electronic attestations of
attributes explicitly including diplomas and professional qualifications, piloted for
education by the DC4EU large-scale pilot... Issuing/sealing adapter belongs in openconnector
or a dedicated trust-service integration; scholiq owns the credential source data." A
repo-wide grep for `OpenID4VCI|openid-credential-issuer|credential_offer|pre-authorized_code`
across `lib/`, `openspec/specs/`, and `openspec/changes/` in this openconnector worktree
returns zero hits — the wire-protocol adapter insight 1150 names does not exist at HEAD.

**Scholiq already signs and serves the credential content this adapter needs to carry — the
gap is the wallet wire protocol, not the credential model.** In the scholiq worktree (sibling
repo, read for grounding only): `certification/spec.md:36,41` — "The system MUST issue
EDCI / Europass credentials and Open Badges 3.0 with verifiable URLs" — is `status: done`.
`lib/Service/CredentialSigningService.php` (scholiq) already builds and RS256-signs an OB3
JSON-LD assertion per tenant, using a proven pattern: `lib/Service/KeyManagementService.php`
(scholiq) generates a per-tenant RSA-2048 keypair via `openssl_pkey_new`, encrypts the
private key at rest with `OCP\Security\ICrypto::encrypt()`, stores the plain public key +
a SHA-256 fingerprint (`kid`) in `IAppConfig`, and on rotation archives the previous public
key (verification-only, capped at 32 entries) so credentials signed under a retired key
remain verifiable — `KeyManagementService.php:138-140,225-266`. But
`certification/spec.md:28` only says a certificate is "pushed to the learner's wallet within
30 seconds" — no protocol is named — and `certification/spec.md:68` explicitly scopes out
"Cross-institution badge wallet (handled by edubadges.nl federation)", which is a *different*
mechanism (edubadges federation) from an EUDI-certified wallet under eIDAS 2.0. Standards
row EDCI (nl_standards id 489): "sealed with eSeals, expressed in the European Learning
Model (ELM v3, ~500 properties, W3C Verifiable Credentials aligned)... issuing/sealing
adapter in openconnector." Rows ELM (527) and Europass (526) repeat the same routing
decision verbatim. Row Open-Badges (223, v3.0): "rebuilt on W3C Verifiable Credentials
(aligning with EDCI/ELM), portable across platforms and wallets." Insight 931: "competitor
LMSs (Moodle, Brightspace) only partially support EDCI today" — a first-mover window tied to
a hard end-2026 date, not a discretionary feature.

**openconnector's existing key-management posture is honestly plaintext-pending-encryption,
and its one precedent for in-process external signing already fails closed rather than
invent a parallel vault.** `openspec/architecture/adr-007-source-credentials-stored-plaintext-pending-encryption.md`
records that `Source` credentials are stored plaintext today; `adr-016-encryption-service-design.md`
specifies a planned `EncryptionService` that **does not exist in code yet** (confirmed: no
`OCA\OpenConnector\Service\EncryptionService` class, no `ICrypto` usage anywhere under
`lib/` — `grep -rl ICrypto lib/` returns zero files). The one place openconnector needs a
private key in-process today, Digikoppeling WS-Security signing, resolves it through
`lib/Adapters/Digikoppeling/PkiOverheidCredentialResolver.php`, which is deliberately
**fail-closed**: it looks for an `issueSigningMaterial` method on OpenRegister's
`CredentialBrokerService` by reflection and throws `DigikoppelingException` naming the exact
missing capability when absent (`PkiOverheidCredentialResolver.php:105-116`) — "the
constrained-proxy broker... cannot, by design [hand back raw key material]... This is the
honest, spec-compliant behaviour (REQ-DK-005), not a stub" (same file, docblock lines 12-20).
That capability does not exist on the broker today, so an EUDI issuer key that depended on it
would ship unable to sign anything. This change therefore does NOT route the new issuer
signing key through the broker; see design.md D-KEY for the alternative this proposal takes
instead (a self-contained `ICrypto`-based key service, mirroring scholiq's proven pattern,
not the broker).

**openconnector already has every inbound-endpoint and consumer-gating primitive this
adapter needs — none of them are EUDI-aware.** `openspec/specs/consumer-management/spec.md`
REQ-CON-001 already enforces `authorizationType` (none/apiKey/jwt/basic/oauth2) on inbound
calls scoped to a registered `consumer` record; `openspec/specs/authorization-jwt/spec.md`
REQ-001 already verifies JWT bearer tokens against a consumer-registered JWK
(`AuthorizationService`). `lib/Controller/DSOController.php` is the existing precedent for a
`#[PublicPage]`/`#[NoCSRFRequired]` inbound wire-protocol receiver with routes commented out
in `appinfo/routes.php:20-34` until real signature verification lands — the same
fail-closed discipline this change follows for the wallet-facing endpoints.
`lib/Service/WebhookSignatureService.php` already HMAC-signs outbound deliveries
(`X-OpenConnector-Signature`) with a generate-once/rotate/redact secret lifecycle
(`openspec/specs/webhook-signing/spec.md` REQ-WHS-001/002) — the mechanism this change reuses
for the "status callbacks" leg of the app-facing contract, instead of inventing a second
signing scheme.

**Adding new persisted state must not collide with the register's own coverage gate.**
`openspec/specs/openconnector-register-schema/spec.md` REQ-A-002: "the descriptor file is
loaded... THEN exactly 15 schema entries MUST exist" against
`lib/Settings/openconnector_register.json`. `lib/Settings/register.d/README.md` documents
the escape valve this change uses instead of editing that file: "ADR-037: per-OpenSpec-change
register fragments are merged here at load. Each change adds its own `<change>.json` (OpenAPI
`components.schemas`/`paths`) instead of editing `openconnector_register.json` — concurrent
builds never conflict." Every existing fragment under `lib/Settings/register.d/*.json` today
only carries `components.objects` (seed data), never `components.schemas` — this change is
the first to exercise the schema-declaring half of that documented mechanism (flagged as a
DEFERRED_QUESTION below).

**Architecture guardrail this design must respect.** `openspec/architecture/adr-017-information-architecture.md`
Rule 1: a new adapter family "MUST NOT become a new top-level nav item, a new settings page,
or a sibling of *Adapters*" — it ships as an *Adapters* catalogue card. Rule 3 sanctions
exactly the split this change needs: "New auth-broker adapter... catalogue entry in
*Adapters*... **and** broker config in *Beheer > Authenticatie* (tenant-wide)" — the same
shape already used for `digid-eherkenning-auth-adapter` (Rule 7's first sanctioned split).

**Consumer leaf (prose note only — not part of this change's scope).** Per the task brief,
scholiq's certification leaf (`certification/spec.md`) will need a small follow-up change
(tracked as scholiq's `eudi-wallet-credential-push`, not yet created at the time of writing)
that calls this adapter's offer-creation endpoint with a `Credential.openbadges3Payload` /
future EDCI payload + subject, and records the returned offer URL/QR and status callbacks
against the `Credential` object. That change is out of scope here; this proposal only
guarantees the app-facing contract (endpoint shape, auth, callback shape) it will consume.

## What Changes

- **New capability `eudi-wallet-credential-issuance`**: an OpenID4VCI (pre-authorized code
  flow) Credential Issuer surface that turns an already-assembled, already-signed W3C
  Verifiable Credential payload (EDCI/ELM-profiled diploma or Open Badges 3.0 assertion,
  handed over by a consuming app such as scholiq) into a wallet-consumable credential offer,
  serves the OpenID4VCI issuance endpoints an EUDI wallet calls, and publishes a revocation
  status list.
- **Adapters catalogue entry** (ADR-017 Rule 1): `EudiWalletAdapter` descriptor — supported
  credential configurations (`credential_configuration_id` → format + claims schema),
  no top-level menu, no `/beheer` page for the adapter itself.
- **Beheer > Authenticatie: EUDI issuer key section** (ADR-017 Rule 3 sanctioned split,
  mirrors `digid-eherkenning-auth-adapter`): admin-gated key generation/rotation for the
  issuer's own ES256 signing keypair, modeled directly on scholiq's
  `KeyManagementService`/`KeyAdminController` pair — `ICrypto`-encrypted private key at rest,
  plain public JWK + `kid` (SHA-256 fingerprint) for verification, rotation archives the
  previous public key so credentials already issued under it remain verifiable. Scoped per
  OpenRegister Organisation via the existing `organisation-bridge` soft-fail accessor, with a
  single default-organisation key when OR/organisation-bridge is unavailable.
- **`EudiWalletController`** (new, mirrors `DSOController`'s inbound-receiver shape):
  - `GET /.well-known/openid-credential-issuer` — issuer metadata (credential_issuer id,
    credential_endpoint, token_endpoint, credential_configurations_supported, JWKS).
  - `POST /api/eudi/credential-offers` — consumer-gated (REQ-CON-001 + authorization-jwt
    REQ-001) app-facing endpoint: accepts `{credentialPayload, format, subjectId, consumerId}`,
    persists an offer, mints a single-use pre-authorized_code, returns
    `{offerUrl, credentialOfferUri, qrPayload}`.
  - `GET /api/eudi/credential-offers/{id}` — public, single-fetch `credential_offer_uri`
    resolution target (short TTL, single-use marker).
  - `POST /api/eudi/token` — public, pre-authorized_code grant
    (`urn:ietf:params:oauth:grant-type:pre-authorized_code`), single-use code, issues an
    opaque access_token + c_nonce.
  - `POST /api/eudi/credential` — public, Bearer + wallet key-proof (`proof.jwt`) verification,
    returns the Credential Response. For `format:"jwt_vc_json"` the response wraps the
    already-signed VC-JWT the consuming app handed over verbatim (no re-signing — see
    design.md D-KEY). For `format:"dc+sd-jwt"` openconnector mints and signs a fresh SD-JWT VC
    with its own issuer key (the actual "sealing" responsibility in scope).
  - `GET /api/eudi/status-lists/{id}` — public, publishes the OAuth Status List token
    (`draft-ietf-oauth-status-list`, bitstring, purpose=revocation only in this change).
  - `POST /api/eudi/credential-offers/{id}/revoke` — consumer-gated, flips the assigned
    status-list bit and fires an HMAC-signed status callback (reusing
    `WebhookSignatureService`, same `X-OpenConnector-Signature` scheme as REQ-WHS-001) to the
    owning consumer's configured callback URL.
- **`lib/Cron/EudiStatusListRefreshJob.php`** (new, mirrors `lib/Cron/EventRetryJob.php`'s
  shape): periodically re-signs the published Status List Token before its own `exp`.
- **Register fragment `lib/Settings/register.d/eudi-wallet-credential-issuance.json`** (ADR-037
  mechanism, NOT an edit to `openconnector_register.json` — see Why): declares three new
  schemas — `eudi_credential_offer`, `eudi_issuance_session`, `eudi_status_list`.
- **Explicitly NOT in scope** (see design.md Non-goals): acting as a PID Provider; wallet
  attestation / WSCA-WSCD verification; Relying Party / credential *verification* flows;
  registering openconnector's issuer key on a national eIDAS Trusted List (an organisational/
  legal process, not a code change) — the key generated here is a technical signing key, not
  a Qualified certificate; suspension status (2-bit status list) — only single-bit revocation
  ships in this change.

## Impact

- **New:** `lib/Controller/EudiWalletController.php`, `lib/Service/EudiIssuerKeyService.php`,
  `lib/Service/EudiCredentialOfferService.php`, `lib/Service/EudiStatusListService.php`,
  `lib/Controller/EudiIssuerKeyAdminController.php`, `lib/Cron/EudiStatusListRefreshJob.php`,
  `lib/Settings/register.d/eudi-wallet-credential-issuance.json`.
- **Modified:** `appinfo/routes.php` (new `eudi*` routes, `#[PublicPage]` on the
  wallet-facing four per the fail-closed discipline `DSOController` already documents),
  `lib/Settings/OpenConnectorAdmin.php` (new Beheer > Authenticatie section), the Adapters
  catalogue index (new `EudiWalletAdapter` card + *Nieuwe verbinding* wizard entry per Rule 1).
- **Depends on (not blocking, documented):** OpenRegister's `CredentialBrokerService`
  gaining an `issueSigningMaterial` capability would let a future change migrate the issuer
  key off `ICrypto`-in-appconfig onto the broker, matching `PkiOverheidCredentialResolver`'s
  posture; not required to ship this change.
- **Consumer leaf (out of scope, prose only):** scholiq's future `eudi-wallet-credential-push`
  change is the caller of `POST /api/eudi/credential-offers`.
