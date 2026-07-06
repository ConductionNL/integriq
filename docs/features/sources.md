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

**Background jobs:** cron-driven synchronizations run without a user session. OpenConnector then passes the credential's **owner** as acting user via the broker's acting-user parameter (requires an OpenRegister version that supports it; older brokers make sessionless brokered calls fail with an explanatory 409). The broker still enforces `allowedApps`, allow-rules, and host-lock.

**Secret hygiene:** the secret value never appears in source configuration, sync logs, CallLogs, or error messages — with brokering it never enters the OpenConnector process at all. Broker refusals are logged as refusals (guard hint), never with the request payload.

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
