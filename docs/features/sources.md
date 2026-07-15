# Sources

## Overview

A **Source** is a configured connection to an external system. Sources are the foundation of all outbound communication in OpenConnector. Every API call made through a synchronization, endpoint proxy, or job references a Source for its base URL, authentication, and connection defaults.

## Source Types

| Type | Description | Use Case |
|------|-------------|----------|
| `json` | REST/JSON API | Most modern REST APIs |
| `xml` | REST/XML API | XML-over-HTTP services |
| `soap` | SOAP web service | Legacy government SOAP APIs (StUF, etc.) |
| `ftp` | FTP/SFTP server | File-based integrations |

## Authentication Methods

Sources support multiple authentication strategies configured in the source's `authenticationConfig` field.

### Brokered Credentials (`credentialRef`) — recommended

Instead of embedding an API key or client secret in the source, reference a credential held by the **OpenRegister credential broker**. The secret stays in the broker's vault; OpenConnector never holds it — every call is dispatched in-process through the broker, which enforces its guard chain (credential owner → `allowedApps` → provider allow-rules → host-lock) and injects the secret server-side.

Set `configuration.authentication` to **exactly** one of:

```json
{
  "authentication": {
    "credentialRef": { "credentialId": "00000000-0000-0000-0000-000000000000" }
  }
}
```

```json
{
  "authentication": {
    "credentialRef": { "credentialName": "doffin-subscription" }
  }
}
```

`credentialId` (the credential's UUID) is the primary form; `credentialName` is a convenience resolved at call time against the acting user's credentials — it must match **exactly one** credential, otherwise the call fails with a 409 config error naming the match count (never a guess).

**Hard rules (each violation is a synthetic 409 config-error CallLog; the request is never sent):**

- Any sibling field next to `credentialRef` under `authentication` (e.g. `client_secret`) is **forbidden** — embedded secrets are never merged or dispatched for a brokered source.
- Setting both `credentialId` and `credentialName`, or an empty value, is rejected.
- Not supported in v1: SOAP sources, asynchronous dispatch, and `cert`/`ssl_key` client-certificate config alongside `credentialRef`.
- If the broker is unavailable (openregister disabled or too old) or the credential no longer exists, the call **soft-fails** with an actionable 409 — there is **no fallback** to embedded secrets.

**Operator recipe:**

1. Create the credential in OpenRegister's credential broker (provider, secret, owner). Note its UUID.
2. Add `openconnector` to the credential's `allowedApps` — a broker refusal is logged as a 403 CallLog with this exact hint.
3. Make sure the provider catalogue entry allows the methods + paths your source calls (allow-rules) — the provider's `baseUrl` host is the **sole** authority for where the call goes; the source `location` only documents it and supplies the request path.
4. Replace the source's embedded auth fields with the `credentialRef` block shown above (remove ALL other keys under `authentication`).
5. Test the source: a 200/upstream status means the brokered path works; a 403 CallLog names the broker refusal; a 409 CallLog names the config problem.

**Background jobs — owner-pinning policy:** cron-driven synchronizations run without a user session. OpenConnector then **pins the acting user to the credential's `owner`** — read from the OpenRegister credential metadata object **at call time**, never cached, guessed, or configured — and passes it via the broker's acting-user parameter (requires an OpenRegister version that supports it; older brokers make sessionless brokered calls fail with an explanatory 409). The acting user substitutes **only** the session identity: the broker still enforces `allowedApps`, allow-rules, and host-lock against it. Owner-pinning is the deliberate default because it is deterministic and auditable — a background sync always acts as exactly the human who owns the secret.

**Sessionless failure modes** (each fails **closed** — a background call never proceeds as no-one or as an administrator):

| Situation | CallLog | Message theme |
|-----------|---------|---------------|
| Broker too old (no acting-user parameter) | 409 | Upgrade OpenRegister, or run from a user session |
| Credential has no `owner` recorded | 409 | Corrupt credential — re-assign the owner |
| Owner account no longer exists (deleted) | 409 | Cannot act as a deleted user — re-assign or remove the source |
| Owner account is disabled | 409 | Cannot act as a disabled user — re-enable or re-assign |
| Secret not yet migrated to Doriath | 403 | Sign in once as the owner to trigger the one-time vault→Doriath migration, then re-run |

The **un-migrated-secret** case is subtle: OpenRegister's lazy vault→Doriath secret migration only runs in a **user-session** context (the legacy vault is session-scoped). A background (sessionless) read of a secret that has never been read interactively therefore fails closed inside the broker. Because the broker's refusal message is deliberately opaque (secret hygiene — see below), OpenConnector cannot tell this apart from an `allowedApps`/allow-rule refusal, so the **sessionless 403** always carries the additional, actionable migration hint (distinct from the owner-gone / owner-disabled 409s above). The fix is a one-time interactive read: sign in as the owner (or open the credential in OpenRegister), then re-run the sync.

**Secret hygiene & trust boundary:** the secret value never appears in source configuration, sync logs, CallLogs, or error messages — with brokering it never enters the OpenConnector process at all. The OpenRegister broker is the trust boundary: it resolves and injects the secret server-side and returns only `{status, headers, body}`. Its **refusal reason is logged inside OpenRegister and never crosses the boundary** — the exception OpenConnector catches is a single opaque "Request not permitted", which is why the 403 guidance covers the likely fixes for its context rather than naming the exact guard. OpenConnector's own owner-pin refusals are logged with the **guard name + owner uid + credential id only** — never any secret material.

### App-side injection (`credentialRef` placeholder) — for arbitrary / self-hosted hosts {#app-side-injection}

The brokered proxy above is **host-locked**: it only works for a provider in OpenRegister's immutable catalogue (Mollie, KVK, GitHub, …), whose `baseUrl` pins where every call may go. That is deliberately the strongest option — the secret is *zero-knowledge* (it never enters the OpenConnector process). But it cannot cover a Source that points at an **arbitrary or self-hosted host** — a municipality's own API, an on-prem ZGW registry, a customer's staging server — because those hosts can't be host-locked from a file shipped with OpenRegister.

App-side injection closes that gap **without storing the secret in the Source schema**. Instead of the whole call being proxied, **any leaf value under `configuration.authentication` may be a placeholder** of the shape `{ "credentialRef": { … } }`. At call time OpenConnector resolves each placeholder from the vault (through the broker) and substitutes the plaintext **in place**, so every normal auth mechanism — API-key header, HTTP Basic, OAuth token exchange, JWT signing — runs unchanged against a hydrated config.

The vault credential must use one of OpenRegister's **generic, inject-only providers** (they carry no `baseUrl` and no allow-rules, so they can *only* be injected app-side — never proxied):

| Provider | Secret it holds | Non-secret scaffolding you keep in the Source |
|---|---|---|
| `generic-apikey` | the bare API key | the header name / template |
| `generic-bearer` | the bearer token | — |
| `generic-basic` | the **password** | the `username` (not sensitive) |
| `generic-oauth2` | the OAuth2 `client_secret` | `client_id`, token URL, scope |
| `generic-jwt` | the JWT signing secret | claims, algorithm |

Example — an API key injected into a header, with the key held in the vault:

```json
{
  "authentication": {
    "type": "apikey",
    "apikey": { "credentialRef": { "credentialId": "00000000-0000-0000-0000-000000000000" } }
  },
  "headers": {
    "Authorization": "Bearer {{ source.configuration.authentication.apikey }}"
  }
}
```

At call time `authentication.apikey` becomes the vault secret, so the `Authorization` header renders with the real key. Only the **secret leaf** is a placeholder; the non-secret scaffolding (`type`, `client_id`, `username`, `tokenUrl`, header name, …) stays in the config as plain values.

**How it differs from the proxy (top-level `credentialRef`):**

| | Brokered proxy | App-side injection |
|---|---|---|
| Config shape | `authentication.credentialRef` (the **whole** auth block) | a `credentialRef` **nested** at a secret's own position |
| Provider | host-locked catalogue entry (Mollie, KVK, …) | a `generic-*` inject-only provider |
| Where the call goes | provider `baseUrl` (host-locked) | the Source's own `location` |
| Secret exposure | zero-knowledge — never enters OpenConnector | resolved into the OpenConnector process, then injected |
| Broker guards | owner → allowedApps → allow-rules → host-lock | owner → allowedApps (no host-lock — there is no fixed host) |
| Use it for | catalogued SaaS | arbitrary / self-hosted hosts |

**Hard rules (each a synthetic 409 config-error CallLog; the request is never sent):**

- The referenced credential **must** use a `generic-*` inject-only provider. Referencing a host-locked *proxy* credential in a placeholder is refused — its secret is zero-knowledge and never leaves OpenRegister (use a top-level `credentialRef` proxy for those instead).
- Requires an OpenRegister version that ships `CredentialBrokerService::resolveInjectable()`; an older broker fails closed with an upgrade hint.
- The broker still enforces credential **owner** and **allowedApps** (add `openconnector` to `allowedApps`). Background jobs use the same owner-pinning policy as the proxy path.

**Operator recipe:**

1. Create a credential in OpenRegister's credential broker with a **`generic-*`** provider matching your auth scheme (e.g. `generic-apikey`), the secret, and `owner`. Note its UUID.
2. Add `openconnector` to the credential's `allowedApps`.
3. In the Source's `configuration.authentication`, replace the secret value with a `{ "credentialRef": { "credentialId": "<uuid>" } }` placeholder, keeping the non-secret scaffolding around it.
4. Test the source: a 2xx/upstream status means injection worked; a 409 CallLog names the config problem (wrong provider kind, missing credential, broker too old).

Because the injected secret ends up exactly where an embedded one would, the same CallLog redaction covers it — the win is that the **Source schema stores only a reference**, never the secret.

### API Key

Set a static value directly in the `headers` or `query` fields of the source:

```json
{
  "headers": {
    "Authorization": "Bearer my-static-api-key"
  }
}
```

### OAuth 2.0

Use a Twig expression in the Authorization header. OpenConnector resolves the token automatically:

```
Bearer {{ oauthToken(source) }}
```

Supported grant types:

| Grant Type | Required Fields |
|------------|----------------|
| `client_credentials` | `grant_type`, `scope`, `tokenUrl`, `authentication`, `client_id`, `client_secret` |
| `password` | `grant_type`, `scope`, `tokenUrl`, `username`, `password` |

Example `authenticationConfig`:

```json
{
  "grant_type": "client_credentials",
  "scope": "api",
  "authentication": "body",
  "tokenUrl": "https://example.com/oauth/token",
  "client_id": "my-client",
  "client_secret": "my-secret"
}
```

### JWT Bearer

Generate a signed JWT automatically:

```
Bearer {{ jwToken(source) }}
```

Required `authenticationConfig` fields: `payload`, `secret`, `algorithm` (e.g. `HS256`, `RS256`, `PS256`).

### ZGW JWT

Dutch government ZGW authentication using the VNG JWT standard. Uses `client_id` and `secret` from `authenticationConfig`, and automatically includes `iss`, `iat`, and `user_id` claims.

### Basic Auth

Set credentials in `authenticationConfig`:

```json
{
  "username": "user",
  "password": "pass"
}
```

The `Authorization: Basic ...` header is generated automatically.

### PKIoverheid mTLS

For connections to Dutch government services requiring client certificate authentication. Configure the certificate path and key in the source's `configuration` field. Used by the StUF adapter and Digikoppeling-compliant integrations.

## Source Configuration Fields

| Field | Description |
|-------|-------------|
| `name` | Human-readable identifier |
| `slug` | URL-friendly unique identifier |
| `location` | Base URL of the external system |
| `type` | Protocol type (`json`, `xml`, `soap`, `ftp`) |
| `auth` | Authentication method identifier |
| `authorizationHeader` | Header name for the auth token (default: `Authorization`) |
| `headers` | Default headers added to every request |
| `query` | Default query parameters added to every request |
| `configuration` | Auth-specific configuration (OAuth params, cert paths) |
| `authenticationConfig` | Dynamic auth parameters (resolved by Twig) |
| `timeout` | HTTP request timeout in seconds |
| `verify` | TLS certificate verification (boolean) |
| `isEnabled` | Whether the source is active |
| `logging` | Whether to log all calls to this source |

## Call Logging

When `logging` is enabled on a source, every HTTP request and response is stored in a `CallLog` entry. Logs include:

- Request method, URL, headers, and body
- Response status code, headers, and body
- Execution duration
- Associated synchronization or job reference

Logs are accessible via the Logs section in the OpenConnector UI and the `/api/logs` endpoint.

## Rate Limit Handling

OpenConnector detects rate limiting responses (HTTP 429, `Retry-After` headers, and common rate limit headers). When detected, the service throws a `TooManyRequestsHttpException` which causes the calling synchronization or job to back off and reschedule.

## Implementation

- `lib/Service/CallService.php` — HTTP execution, template rendering, error handling
- `lib/Service/AuthenticationService.php` — OAuth token fetching, JWT generation, ZGW JWT
- `lib/Controller/SourcesController.php` — REST CRUD API
- `lib/Db/Source.php` — Entity
- `lib/Db/SourceMapper.php` — Database mapper
