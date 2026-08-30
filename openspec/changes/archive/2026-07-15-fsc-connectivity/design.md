# Design: fsc-connectivity

## Architecture Overview

```
   OpenRegister object / a sibling app's own request
         │ POST /api/fsc/call {organisation, service, method, payload}
         ▼
   FscController::call()
         │  ActionAuthService::requireAction("fsc.call")
         ▼
   FscCallService::callService()
         │
         ├─ 1. resolveActiveSource()  ── the active `type=fsc` Source (own-organisation id,
         │                                directory config, provider selection)
         │
         ├─ 2. resolveProvider(configuration)  ── FscConnectivityProviderInterface
         │        ◄── LogFscConnectivityProvider (default, dry-run, no network)
         │        ◄── FscDirectoryClient (REST — directory lookup + outway call, one class)
         │
         ├─ 3. provider->resolveService(directoryConfig, organisation, service)
         │        └─ FscDirectoryException  when organisation/service unknown
         │        └─ on success: cache the resolution as an `fsc_service` OR record
         │           (upsert by organisation+service)
         │
         ├─ 4. provider->call(directoryConfig, resolution, method, payload)
         │        └─ FscConnectivityException  on transport/config failure
         │
         └─ 5. persist one `fsc_call` OR record (organisation, service, method, status,
              ref, error, syncedAt) — always, success or failure

   GET /api/fsc/services  ── FscController::listServices() returns the current
   `fsc_service` cache (organisations/services this instance has already resolved),
   letting a sibling app discover what it can call before attempting POST /api/fsc/call.
```

Unlike `iwmo-ijw-adapter` / `kiss-kcc-bridge`, FSC connectivity has **no inbound
leg** in this change — the calling organisation always initiates (outway → inway
is a request/response tunnel, not an asynchronous retour). There is therefore no
signed webhook receiver and no retry `TimedJob`: a failed call is reported
synchronously to the caller (`fsc_call` record persisted either way, for
observability), and retrying is the calling sibling app's own responsibility
(mirrors `PeppolController::participants` being a synchronous lookup, not
`PeppolController::inbound`'s async webhook).

## FSC concept mapping (READ THIS FIRST)

**No live FSC (Federatieve Service Connectiviteit) network was available in
this environment to verify against — stated explicitly, not pretended
otherwise.** Every endpoint, field name, and header below is a documented
assumption, grounded in the publicly published VNG/Common Ground FSC concept
model (FSC replaced NLX in 2025 as the standard inter-organisation
connectivity layer for Dutch government) and this app's own already-shipped
provider-seam precedent (`kiss-kcc-bridge`, `iwmo-ijw-adapter`,
`peppol-access-point-connector`). If the real FSC wire format diverges, the
fix is isolated to `FscDirectoryClient` alone — `FscCallService` and
`FscController` are unaffected (same provider-seam argument as every prior
VNG-dialect connector in this app).

| FSC concept (published) | This change's mapping | Assumption / gap |
| --- | --- | --- |
| **Directory** — a federation-wide catalogue of organisations and the services they publish | `FscDirectoryClient::resolveService()` queries `{directoryUrl}/organisations/{organisation}/services/{service}` (assumed REST shape — see "Directory API shape" below) | No live FSC Directory endpoint exists to verify the real request/response shape against; this is a reasonable REST modelling of "look up one service by organisation+service id", not a verified excerpt of a published OpenAPI spec |
| **Organisation** — an OIN/KVK-identified federation member | `organisation` string (assumed to be an OIN or comparable federation-scoped identifier — this change does not validate its format) | Format validation deferred — see Open Questions |
| **Service** — one published API a member organisation offers to the federation | `service` string (a service identifier scoped to its owning organisation) | Service *versioning* (e.g. semver on a published service) is out of scope |
| **Outway** — the calling organisation's local gateway process; establishes the mTLS tunnel to the target's Inway and proxies the call | `FscDirectoryClient::call()` — in a REAL FSC deployment, application code does NOT build the mTLS tunnel itself; it makes a plain authenticated HTTP call to its own locally-running Outway process (typically `http://outway:8080/{organisation}/{service}/{path}`), and the Outway transparently handles directory resolution + mTLS. This change's `FscDirectoryClient` therefore plays the role application code would normally delegate to a local Outway sidecar — see "Outway/mTLS deviation" below | **This is the single biggest documented gap** — no Outway process exists in this environment; `FscDirectoryClient` calls the resolved endpoint directly over token-authenticated HTTPS instead |
| **Inway** — the receiving organisation's gateway; terminates mTLS, enforces the grant, forwards internally | Not implemented — OpenConnector is a caller (Outway-side) in this change, never a receiver (Inway-side); publishing OpenConnector's own services INTO an FSC federation is out of scope, see below | Explicit out-of-scope, not a silent omission |
| **Grant** — per-organisation, per-service authorization the receiving organisation issues to a specific calling organisation | `resolution['grantRequired']` (boolean, surfaced from the directory lookup) — this change does NOT perform grant provisioning or verification; it only reports whether the directory says a grant is required, so a caller/operator knows to arrange one out-of-band | Grant issuance/verification is entirely out of scope — mirrors how `iwmo-ijw-adapter` deferred mTLS cert provisioning |

## Outway/mTLS deviation, stated explicitly

Real FSC connectivity between organisations is secured end-to-end by **mutual
TLS with organisation-issued (PKIoverheid-chained) client certificates** —
never a static bearer token. A calling application normally never touches
this directly: it talks to its own local Outway process over plain HTTP, and
the Outway (a separately deployed, separately certificate-provisioned
process) performs the actual mTLS handshake to the target's Inway.

`FscDirectoryClient` in this change implements **token-authenticated HTTPS
only** — the same deliberate, explicitly-flagged deviation
`iwmo-ijw-adapter`'s `IStandaardenClient` and
`kiss-kcc-bridge`/`peppol-access-point-connector`'s REST bindings already
made for their respective VNG-dialect standards, because:

1. No live Outway/Inway pair exists in this environment to validate a real
   mTLS handshake against.
2. Guzzle's client-certificate options (`cert`/`ssl_key`) are a
   deployment-time concern (the actual PEM material and its provisioning
   pipeline), not a translation/routing-logic concern this change can safely
   invent.
3. The provider seam (`FscConnectivityProviderInterface`) isolates this
   entirely — a future `OutwayProxyClient` binding that instead talks to a
   locally-running Outway sidecar over plain HTTP (the architecturally
   correct production shape) is a drop-in replacement requiring zero change
   to `FscCallService` or `FscController`.

This is flagged as the primary "Open Questions" item below, not a silent
omission — see "Cert-exchange / Outway-Inway provisioning" there.

## Directory API shape (assumed)

```
GET {directoryUrl}/organisations/{organisation}/services/{service}

200 OK
{
  "organisation": "00000001823288444000",
  "service": "brp-bevragen",
  "endpoint": "https://outway.gemeente-x.example.nl/00000001823288444000/brp-bevragen",
  "publicKeyFingerprint": "sha256:...",
  "grantRequired": true
}

404 Not Found  — organisation or service is not published in the directory
5xx            — directory itself unreachable/erroring
```

`endpoint` is the directory's advertised routable address for the service —
in a real deployment this is the target organisation's Inway address (reached
via the caller's own Outway); in this change it is called directly. The
response shape is this change's own reasonable modelling of "resolve one
service", not a verified excerpt of a published FSC Directory OpenAPI spec —
explicitly stated, see "FSC concept mapping" above.

## Provider seam, credential storage, feature gating

Mirrors `iwmo-ijw-adapter`/`kiss-kcc-bridge` exactly:
`FscConnectivityProviderInterface` (`getProviderId`, `getConfigSchema`,
`resolveService`, `call`) is implemented by `LogFscConnectivityProvider`
(sandbox — no network, resolves against the source's own
`configuration.directory.knownServices` static list so `found`/
`unknown-organisation`/`unknown-service` are all demonstrable without a live
directory; synthetic `FSC-MOCK-<n>` refs; default for dev/CI) and
`FscDirectoryClient` (generic REST binding — the ONE class housing every HTTP
and credential operation this change performs: both the directory lookup GET
and the downstream service-invocation call, so a future switch to a real
Outway sidecar or a client-cert-aware binding touches exactly one file).
`configuration.provider` selects the binding (`log`|`rest`), default `log` —
an unconfigured source (none active, or `type=fsc` absent) makes
`callService()`/`listResolvableServices()` report a clean `not_configured`
503/empty result, no HTTP attempted (same convention as every other leaf
connector in this app).

**Credential storage**: `configuration.authentication.encryptedToken`,
ENCRYPTED AT REST via `OCP\Security\ICrypto`, decrypted in-process only for
the instant needed to build each request's Authorization header — same
already-accepted deviation from `credentialRef`/`BrokeredCallService` that
`kiss-kcc-bridge`/`iwmo-ijw-adapter`/`notifynl-sms-channel` document (identity-
only receiver registry, no EncryptionSuite access — see those changes'
design.md "Provider seam" sections). This stores a bearer-style stand-in
token, NOT the real mTLS client certificate material a production FSC Outway
would need — see "Outway/mTLS deviation" above.

## Routing: `FscCallService`

`callService(array $input): array` — `$input` is `{organisation, service,
method, payload}` (`method` defaults to `POST` when absent):

1. `resolveActiveSource()` — the single active `type=fsc` Source
   (`isEnabled=true`); throws `FscConnectivityException` ("No active FSC
   source is configured...") when none exists — the controller maps this to
   HTTP 503 `not_configured`.
2. `resolveProvider(configuration)` selects `log` (default) or `rest`.
3. `provider->resolveService($configuration['directory'] ?? [], $organisation,
   $service)` — throws `FscDirectoryException` for an unknown organisation or
   service (differentiated by message, not by a separate exception class —
   mirrors how `IwmoIjwProviderException` differentiates `not_configured` by
   message substring rather than a taxonomy of exception subclasses).
4. On successful resolution, upsert an `fsc_service` OR record (find an
   existing row for the same `organisation`+`service`, else create) — this is
   the "directory entries cache" persistence requirement: repeated resolves
   against the same organisation/service update the SAME cached row rather
   than accumulating duplicates, and `listResolvableServices()` reads this
   cache.
5. `provider->call($configuration['directory'] ?? [], $resolution, $method,
   $payload)` — on `FscConnectivityException`, persist an `fsc_call` record
   with `status: failed` and rethrow (controller maps to 502); on success,
   persist `status: sent` with the returned `ref`.
6. Return `['ref' => ..., 'statusCode' => ..., 'body' => ...]`.

Per-call isolation (task brief's "per-call isolation" requirement): each
`callService()` invocation is independent — one call's resolution or
transport failure never affects another call's ability to succeed
afterwards (no shared mutable state between calls beyond the `fsc_service`
cache, which is additive/upserting, never a blocking gate).

`listResolvableServices(): array` — returns every cached `fsc_service` row
for the active source (empty array when unconfigured, never an exception —
this is a read, not a call attempt, so there is nothing to fail "cleanly"
about beyond returning nothing).

## Persistence: `fsc_service` (directory cache) + `fsc_call` (call log)

- **`fsc_service`**: one row per (organisation, service) pair this instance
  has successfully resolved — `organisation`, `service`, `endpoint`,
  `grantRequired`, `resolvedVia` (`cache`|`directory`|`log`), `resolvedAt`.
  Upserted (never duplicated) by `FscCallService` after every successful
  `resolveService()` call. This is genuinely a **cache**, not a live mirror
  of the federation directory — a resolved entry can go stale if the target
  organisation changes its published endpoint; this change does not
  implement cache invalidation/expiry (see Open Questions).
- **`fsc_call`**: one row per `callService()` attempt (never merged/updated
  after the fact — mirrors `iwmo_ijw_message`'s per-attempt audit
  convention) — `organisation`, `service`, `method`, `status`
  (`sent`|`failed`|`unresolved`), `ref`, `error`, `syncedAt`.

Both are ordinary mutable OR schemas (not `appendOnly`/`immutable` — same
precedent as `iwmo_ijw_message`/`kiss_klantcontact`/`sms_message`/
`payment_intent`, all of which are per-attempt audit rows without the
5-year-retention append-only requirement the 4 original `*_log` schemas
carry).

## REST surface

- `GET /api/fsc/services` — authenticated NC-session read of the current
  `fsc_service` cache (`FscCallService::listResolvableServices()`). Gated by
  `ActionAuthService::requireAction("fsc.list")`. Returns `[]` when
  unconfigured (never a 503 — listing an empty catalogue is not an error).
- `POST /api/fsc/call` — authenticated NC-session invocation
  (`FscCallService::callService()`). Gated by
  `ActionAuthService::requireAction("fsc.call")`. Missing `organisation` or
  `service` → 400 `missing_fields`. No active source → 503
  `not_configured`. Unknown organisation/service (`FscDirectoryException`) →
  404 `unknown_service`. Any other transport/config failure
  (`FscConnectivityException`) → 502 `fsc_call_failed`.

Both endpoints are plain authenticated NC-session calls (no HMAC/webhook —
there is no inbound leg to verify, see "Architecture Overview" above).

## How sibling apps consume this (cross-app contract, not implemented here)

- To reach another organisation's published service through FSC:
  `POST /api/fsc/call {organisation: <OIN or federation id>, service:
  <service id>, method: "GET"|"POST"|..., payload: {...}}`. The response's
  `ref` SHOULD be stored on the consuming app's own record for correlation
  (mirrors how procest stores a NotifyNL `providerMessageId`).
- To discover what this instance can currently reach without attempting a
  call: `GET /api/fsc/services`.
- A `not_configured` (503) response means no active `type=fsc` source
  exists — treat as log-and-skip, not a citizen-facing error (same
  convention as every other leaf connector).
- An `unknown_service` (404) response means the directory (or, in `log`
  mode, the configured `knownServices` stand-in) does not recognise that
  organisation/service pair — the consuming app should not retry blindly.

## Alternatives considered

- **Modelling FSC as a `Source.type=api` dispatched through the existing
  generic `CallService`** was considered (maximal reuse). Rejected: FSC's
  two-phase resolve-then-call shape (directory lookup separate from the
  actual invocation, with its own failure taxonomy — unknown org/service vs.
  transport failure vs. not-configured) does not fit `CallService`'s
  single-phase HTTP dispatch model, and every other VNG/Common-Ground-dialect
  connector in this app (`kiss`, `iwmo-ijw`, `peppol`, `psd2`) is already
  dispatched directly by its own sync service, never via `CallService` — this
  change follows that established precedent instead of being the first
  exception to it.
- **A dedicated `FscOutwayClient` class separate from `FscDirectoryClient`**
  (one class per HTTP concern) was considered for symmetry with how
  `IwmoIjwSyncService` separates `IStandaardenClient` (transport) from
  translator classes (shape). Rejected here: FSC's "translation" step barely
  exists (payload passes through largely as-is — there is no VNG berichttype
  envelope to build), so there is no second concern to split out; the task
  brief's explicit "keep every HTTP/cert operation in one client class"
  instruction is followed literally — `FscDirectoryClient` owns both the
  directory GET and the downstream call.

## Open Questions

- **The mTLS transport gap itself is CLOSED by
  `mtls-client-certificate-transport` (2026-07-16)**: `FscDirectoryClient::call()`
  (the downstream service invocation, standing in for what a real Outway
  sidecar would transparently provide) now dispatches over a real
  mutual-TLS connection when its source's
  `configuration.authentication.mode=mtls` is configured
  (`ICrypto`-encrypted-at-rest certificate/key/optional passphrase/optional
  CA bundle under `configuration.authentication.mtls`), via the shared
  `OCA\OpenConnector\Service\Mtls\MtlsTransportService`. Directory
  `resolveService()` lookups remain unauthenticated/plain (a real FSC
  Directory sits outside the Outway/Inway mTLS boundary). Token mode
  remains the default and is unchanged.
- **Cert-exchange / Outway-Inway provisioning remains out of scope** and is
  the one part of the original gap that stays operator-side: a real FSC
  deployment still requires (a) this instance's own organisation
  certificate registered in the federation, (b) either a running Outway
  process OR this app's own client certificate configured directly via the
  mTLS transport above, and (c) grants arranged with every target
  organisation. None of the federation onboarding is automated by this or
  any change. This change ships the directory-resolution + call-routing +
  config/persistence layer, with `FscDirectoryClient` standing in for what a
  real Outway sidecar would transparently provide — swapping in a real
  Outway-backed binding later requires no change to
  `FscCallService`/`FscController`.
- **Directory API shape is unverified** — see "Directory API shape" above;
  the exact response schema, error codes, and whether a real FSC Directory
  exposes a "resolve one service" endpoint at all (versus only a bulk
  catalogue listing) are unknowns this change does not resolve.
- **`organisation` identifier format** (OIN vs. KVK vs. some other
  federation-scoped id) is not validated — accepted as an opaque string.
- **`fsc_service` cache staleness/expiry** is not implemented — a cached
  resolution never expires or gets invalidated automatically.
- **Grant provisioning/verification** is surfaced as a read-only
  `grantRequired` flag only — arranging or checking an actual grant is out
  of scope.
