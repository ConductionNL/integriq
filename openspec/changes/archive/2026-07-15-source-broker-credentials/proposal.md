---
kind: code
depends_on: []
---

# openconnector — Sources call external APIs through the OpenRegister credential broker

## Why

Today an OpenConnector Source embeds its API keys and OAuth secrets directly in
its authentication configuration, stored as plain fields on the `source` object
in the register:

- Plain header keys: spectr's `connectors/norway_doffin.json` ships
  `configuration.headers.Ocp-Apim-Subscription-Key: "<PLACEHOLDER>"` — the real
  key would live verbatim in source config.
- Twig-minted tokens: `{{ oauthToken(source) }}` / `{{ jwtToken(source) }}` /
  `{{ decosToken(source) }}` (`lib/Twig/AuthenticationRuntime.php`) read
  `configuration.authentication` — which therefore carries `client_secret`,
  JWT signing secrets, or passwords — and hand them to
  `AuthenticationService` (`fetchOAuthTokens` / `fetchJWTToken` /
  `fetchDecosToken`).

The engine already fights the consequences: `normaliseRequestConfig()` strips
every config key containing `authentication` before dispatch, and
`buildResponseData()` runs four redaction helpers (`collectSecretValues`,
`redactSecretsFromConfig`, `redactSecretsFromUrl`,
`redactSecretValuesFromString`) so live secrets do not land in CallLogs. But
the secret itself still lives in the register object, is exported with
configuration, and is readable by anyone who can read the source.

The company decision is ONE credential system: the OpenRegister credential
broker (`OCA\OpenRegister\Service\Credential\CredentialBrokerService`) — a
constrained proxy where the consuming app NEVER holds the secret and custody
sits in the Doriath vault. OpenConnector must be able to run a Source's
outbound calls THROUGH the broker instead of holding secrets.

## What Changes

- **`credentialRef` in source authentication config**: a Source's
  `configuration.authentication` gains a `credentialRef` object —
  `{"credentialId": "00000000-0000-0000-0000-000000000000"}` (primary) or
  `{"credentialName": "doffin-subscription"}` (convenience). When
  `credentialRef` is present, embedded secret fields are forbidden: any
  sibling secret-bearing authentication field is a hard config error at call
  time, never silently merged.
- **Brokered dispatch in the HTTP call engine**: at call time
  (`CallService::call()` Phase 10, `dispatchRequest()`), a source carrying a
  `credentialRef` is dispatched IN-PROCESS through
  `CredentialBrokerService::request(credentialId, appId: 'openconnector',
  method, path, headers, body)` instead of the internal Guzzle client. The
  broker's `array{status, headers, body}` return is adapted to a PSR-7
  response so `buildResponseData()` → `buildAndPersistCallLog()` and
  `sourceRateLimit()` run unchanged. The broker enforces its guard chain
  (owner IDOR → allowedApps must include `openconnector` → provider
  allowRules on method+path → host-lock) and injects the secret server-side.
- **Pagination / rate-limiting / retry stay in OpenConnector**: each page
  fetched by `SynchronizationService::fetchSinglePageData()` is one brokered
  request; the engine's existing rate-limit tracking keeps working off the
  returned headers.
- **Background jobs without a user session** (`lib/Cron/JobTask.php` →
  `JobService` → `SynchronizationService`): OpenConnector passes
  `actingUserId` = the credential's owner to the broker's new optional
  acting-user parameter for in-process trusted callers.
- **Soft-fail, never fall back**: when the OpenRegister broker classes are
  absent, the app is disabled, or the referenced credential is gone, the call
  fails with a clear config-error CallLog. There is no fallback to embedded
  secrets.
- **Secret hygiene**: the secret value never appears in source config, sync
  logs, call logs, or error messages (with brokering it never enters this
  process at all). Broker refusals (403) are logged as refusals with the
  guard name, not the payload.

## Cross-repo dependencies (prose — tracked in their own repos)

- **openregister `credential-doriath-leaf`** — specs the broker's optional
  acting-user parameter for in-process trusted callers (required for
  background-job dispatch here) and Doriath vault custody. Today
  `CredentialBrokerService::request()` derives the owner solely from
  `IUserSession`.
- **openregister `credential-provider-doffin`** — the provider-catalogue entry
  (host-lock + allowRules) for the Doffin APIM host that the first real
  consumer needs.
- **spectr manifest change** — flips `connectors/norway_doffin.json` from the
  `<PLACEHOLDER>` embedded header to a `credentialRef`, once this change and
  the provider entry ship.

## Impact

- Affected specs: `http-call-engine` (owning capability — status set to
  in-progress with this change listed).
- Affected code (implementation phase, not this change):
  `lib/Service/CallService.php` (`dispatchRequest()` brokered branch +
  config-error guards), a new brokered-dispatch helper service,
  `lib/Service/SynchronizationService.php` (acting-user threading only if
  needed — `callSourceObject()` itself is unchanged), unit tests.
- Not affected: `AuthenticationService` / Twig token minting (legacy path
  stays for sources without `credentialRef`), SOAP dispatch, the CallLog
  shape, endpoint/rule pipelines.
