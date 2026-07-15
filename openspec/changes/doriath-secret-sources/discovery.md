# Discovery: doriath-secret-sources

## Question

How should OpenConnector use Doriath's application secret vault to hold **source
connection secrets** — instead of storing them inline in the source config —
covering both **populating** secrets and **resolving** them at call time, and
what does that require on the OpenConnector and Doriath sides?

## Approach Taken

- Read Doriath's code and docs: `SecretService` (in-process seam),
  `MachineSecretEnvelopeService`, `EncryptionSuiteService`,
  `SecretRequestService`, `appinfo/routes.php`, and
  `docs/integration-openconnector.md`.
- Reviewed OpenConnector's current secret handling: sources are OpenRegister
  objects (register `openconnector`, schema `source`) storing secrets **inline**
  (`apikey`, `password`, `client_secret`, `private_key`, `authenticationConfig`),
  read directly by `AuthenticationService` / `CallService`.

## Findings

### Zero-knowledge model (ADR-003)
Doriath's vault stores and serves **ciphertext only**; the consumer that holds
the application private key decrypts locally (the machine analogue of the
browser). This holds for *both* transports below — OpenConnector always
decrypts client-side.

### Two fetch transports — DI is preferred for same-instance OpenConnector
- **DI in-process seam (preferred):**
  `SecretService::getByNameForApplication(string $name, string $applicationId): ?Secret`
  returns the `Secret` with ciphertext fields — no discovery/JWT/token dance.
  This is the route originally intended for same-instance sister apps; it is
  **not yet documented** in `integration-openconnector.md` (doc gap).
- **HTTP machine API (documented fallback):** discover
  (`/apps/doriath/api/v1/app/.well-known/doriath`) → RS256 JWT-bearer token →
  `GET /app/secrets/by-name/{name}` → `doriath-machine-secret-v1` envelope.
  CI-verifiable via the Newman collection Doriath ships.

Trusting `applicationId` alone on the **read** seam is safe: the returned
ciphertext is useless without the app's private key (no confidentiality risk).

### Reference format and resolution
Source config embeds `doriath://{applicationId}/{folderPath}/{name}`. Secret
**names are API** — renaming a referenced secret breaks configs (loud 404, never
a silent wrong credential). An ambiguous name yields 409 / (DI) a null + warning;
resolve by rename or folder-scoping.

### Decryption
`encryption.scheme = rsa-oaep-sha256-chunked-v1`: base64-decode each field, read a
4-byte big-endian chunk count, RSA-decrypt each 512-byte block, strip OAEP
padding **with SHA-256 by hand** (PHP's `OPENSSL_PKCS1_OAEP_PADDING` hard-codes
SHA-1; the scheme matches WebCrypto `RSA-OAEP` SHA-256 keys). Reusable via
Doriath's `DecryptService` primitives (`rsaDecrypt` / `aesDecrypt` /
`decryptPrivateKey` / `deriveKey`) in-process, or reimplemented against the
versioned contract.

### Key custody
The application private key lives in **OpenConnector's own credential storage
(Nextcloud `ICredentialManager`)** — never in synced/exported source config.
Re-registration after key loss keeps name-based `doriath://` refs working (the
next fetch returns envelopes encrypted to the new cert; detect via the changed
`certificateFingerprint`).

### Population via SecretRequest (write-without-read) — the chosen write path
Rather than OpenConnector writing secret values, it creates a **SecretRequest**
that declares the fields to be filled, e.g. for an Xxllnc zaaksysteem source:
`url` (public) · `api-key` (secret) · `api-interface-id` (additional field). The
request carries a URL-safe **token**; the user fills the values via the public
fill flow (`GET/POST /api/v1/public/secret-requests/{token}[/fill]`), encrypted
to the application's cert. **OpenConnector never handles raw secret values on the
write path.** Field types map to the envelope: `url` → plaintext-safe
`secret.url` metadata, `api-key` → encrypted `key`, `api-interface-id` →
`additionalFields`.

### Doriath-side gaps (owned by this project — decisions made)
Application-initiated request creation is **not** available session-less: it is
exposed only on the user-authenticated OCS route (`POST /api/v1/secret-requests`)
and `SecretRequestService::createForApplication(...)` **requires a `userId`**.
Auto-create-on-import runs without a user session, so Doriath will add:
1. **A DI seam for app request-creation with signature-based identity** — the app
   signs a challenge with its `ICredentialManager` private key; Doriath verifies
   against the registered public cert. `applicationId` alone is insufficient for a
   *mutation* (unlike reads).
2. **A screen listing open fill-links per application** so an admin can find and
   hand out pending fill-links.
3. **Document the DI fetch route** (and the new creation seam) in
   `integration-openconnector.md`.

## Recommendation

- Use the **DI in-process seam** for same-instance OpenConnector (fetch +, once it
  exists, request-creation), with the HTTP machine API as the documented
  fallback.
- Represent source secrets as `doriath://` references; **resolve + decrypt at call
  time** in `AuthenticationService`/`CallService`, in memory only, behind a soft
  dependency with a defined fallback when Doriath is absent.
- **Populate via the SecretRequest fill-link flow** so OpenConnector never touches
  raw values. Two UX entry points: (a) a "create secret request → return fill-link"
  form; (b) **auto-create the request on source import** when the imported source
  declares its Doriath field spec.
- Store the app private key via **`ICredentialManager`**; provision OpenConnector as
  a Doriath application via CSR (one-time).
- Structure as a **`depends_on` chain**: Doriath seams first (request-creation DI +
  identity + fill-links screen + doc update), then the OpenConnector consumer
  change.

## Risks Uncovered

- **Decryption fidelity** — `rsa-oaep-sha256-chunked-v1` must match exactly
  (hand-rolled OAEP-SHA256); a mismatch is a hard decrypt failure. Mitigate by
  reusing Doriath's `DecryptService` in-process and testing against a known
  envelope.
- **Unattended runtime** — background sync jobs decrypt with no human present; the
  private key must be reachable via `ICredentialManager` from a cron/job context
  (no interactive session). Confirm the background-job credential-access model.
- **Rotation is poll-based** (ETag / `updated_since`) — a rotated credential
  propagates at poll cadence. Decide: resolve fresh per run (simplest) vs cache
  with ETag revalidation.
- **Fallback semantics** — if Doriath is absent/down, decide fail-closed (skip
  source + log) vs inline fallback. Wrong choice risks silent auth failure or
  leaking back to inline secrets.
- **Auto-create-on-import identity** — depends on the Doriath app-identity DI seam
  above; blocked until that lands.

## Next Steps

*(Deferred — continue later, per session.)*

1. **Doriath** (this project): add the app request-creation DI seam with
   signature identity; add the open-fill-links screen; document the DI fetch route
   in `integration-openconnector.md`.
2. **OpenConnector**: after the seams land, FF a `doriath-secret-sources` change
   (`depends_on` the Doriath additions) — resolver + `doriath://` ref format +
   `ICredentialManager` custody + CSR provisioning + UX (create-request form +
   auto-create-on-import) + soft dependency/fallback + tests.
3. Decide: reuse Doriath `DecryptService` in-process vs reimplement the envelope
   decrypt.
4. Define the **source field-spec** that declares "this source wants a Doriath
   secret" and its fields (public / secret / additional) to drive
   auto-request-creation.