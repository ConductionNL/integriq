# Design — source calls through the OpenRegister credential broker

## Context

Verified against HEAD (`origin/development`, 1836d589):

- `CallService::call()` (`lib/Service/CallService.php:1351`) runs in phases:
  merge source configuration (`mergeSourceConfiguration()`, Phase 7) →
  normalise (`normaliseRequestConfig()`, Phase 9: Twig render via
  `renderConfiguration()`, cert materialisation via `getCertificate()`, strip
  every key containing `authentication`, force `http_errors=false`) → dispatch
  (`dispatchRequest()`, Phase 10: SOAP delegate or
  `$this->client->request($method, $url, $config)`; `ConnectException` →
  synthetic 503 `Response`) → envelope (`buildResponseData()`, Phase 11,
  consumes `\Psr\Http\Message\ResponseInterface`) → persist
  (`buildAndPersistCallLog()`, Phase 12). Early config errors go through
  `saveEarlyErrorLog()` (synthetic 409/429 CallLogs).
- Secrets enter outbound requests two ways today: literal header values in
  `configuration.headers` (e.g. `Ocp-Apim-Subscription-Key`), and Twig
  functions (`oauthToken`/`jwtToken`/`decosToken` in
  `lib/Twig/AuthenticationRuntime.php`) that read
  `configuration.authentication` and mint tokens via
  `AuthenticationService`.
- Synchronizations call the engine via
  `SynchronizationService::callSourceObject()` →
  `$this->callService->call(...)`; pagination fetches one page per call
  (`fetchSinglePageData()`). Background execution is `lib/Cron/JobTask.php`
  → `JobService` — no user session.
- The broker exists on OR development:
  `OCA\OpenRegister\Service\Credential\CredentialBrokerService::request(string
  $credentialId, string $appId, string $method, string $path, array
  $headers=[], ?string $body=null): array{status: int, headers: array, body:
  string}` — guard chain: (1) owner IDOR via `loadOwnedCredential`, (2)
  allowedApps, (3) provider allowRules (method + normalised path), (4)
  host-lock — then injects the auth scheme server-side and performs the call
  via `IClientService`. Throws `CredentialAccessDeniedException` (fail-closed
  403) and `CredentialUpstreamException` (transport 502). Credential metadata
  lives in OR schema `brokeredcredential`.

Signed-off decisions carried into this design (not re-litigated here):
`credentialRef` shape, in-process brokered dispatch with guard chain,
pagination/retry staying in OpenConnector, acting-user = credential owner for
background jobs, absolute secret hygiene, soft-fail with no embedded-secret
fallback.

## ADR-031 exception note

ADR-031 (schema-declarative business logic) prefers declarative dialects over
imperative service code. This change is a justified exception: it is an
**external integration** — the outbound HTTP call engine is already an
imperative service (`CallService`), the broker is an imperative constrained
proxy by design (the secret must be injected server-side at dispatch time),
and there is no declarative dialect that could express "route this request
through another app's guard chain". The *configuration* surface stays
declarative (`credentialRef` in source config); only the dispatch plumbing is
imperative, inside the engine that is already the imperative seam.

## Decisions

### D1 — Hook point: a brokered branch inside `dispatchRequest()`

Brokered dispatch is a third branch in `dispatchRequest()` alongside SOAP and
Guzzle, selected when the (already merged) source configuration carries
`authentication.credentialRef`. Everything before (method resolution, source
enable/location guards, rate-limit short-circuit, config merge, Twig render of
NON-auth fields, pagination→query mapping) and everything after
(`buildResponseData()`, `buildAndPersistCallLog()`, `sourceRateLimit()`,
postRequest hooks) is unchanged. Rationale: one write path — the CallLog
contract, redaction, retention, and rate-limit logic must not fork.

The brokered branch lives in a new small service
(`lib/Service/BrokeredCallService.php`, name final at implementation) so
`CallService` only gains the branch and the config-error guards; the OR
coupling (class resolution, name resolution, exception mapping, response
adaptation) is testable in isolation.

### D2 — In-process resolution with `class_exists` + `IAppManager` guard

The broker is resolved in-process:
`class_exists(\OCA\OpenRegister\Service\Credential\CredentialBrokerService::class)`
AND `IAppManager::isEnabledForUser('openregister')` (mirroring the existing
OR consumption pattern in `lib/Service/ObjectService.php`), then
`\OCP\Server::get(...)`. When either check fails and a source carries a
`credentialRef`, the call fails soft: a synthetic config-error CallLog via
`saveEarlyErrorLog()` with an actionable message. No HTTP loopback, no
fallback to embedded secrets.

### D3 — Response adaptation: broker array → PSR-7

The broker returns `array{status, headers, body}`. The brokered branch adapts
it to a `GuzzleHttp\Psr7\Response` before returning from `dispatchRequest()`,
so `buildResponseData()` (which consumes `ResponseInterface`) and the whole
CallLog pipeline are untouched. Upstream non-2xx statuses returned by the
broker are COMPLETED calls (the broker's own contract) and flow through as
today's non-2xx Guzzle responses do.

### D4 — Request derivation: path+query only, provider host governs

The broker accepts a provider-relative `path`, never a full URL, and resolves
the target from the provider catalogue's host-lock. The brokered branch
therefore composes the URL exactly as today (`location` + `endpoint`), then
passes only its path + query-string portion (with `config['query']`
serialised into the query string, since the broker signature carries no query
array). The provider host from the catalogue is authoritative; the source
`location` host is configuration documentation. A mismatch surfaces naturally
as a broker host-lock/allowRules refusal — deliberately NOT pre-validated in
OpenConnector, so there is exactly one authority for "where may this
credential call".

Headers passed are the normalised `config['headers']` (the broker overrides
the auth header — it is broker-controlled). Body is `config['body']` verbatim
or JSON-encoded `config['json']`.

### D5 — `credentialName` convenience resolution

`{"credentialName": "..."}` is resolved at call time against the credential
owner's OR `brokeredcredential` metadata (in-process OR object query filtered
to the acting user's credentials). Exactly one match → its `credentialId` is
used. Zero or multiple matches → hard config error (synthetic config-error
CallLog naming the count), never a guess. Resolution is per-call with no
persistent cache (a per-run memoisation inside one synchronization run is an
implementation freedom; cross-run caching is not, since ownership and names
can change).

### D6 — Acting user

Interactive calls (source test endpoint, manual sync trigger): the session
user is the acting user; the broker's existing `IUserSession`-derived owner
guard applies as-is. Background jobs (`JobTask` — no session): OpenConnector
passes `actingUserId` = the credential's owner via the broker's new optional
acting-user parameter for in-process trusted callers (cross-repo dependency:
OR change `credential-doriath-leaf`). The broker still enforces allowedApps
(`openconnector` must be listed), allowRules, and host-lock — acting-user
only substitutes the session identity, it does not bypass any guard.

### D7 — Error mapping (all secret-free)

| Condition | CallLog |
| --- | --- |
| Broker classes absent / openregister disabled | 409 config error via `saveEarlyErrorLog()` — "credentialRef configured but the OpenRegister credential broker is unavailable" |
| Referenced credential gone / not owned / name ambiguous | 409 config error naming the ref (id or name), never the payload |
| Sibling embedded secret fields next to `credentialRef` | 409 config error — embedded secrets are forbidden with credentialRef |
| `CredentialAccessDeniedException` | 403 CallLog, statusMessage carries the guard name surfaced by the broker (e.g. `allowedApps`) plus the actionable hint to add `openconnector` to the credential's allowedApps — never the request payload or secret |
| `CredentialUpstreamException` | 502 CallLog (mirrors today's `ConnectException` → 503 synthetic pattern, distinct code because the transport failed inside the broker) |
| Upstream non-2xx via broker | Normal CallLog with the upstream status (completed call) |

409 for config errors keeps consistency with the engine's existing
`saveEarlyErrorLog()` guard family (disabled source / missing location are
409 today).

### D8 — Scope exclusions (v1)

- **SOAP sources**: `credentialRef` on a `type: soap` source is a 409 config
  error — the SOAP transport bypasses the Guzzle path entirely.
- **Asynchronous dispatch**: `asynchronous=true` with `credentialRef` is a
  409 config error — the broker call is synchronous in-process; no caller in
  HEAD combines async with a to-be-brokered source.
- **TLS client-certificate config** (`cert`/`ssl_key`) alongside
  `credentialRef`: 409 config error — outbound TLS identity is the broker's
  concern once brokered.
- The legacy embedded-secret path is untouched for sources without
  `credentialRef`; migrating existing sources is per-app work (see spectr
  cross-repo dependency).

## Risks

- **Version skew with OR** — an older OR without the acting-user parameter
  breaks only the background-job path; interactive brokered calls still work.
  Implementation must feature-detect the parameter (reflection on the method
  signature) and fail background dispatch with the soft-fail config error, not
  a TypeError.
- **Guard-name surface** — D7 assumes broker refusals expose a secret-free
  guard identifier; that surface is part of OR `credential-doriath-leaf`. If
  it lands later, the 403 CallLog carries the generic refusal message until
  then.
- **Per-page broker overhead** — every sync page is one brokered in-process
  call; the added cost is guard checks + one OR object read per page. If it
  shows up in profiles, memoise credential metadata per synchronization run
  (D5 already permits this).
